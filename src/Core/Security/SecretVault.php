<?php
/**
 * Centralized, Hub-scoped encrypted Secret Vault for MDS Release Manager and companion plugins.
 *
 * Implements a zero-dependency cryptographic vault preferring PHP 8.0+ libsodium
 * XChaCha20-Poly1305 AEAD (with AAD context binding), falling back to OpenSSL
 * AES-256-GCM, and finally base64 obfuscation ONLY when neither crypto extension
 * is installed (never refusing to save secrets, while surfacing explicit security
 * posture warnings and hardening instructions to administrators).
 *
 * @package MDS\ReleaseManager
 * @since   2.2.0
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Core\Security;

use MDS\ReleaseManager\Config;
use MDS\ReleaseManager\Core\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves, encrypts, rotates, and audits secrets across the MDS plugin ecosystem.
 *
 * On WordPress Multisite, all vault reads and writes execute against the Hub site
 * so credentials are managed in one place without per-spoke duplication.
 *
 * @since 2.2.0
 */
final class SecretVault {

	/**
	 * Envelope version prefix for v2 vault payloads.
	 *
	 * Format: `mds_v2:<kid>:<cipher>:<base64>`
	 *
	 * @var string
	 */
	private const ENVELOPE_PREFIX = 'mds_v2:';

	/**
	 * Maximum number of metadata-only audit events retained.
	 *
	 * @var int
	 */
	private const MAX_AUDIT_ENTRIES = 100;

	/**
	 * Resolves a plaintext secret through the full cascade.
	 *
	 * Cascade order:
	 * 1. `mds_rm_resolve_secret` enterprise filter (KMS / Vault / Infisical drop-in)
	 * 2. WordPress Core Secrets API (`wp_get_secret()` when available)
	 * 3. Process environment variables (`getenv()` / `$_ENV`)
	 * 4. `wp-config.php` PHP constants
	 * 5. Hub Encrypted Vault (`mds_rm_secret_vault` on the Hub site)
	 *
	 * @param string               $service Canonical service namespace (e.g. `github`, `spotify`).
	 * @param string               $field   Field key (e.g. `token`, `client_id`, `client_secret`).
	 * @param array<string, mixed> $options Optional `env` (string[]) and `const` (string[]) override names.
	 * @return string Plaintext secret, or empty string if unset.
	 */
	public static function get( string $service, string $field, array $options = array() ): string {
		$resolved = self::get_with_meta( $service, $field, $options );
		return $resolved['value'];
	}

	/**
	 * Resolves a secret along with its source metadata and cipher strength.
	 *
	 * @param string               $service Canonical service namespace.
	 * @param string               $field   Field key.
	 * @param array<string, mixed> $options Optional `env` (string[]) and `const` (string[]) override names.
	 * @return array{value: string, source: string, cipher: string, key_id: string, needs_rotation: bool}
	 */
	public static function get_with_meta( string $service, string $field, array $options = array() ): array {
		$ns  = sanitize_key( $service );
		$key = sanitize_key( $field );

		if ( '' === $ns || '' === $key ) {
			return self::empty_result();
		}

		// 1. Enterprise KMS / Vault drop-in filter.
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Allows external secrets managers (AWS Secrets Manager, HashiCorp Vault, Infisical, etc.)
			 * to resolve a secret before local environment, constant, or database lookups.
			 *
			 * @since 2.2.0
			 *
			 * @param string|null          $resolved  Pre-resolved secret string, or null to continue cascade.
			 * @param string               $namespace Canonical service namespace.
			 * @param string               $field     Field key.
			 * @param array<string, mixed> $options   Lookup options.
			 */
			$filtered = apply_filters( 'mds_rm_resolve_secret', null, $ns, $key, $options );
			if ( is_string( $filtered ) && '' !== trim( $filtered ) ) {
				return array(
					'value'          => trim( $filtered ),
					'source'         => 'filter',
					'cipher'         => 'external',
					'key_id'         => 'external',
					'needs_rotation' => false,
				);
			}
		}

		// 2. WordPress Core Secrets API (when available in WP 7.x+).
		if ( function_exists( 'wp_get_secret' ) ) {
			$wp_secret_name = 'mds_' . $ns . '_' . $key;
			$wp_secret      = wp_get_secret( $wp_secret_name );
			if ( is_string( $wp_secret ) && '' !== trim( $wp_secret ) ) {
				return array(
					'value'          => trim( $wp_secret ),
					'source'         => 'wp_secrets',
					'cipher'         => 'wp_core',
					'key_id'         => 'wp_core',
					'needs_rotation' => false,
				);
			}
		}

		$canonical_env = 'MDS_SECRET_' . strtoupper( $ns ) . '_' . strtoupper( $key );

		// 3. Process environment variables (`getenv` / `$_ENV`).
		$env_names = self::normalize_override_list( $options['env'] ?? array(), $canonical_env );
		foreach ( $env_names as $env_name ) {
			$env_val = self::read_env( $env_name );
			if ( '' !== $env_val ) {
				return array(
					'value'          => $env_val,
					'source'         => 'env',
					'cipher'         => 'external',
					'key_id'         => 'env',
					'needs_rotation' => false,
				);
			}
		}

		// 4. `wp-config.php` PHP constants.
		$const_names = self::normalize_override_list( $options['const'] ?? array(), $canonical_env );
		foreach ( $const_names as $const_name ) {
			if ( defined( $const_name ) ) {
				$const_val = trim( (string) constant( $const_name ) );
				if ( '' !== $const_val ) {
					return array(
						'value'          => $const_val,
						'source'         => 'constant',
						'cipher'         => 'external',
						'key_id'         => 'constant',
						'needs_rotation' => false,
					);
				}
			}
		}

		// 5. Hub Encrypted Vault (`mds_rm_secret_vault` on Hub site).
		$vault = self::read_hub_vault();
		if ( ! isset( $vault[ $ns ] ) || ! is_array( $vault[ $ns ] ) || ! isset( $vault[ $ns ][ $key ] ) || ! is_string( $vault[ $ns ][ $key ] ) ) {
			return self::empty_result();
		}

		$envelope = $vault[ $ns ][ $key ];
		$aad      = self::build_aad( $ns, $key );
		$opened   = self::decrypt_envelope( $envelope, $aad );
		if ( null === $opened ) {
			return self::empty_result();
		}

		$active_kid    = self::active_key_id();
		$active_cipher = self::preferred_cipher();
		$needs_rotate  = ( $opened['key_id'] !== $active_kid || $opened['cipher'] !== $active_cipher );

		// Lazy write-on-read re-encryption when a stronger cipher or rotated key is active.
		if ( $needs_rotate && '' !== $opened['plaintext'] ) {
			$reencrypted = self::encrypt_envelope( $opened['plaintext'], $aad );
			if ( '' !== $reencrypted ) {
				$vault[ $ns ][ $key ] = $reencrypted;
				self::write_hub_vault( $vault );
				self::record_audit( 'auto_rotated', $ns, $key );
				$needs_rotate = false;
			}
		}

		return array(
			'value'          => $opened['plaintext'],
			'source'         => 'vault',
			'cipher'         => $opened['cipher'],
			'key_id'         => $opened['key_id'],
			'needs_rotation' => $needs_rotate,
		);
	}

	/**
	 * Encrypts and stores a secret in the Hub vault.
	 *
	 * Never refuses saving even when neither `libsodium` nor `OpenSSL` is installed;
	 * instead falls back to minimum-security obfuscation and returns the active security
	 * posture so callers can notify administrators immediately.
	 *
	 * @param string $service   Canonical service namespace.
	 * @param string $field     Field key.
	 * @param string $plaintext Secret value to store (empty string deletes the entry).
	 * @return array{saved: bool, cipher: string, key_id: string, security_level: string}
	 */
	public static function set( string $service, string $field, string $plaintext ): array {
		$ns  = sanitize_key( $service );
		$key = sanitize_key( $field );

		if ( '' === $ns || '' === $key ) {
			return array(
				'saved'          => false,
				'cipher'         => 'none',
				'key_id'         => 'none',
				'security_level' => self::get_security_posture()['level'],
			);
		}

		$clean = trim( $plaintext );
		if ( '' === $clean ) {
			self::delete( $ns, $key );
			return array(
				'saved'          => true,
				'cipher'         => 'none',
				'key_id'         => 'none',
				'security_level' => self::get_security_posture()['level'],
			);
		}

		// Delegate to WordPress Core Secrets API if available and writable.
		if ( function_exists( 'wp_set_secret' ) ) {
			$wp_secret_name = 'mds_' . $ns . '_' . $key;
			if ( wp_set_secret( $wp_secret_name, $clean ) ) {
				self::record_audit( 'updated', $ns, $key );
				return array(
					'saved'          => true,
					'cipher'         => 'wp_core',
					'key_id'         => 'wp_core',
					'security_level' => 'optimal',
				);
			}
		}

		$aad      = self::build_aad( $ns, $key );
		$envelope = self::encrypt_envelope( $clean, $aad );

		$vault = self::read_hub_vault();
		if ( ! isset( $vault[ $ns ] ) || ! is_array( $vault[ $ns ] ) ) {
			$vault[ $ns ] = array();
		}
		$vault[ $ns ][ $key ] = $envelope;
		$saved                = self::write_hub_vault( $vault );

		if ( $saved ) {
			self::record_audit( 'updated', $ns, $key );
		}

		$posture = self::get_security_posture();

		return array(
			'saved'          => $saved,
			'cipher'         => self::preferred_cipher(),
			'key_id'         => self::active_key_id(),
			'security_level' => $posture['level'],
		);
	}

	/**
	 * Deletes a single secret from the Hub vault.
	 *
	 * @param string $service Canonical service namespace.
	 * @param string $field   Field key.
	 * @return bool True when deleted or already absent.
	 */
	public static function delete( string $service, string $field ): bool {
		$ns  = sanitize_key( $service );
		$key = sanitize_key( $field );

		if ( '' === $ns || '' === $key ) {
			return false;
		}

		if ( function_exists( 'wp_delete_secret' ) ) {
			wp_delete_secret( 'mds_' . $ns . '_' . $key );
		}

		$vault = self::read_hub_vault();
		if ( isset( $vault[ $ns ] ) && is_array( $vault[ $ns ] ) && array_key_exists( $key, $vault[ $ns ] ) ) {
			unset( $vault[ $ns ][ $key ] );
			if ( empty( $vault[ $ns ] ) ) {
				unset( $vault[ $ns ] );
			}
			self::write_hub_vault( $vault );
			self::record_audit( 'deleted', $ns, $key );
		}

		return true;
	}

	/**
	 * Returns safe, masked status metadata for a single secret (never exposes plaintext).
	 *
	 * @param string               $service Canonical service namespace.
	 * @param string               $field   Field key.
	 * @param array<string, mixed> $options Optional `env`, `const`, and `is_secret` (bool, default true).
	 * @return array{configured: bool, source: string, masked: string, cipher: string, key_id: string, is_override: bool}
	 */
	public static function get_status( string $service, string $field, array $options = array() ): array {
		$resolved    = self::get_with_meta( $service, $field, $options );
		$is_secret   = ! array_key_exists( 'is_secret', $options ) || (bool) $options['is_secret'];
		$configured  = '' !== $resolved['value'];
		$is_override = in_array( $resolved['source'], array( 'filter', 'wp_secrets', 'env', 'constant' ), true );

		return array(
			'configured'  => $configured,
			'source'      => $resolved['source'],
			'masked'      => $is_secret ? self::mask( $resolved['value'] ) : $resolved['value'],
			'cipher'      => $resolved['cipher'],
			'key_id'      => $resolved['key_id'],
			'is_override' => $is_override,
		);
	}

	/**
	 * Re-encrypts all secrets stored in the Hub vault using the current preferred cipher and active key.
	 *
	 * @return array{rotated: int, failed: int, cipher: string, key_id: string}
	 */
	public static function rotate_all(): array {
		$vault   = self::read_hub_vault();
		$rotated = 0;
		$failed  = 0;

		foreach ( $vault as $ns => $fields ) {
			if ( ! is_string( $ns ) || ! is_array( $fields ) ) {
				continue;
			}
			foreach ( $fields as $field => $envelope ) {
				if ( ! is_string( $field ) || ! is_string( $envelope ) ) {
					continue;
				}
				$aad    = self::build_aad( $ns, $field );
				$opened = self::decrypt_envelope( $envelope, $aad );
				if ( null === $opened ) {
					++$failed;
					continue;
				}
				$vault[ $ns ][ $field ] = self::encrypt_envelope( $opened['plaintext'], $aad );
				++$rotated;
			}
		}

		if ( $rotated > 0 ) {
			self::write_hub_vault( $vault );
			self::record_audit( 'rotated_all', 'vault', (string) $rotated );
		}

		return array(
			'rotated' => $rotated,
			'failed'  => $failed,
			'cipher'  => self::preferred_cipher(),
			'key_id'  => self::active_key_id(),
		);
	}

	/**
	 * Evaluates the host's cryptographic security posture and returns actionable guidance.
	 *
	 * @return array<string, mixed> Posture descriptor (`level`, `cipher`, `key_source`, `warnings`, `instructions`, etc.).
	 */
	public static function get_security_posture(): array {
		$has_sodium     = self::has_sodium_aead();
		$has_openssl    = self::has_openssl_gcm();
		$has_wp_secrets = function_exists( 'wp_get_secret' );
		$dedicated_key  = self::read_dedicated_master_key();
		$has_custom_key = '' !== $dedicated_key;
		$cipher         = self::preferred_cipher();
		$key_source     = $has_custom_key ? 'MDS_RM_SECRETS_KEY' : 'wp_salt';
		$environment    = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';

		$warnings     = array();
		$instructions = array();

		if ( ! $has_sodium && ! $has_openssl && ! $has_wp_secrets ) {
			$level          = 'minimum';
			$warnings[]     = __( 'Secrets are currently stored with minimum security (base64 obfuscation) because neither PHP libsodium nor OpenSSL AES-256-GCM is enabled on this server.', 'mds-release-manager' );
			$instructions[] = __( 'Enable the PHP sodium extension (recommended) or OpenSSL extension in your hosting control panel or php.ini.', 'mds-release-manager' );
			$instructions[] = __( 'Define MDS_RM_SECRETS_KEY in wp-config.php (use the Generate Key button below) and click Re-encrypt All Secrets once encryption is enabled.', 'mds-release-manager' );
			$instructions[] = __( 'For zero database storage, supply credentials via server environment variables (ENV) or wp-config.php constants.', 'mds-release-manager' );
		} elseif ( ! $has_custom_key && ! $has_wp_secrets ) {
			$level          = 'good';
			$warnings[]     = __( 'Secrets are encrypted at rest using WordPress auth salts. Defining a dedicated MDS_RM_SECRETS_KEY in wp-config.php isolates vault encryption from salt resets and enables zero-downtime key rotation.', 'mds-release-manager' );
			$instructions[] = __( 'Click Generate MDS_RM_SECRETS_KEY below and add the define() line to wp-config.php above "That\'s all, stop editing!".', 'mds-release-manager' );
			$instructions[] = __( 'Click Re-encrypt All Secrets to upgrade existing vault entries to your dedicated key.', 'mds-release-manager' );
		} else {
			$level = 'optimal';
		}

		// Also check if any stored entry in the vault is still using `obf` (minimum security).
		$has_minimum_entries = false;
		foreach ( self::read_hub_vault() as $fields ) {
			if ( ! is_array( $fields ) ) {
				continue;
			}
			foreach ( $fields as $envelope ) {
				if ( is_string( $envelope ) && str_contains( $envelope, ':obf:' ) ) {
					$has_minimum_entries = true;
					break 2;
				}
			}
		}

		if ( $has_minimum_entries && 'minimum' !== $level ) {
			$warnings[]     = __( 'One or more stored secrets were saved under minimum security before encryption was available. Click Re-encrypt All Secrets to upgrade them now.', 'mds-release-manager' );
			$instructions[] = __( 'Click Re-encrypt All Secrets below to upgrade legacy minimum-security entries to authenticated encryption.', 'mds-release-manager' );
		}

		return array(
			'level'               => $level,
			'cipher'              => $cipher,
			'key_source'          => $key_source,
			'has_sodium'          => $has_sodium,
			'has_openssl'         => $has_openssl,
			'has_dedicated_key'   => $has_custom_key,
			'has_wp_secrets'      => $has_wp_secrets,
			'has_minimum_entries' => $has_minimum_entries,
			'environment'         => $environment,
			'warnings'            => $warnings,
			'instructions'        => $instructions,
		);
	}

	/**
	 * Generates a cryptographically secure 32-byte base64 master key suitable for `MDS_RM_SECRETS_KEY`.
	 *
	 * @return string Key formatted as `base64:<44-char-string>`.
	 */
	public static function generate_master_key(): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding binary 256-bit random master key.
		return 'base64:' . base64_encode( random_bytes( 32 ) );
	}

	/**
	 * Returns the bounded metadata-only audit log from the Hub site (newest first).
	 *
	 * @return array<int, array{timestamp: int, date: string, user_id: int, action: string, namespace: string, field: string}>
	 */
	public static function get_audit_log(): array {
		$raw = self::run_on_hub(
			static fn() => function_exists( 'get_option' ) ? get_option( Config::OPTION_SECRET_AUDIT, array() ) : array()
		);
		if ( ! is_array( $raw ) ) {
			return array();
		}
		return array_reverse( $raw );
	}

	/**
	 * Masks a secret string for safe display in admin UIs and status endpoints.
	 *
	 * @param string $value Raw value.
	 * @return string Masked string.
	 */
	public static function mask( string $value ): string {
		$len = strlen( $value );
		if ( 0 === $len ) {
			return '';
		}
		if ( $len <= 6 ) {
			return str_repeat( '•', $len );
		}
		return substr( $value, 0, 3 ) . str_repeat( '•', min( 12, $len - 6 ) ) . substr( $value, -3 );
	}

	/**
	 * Encrypts plaintext into a versioned AEAD envelope bound to `$aad`.
	 *
	 * @param string $plaintext Plaintext secret.
	 * @param string $aad       Additional authenticated data (`namespace|field|hub|blog_id`).
	 * @return string Versioned envelope string.
	 */
	public static function encrypt_envelope( string $plaintext, string $aad ): string {
		$kid = self::active_key_id();
		$key = self::derive_key( $kid );

		if ( self::has_sodium_aead() && '' !== $key ) {
			$nonce = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
			$ct    = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $plaintext, $aad, $nonce, $key );
			if ( function_exists( 'sodium_memzero' ) ) {
				sodium_memzero( $key );
			}
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding binary XChaCha20-Poly1305 ciphertext.
			return self::ENVELOPE_PREFIX . $kid . ':xchacha:' . base64_encode( $nonce . $ct );
		}

		if ( self::has_openssl_gcm() && '' !== $key ) {
			$iv  = random_bytes( 12 );
			$tag = '';
			$ct  = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16 );
			if ( is_string( $ct ) && 16 === strlen( $tag ) ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding binary AES-256-GCM ciphertext.
				return self::ENVELOPE_PREFIX . $kid . ':aesgcm:' . base64_encode( $iv . $tag . $ct );
			}
		}

		// Minimum-security fallback when neither libsodium nor OpenSSL is available (never refuses saving).
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Fallback obfuscation when host lacks sodium and openssl.
		return self::ENVELOPE_PREFIX . '0:obf:' . base64_encode( $plaintext );
	}

	/**
	 * Decrypts a versioned vault envelope, verifying AAD and supporting key-ring fallback.
	 *
	 * @param string $envelope Stored envelope string.
	 * @param string $aad      Expected Additional Authenticated Data.
	 * @return array{plaintext: string, cipher: string, key_id: string}|null Decrypted payload or null on auth failure.
	 */
	public static function decrypt_envelope( string $envelope, string $aad ): ?array {
		if ( ! str_starts_with( $envelope, self::ENVELOPE_PREFIX ) ) {
			return null;
		}

		$parts = explode( ':', substr( $envelope, strlen( self::ENVELOPE_PREFIX ) ), 3 );
		if ( 3 !== count( $parts ) ) {
			return null;
		}

		list( $kid, $cipher, $b64 ) = $parts;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding stored vault envelope payload.
		$raw = base64_decode( $b64, true );
		if ( ! is_string( $raw ) ) {
			return null;
		}

		if ( 'obf' === $cipher ) {
			return array(
				'plaintext' => $raw,
				'cipher'    => 'obf',
				'key_id'    => '0',
			);
		}

		// Build ordered candidate key IDs to support seamless key rotation (`custom` -> `old` -> `salt`).
		$candidate_kids = array_values( array_unique( array( $kid, self::active_key_id(), 'old', 'salt' ) ) );

		if ( 'xchacha' === $cipher && self::has_sodium_aead() ) {
			$nonce_len = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
			if ( strlen( $raw ) <= $nonce_len ) {
				return null;
			}
			$nonce = substr( $raw, 0, $nonce_len );
			$ct    = substr( $raw, $nonce_len );

			foreach ( $candidate_kids as $try_kid ) {
				$key = self::derive_key( $try_kid );
				if ( '' === $key ) {
					continue;
				}
				try {
					$plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( $ct, $aad, $nonce, $key );
				} catch ( \Throwable $e ) {
					$plain = false;
				}
				if ( function_exists( 'sodium_memzero' ) ) {
					sodium_memzero( $key );
				}
				if ( is_string( $plain ) ) {
					return array(
						'plaintext' => $plain,
						'cipher'    => 'xchacha',
						'key_id'    => $try_kid,
					);
				}
			}
			return null;
		}

		if ( 'aesgcm' === $cipher && self::has_openssl_gcm() ) {
			if ( strlen( $raw ) <= 28 ) {
				return null;
			}
			$iv  = substr( $raw, 0, 12 );
			$tag = substr( $raw, 12, 16 );
			$ct  = substr( $raw, 28 );

			foreach ( $candidate_kids as $try_kid ) {
				$key = self::derive_key( $try_kid );
				if ( '' === $key ) {
					continue;
				}
				$plain = openssl_decrypt( $ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad );
				if ( is_string( $plain ) ) {
					return array(
						'plaintext' => $plain,
						'cipher'    => 'aesgcm',
						'key_id'    => $try_kid,
					);
				}
			}
			return null;
		}

		return null;
	}

	/**
	 * Executes a callback on the Hub site (safely restoring blog context on Multisite).
	 *
	 * @template T
	 * @param callable(): T $callback Callback to execute on the Hub site.
	 * @return T Callback return value.
	 */
	private static function run_on_hub( callable $callback ) {
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() || ! function_exists( 'switch_to_blog' ) ) {
			return $callback();
		}

		$hub_id     = class_exists( Sync::class ) ? Sync::hub_id() : 1;
		$current_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : $hub_id;

		if ( $hub_id <= 0 || $hub_id === $current_id ) {
			return $callback();
		}

		switch_to_blog( $hub_id );
		try {
			return $callback();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Reads the Hub vault option array.
	 *
	 * @return array<string, mixed>
	 */
	private static function read_hub_vault(): array {
		$stored = self::run_on_hub(
			static fn() => function_exists( 'get_option' ) ? get_option( Config::OPTION_SECRET_VAULT, array() ) : array()
		);
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Persists the Hub vault option array (non-autoloaded).
	 *
	 * @param array<string, mixed> $vault Vault map.
	 * @return bool True on write.
	 */
	private static function write_hub_vault( array $vault ): bool {
		return (bool) self::run_on_hub(
			static fn(): bool => function_exists( 'update_option' ) ? (bool) update_option( Config::OPTION_SECRET_VAULT, $vault, false ) : false
		);
	}

	/**
	 * Appends a metadata-only event to the Hub audit ring buffer.
	 *
	 * @param string $action  Action label (`updated`, `deleted`, `auto_rotated`, `rotated_all`).
	 * @param string $service Service namespace.
	 * @param string $field   Field key.
	 * @return void
	 */
	private static function record_audit( string $action, string $service, string $field ): void {
		self::run_on_hub(
			static function () use ( $action, $service, $field ): void {
				if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
					return;
				}
				$log = get_option( Config::OPTION_SECRET_AUDIT, array() );
				if ( ! is_array( $log ) ) {
					$log = array();
				}
				$now   = time();
				$log[] = array(
					'timestamp' => $now,
					'date'      => function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i:s', $now ) : gmdate( 'Y-m-d H:i:s', $now ),
					'user_id'   => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
					'action'    => sanitize_key( $action ),
					'namespace' => sanitize_key( $service ),
					'field'     => sanitize_key( $field ),
				);
				if ( count( $log ) > self::MAX_AUDIT_ENTRIES ) {
					$log = array_slice( $log, -self::MAX_AUDIT_ENTRIES );
				}
				update_option( Config::OPTION_SECRET_AUDIT, $log, false );
			}
		);
	}

	/**
	 * Builds the AEAD Additional Authenticated Data (AAD) binding ciphertext to its slot and Hub blog.
	 *
	 * @param string $service Sanitized namespace.
	 * @param string $field   Sanitized field key.
	 * @return string AAD string.
	 */
	private static function build_aad( string $service, string $field ): string {
		$hub_id = ( function_exists( 'is_multisite' ) && class_exists( Sync::class ) ) ? Sync::hub_id() : 1;
		return $service . '|' . $field . '|hub|' . $hub_id;
	}

	/**
	 * Determines the active key identifier (`custom` or `salt`).
	 *
	 * @return string Key ID.
	 */
	private static function active_key_id(): string {
		return '' !== self::read_dedicated_master_key() ? 'custom' : 'salt';
	}

	/**
	 * Determines the preferred available cipher (`xchacha`, `aesgcm`, or `obf`).
	 *
	 * @return string Cipher slug.
	 */
	private static function preferred_cipher(): string {
		if ( self::has_sodium_aead() ) {
			return 'xchacha';
		}
		if ( self::has_openssl_gcm() ) {
			return 'aesgcm';
		}
		return 'obf';
	}

	/**
	 * Derives a 32-byte binary encryption key for the requested key ID (`custom`, `old`, or `salt`).
	 *
	 * @param string $kid Key ID.
	 * @return string 32-byte binary key, or empty string if unavailable.
	 */
	private static function derive_key( string $kid ): string {
		$material = '';
		if ( 'custom' === $kid ) {
			$material = self::read_dedicated_master_key();
		} elseif ( 'old' === $kid ) {
			$material = self::read_old_master_key();
		} elseif ( 'salt' === $kid ) {
			$material = self::read_salt_key_material();
		}

		if ( '' === $material ) {
			return '';
		}

		if ( str_starts_with( $material, 'base64:' ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding base64-prefixed master key.
			$decoded = base64_decode( substr( $material, 7 ), true );
			if ( is_string( $decoded ) && strlen( $decoded ) >= 16 ) {
				$material = $decoded;
			}
		}

		return hash_hkdf( 'sha256', $material, 32, 'mds-secret-vault-v2' );
	}

	/**
	 * Reads the dedicated master key from ENV or `wp-config.php` (`MDS_RM_SECRETS_KEY` or `WP_SECRETS_KEY`).
	 *
	 * @return string Key material or empty string.
	 */
	private static function read_dedicated_master_key(): string {
		foreach ( array( 'MDS_RM_SECRETS_KEY', 'WP_SECRETS_KEY' ) as $name ) {
			$env = self::read_env( $name );
			if ( '' !== $env ) {
				return $env;
			}
			if ( defined( $name ) ) {
				$val = trim( (string) constant( $name ) );
				if ( '' !== $val ) {
					return $val;
				}
			}
		}
		return '';
	}

	/**
	 * Reads the previous master key for zero-downtime key rotation (`MDS_RM_SECRETS_KEY_OLD`).
	 *
	 * @return string Key material or empty string.
	 */
	private static function read_old_master_key(): string {
		$env = self::read_env( 'MDS_RM_SECRETS_KEY_OLD' );
		if ( '' !== $env ) {
			return $env;
		}
		if ( defined( 'MDS_RM_SECRETS_KEY_OLD' ) ) {
			return trim( (string) constant( 'MDS_RM_SECRETS_KEY_OLD' ) );
		}
		return '';
	}

	/**
	 * Derives fallback key material from WordPress auth salts.
	 *
	 * @return string Salt material.
	 */
	private static function read_salt_key_material(): string {
		if ( function_exists( 'wp_salt' ) ) {
			return wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' );
		}
		$auth   = defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : 'mds-rm-local-auth';
		$secure = defined( 'SECURE_AUTH_KEY' ) ? (string) SECURE_AUTH_KEY : 'mds-rm-local-secure';
		return $auth . '|' . $secure;
	}

	/**
	 * Checks whether PHP libsodium XChaCha20-Poly1305 AEAD is available.
	 *
	 * @return bool True when available.
	 */
	private static function has_sodium_aead(): bool {
		return function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' )
			&& function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt' )
			&& defined( 'SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES' );
	}

	/**
	 * Checks whether OpenSSL AES-256-GCM is available.
	 *
	 * @return bool True when available.
	 */
	private static function has_openssl_gcm(): bool {
		return function_exists( 'openssl_encrypt' )
			&& function_exists( 'openssl_decrypt' )
			&& function_exists( 'openssl_get_cipher_methods' )
			&& in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true );
	}

	/**
	 * Reads a process environment variable safely.
	 *
	 * @param string $name Environment variable name.
	 * @return string Trimmed value, or empty string.
	 */
	private static function read_env( string $name ): string {
		$val = getenv( $name );
		if ( is_string( $val ) && '' !== trim( $val ) ) {
			return trim( $val );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Process environment secret value must preserve exact bytes.
		$env_raw = isset( $_ENV[ $name ] ) && is_string( $_ENV[ $name ] ) ? $_ENV[ $name ] : '';
		if ( '' !== trim( $env_raw ) ) {
			return trim( $env_raw );
		}
		return '';
	}

	/**
	 * Normalizes an override name list and appends the canonical name.
	 *
	 * @param mixed  $raw       Raw override string or string[].
	 * @param string $canonical Canonical ENV/constant name.
	 * @return string[] Unique non-empty names.
	 */
	private static function normalize_override_list( mixed $raw, string $canonical ): array {
		$list = array();
		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$list[] = trim( $raw );
		} elseif ( is_array( $raw ) ) {
			foreach ( $raw as $item ) {
				if ( is_string( $item ) && '' !== trim( $item ) ) {
					$list[] = trim( $item );
				}
			}
		}
		$list[] = $canonical;
		return array_values( array_unique( $list ) );
	}

	/**
	 * Returns an empty resolution result descriptor.
	 *
	 * @return array{value: string, source: string, cipher: string, key_id: string, needs_rotation: bool}
	 */
	private static function empty_result(): array {
		return array(
			'value'          => '',
			'source'         => 'none',
			'cipher'         => 'none',
			'key_id'         => 'none',
			'needs_rotation' => false,
		);
	}
}

<?php
/**
 * Standalone unit tests for MDS Release Manager's centralized SecretVault & SecretRegistry.
 * Run with PHP: php tests/secret-vault.php
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

$GLOBALS['mds_rm_options'] = array();
$GLOBALS['mds_rm_filters'] = array();

function __( string $text, string $_domain = '' ): string {
	return $text;
}
function sanitize_key( string $key ): string {
	return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
}
function sanitize_text_field( string $str ): string {
	return trim( strip_tags( $str ) );
}
function esc_url_raw( string $url ): string {
	return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
}
function get_option( string $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['mds_rm_options'] ) ? $GLOBALS['mds_rm_options'][ $name ] : $default;
}
function update_option( string $name, $value, $_autoload = null ): bool {
	$GLOBALS['mds_rm_options'][ $name ] = $value;
	return true;
}
function get_current_user_id(): int {
	return 1;
}
function wp_date( string $format, int $timestamp ): string {
	return gmdate( $format, $timestamp );
}
function apply_filters( string $hook, $value, ...$args ) {
	if ( empty( $GLOBALS['mds_rm_filters'][ $hook ] ) ) {
		return $value;
	}
	foreach ( $GLOBALS['mds_rm_filters'][ $hook ] as $cb ) {
		$value = $cb( $value, ...$args );
	}
	return $value;
}

require __DIR__ . '/../vendor/autoload.php';

use MDS\ReleaseManager\Api\ExtensionApi;
use MDS\ReleaseManager\Config;
use MDS\ReleaseManager\Core\Security\SecretRegistry;
use MDS\ReleaseManager\Core\Security\SecretVault;

function assert_sv( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
}

// 1. Encrypt, store, read, and mask via ExtensionApi facade.
$res = ExtensionApi::set_secret( 'spotify', 'client_secret', 'super_secret_spotify_value_12345' );
assert_sv( true === $res['saved'], 'SecretVault::set should persist secret.' );
assert_sv( in_array( $res['cipher'], array( 'xchacha', 'aesgcm' ), true ), 'Active host should use authenticated encryption.' );

$plain = ExtensionApi::get_secret( 'spotify', 'client_secret' );
assert_sv( 'super_secret_spotify_value_12345' === $plain, 'Secret should decrypt cleanly.' );

$status = ExtensionApi::get_secret_status( 'spotify', 'client_secret' );
assert_sv( true === $status['configured'] && 'vault' === $status['source'], 'Status should report configured from vault.' );
assert_sv( str_contains( $status['masked'], '•' ) && ! str_contains( $status['masked'], 'super_secret' ), 'Masked status must hide secret body.' );

// 2. AAD context binding prevents cross-namespace ciphertext replay.
$stored_vault = get_option( Config::OPTION_SECRET_VAULT, array() );
$envelope     = (string) ( $stored_vault['spotify']['client_secret'] ?? '' );
assert_sv( str_starts_with( $envelope, 'mds_v2:' ), 'Stored envelope should use mds_v2 prefix.' );
assert_sv( null === SecretVault::decrypt_envelope( $envelope, 'github|token|hub|1' ), 'Replaying ciphertext under different AAD must fail authentication.' );

// 3. Minimum-security obfuscated entry is readable, flagged in posture, and upgraded by rotate_all().
$stored_vault['legacy_svc']['api_key'] = 'mds_v2:0:obf:' . base64_encode( 'min_sec_token_abc' );
update_option( Config::OPTION_SECRET_VAULT, $stored_vault );
$posture_before = SecretVault::get_security_posture();
assert_sv( true === $posture_before['has_minimum_entries'], 'Posture should detect minimum-security obf entries.' );

$rotated = SecretVault::rotate_all();
assert_sv( $rotated['rotated'] >= 2 && 0 === $rotated['failed'], 'rotate_all should re-encrypt all vault entries cleanly.' );
assert_sv( 'min_sec_token_abc' === SecretVault::get( 'legacy_svc', 'api_key' ), 'Upgraded secret should decrypt to original plaintext.' );
$posture_after = SecretVault::get_security_posture();
assert_sv( false === $posture_after['has_minimum_entries'], 'Posture should clear has_minimum_entries after rotation.' );

// 4. Constant override takes precedence over vault without touching DB.
define( 'MDS_TEST_SPOTIFY_SECRET', 'constant_override_value' );
$override = SecretVault::get_with_meta( 'spotify', 'client_secret', array( 'const' => array( 'MDS_TEST_SPOTIFY_SECRET' ) ) );
assert_sv( 'constant_override_value' === $override['value'] && 'constant' === $override['source'], 'Constant override must take precedence over vault.' );

// 5. Audit log records metadata only (never plaintext).
$audit_json = (string) json_encode( SecretVault::get_audit_log() );
assert_sv( ! str_contains( $audit_json, 'super_secret_spotify_value_12345' ), 'Audit log must never contain plaintext secrets.' );
assert_sv( str_contains( $audit_json, 'rotated_all' ), 'Audit log should record rotation event.' );

// 6. SecretRegistry merges duplicate namespaces across sibling plugins cleanly.
$GLOBALS['mds_rm_filters']['mds_rm_register_secret_schemas'][] = static function ( array $schemas ): array {
	$schemas['spotify'] = array(
		'namespace' => 'spotify',
		'label'     => 'Spotify',
		'plugin'    => 'MDS Audio Providers',
		'fields'    => array(
			'client_id'     => array( 'label' => 'Client ID', 'const' => array( 'MDS_AP_SPOTIFY_CLIENT_ID' ) ),
			'client_secret' => array( 'label' => 'Client Secret', 'const' => array( 'MDS_AP_SPOTIFY_CLIENT_SECRET' ) ),
		),
	);
	return $schemas;
};
$GLOBALS['mds_rm_filters']['mds_rm_register_secret_schemas'][] = static function ( array $schemas ): array {
	$schemas['spotify_md'] = array(
		'namespace' => 'spotify',
		'label'     => 'Spotify',
		'plugin'    => 'MDS Metadata',
		'fields'    => array(
			'client_id' => array( 'label' => 'Client ID', 'const' => array( 'MDS_MD_SPOTIFY_CLIENT_ID' ) ),
		),
	);
	return $schemas;
};

$schemas = SecretRegistry::get_schemas();
assert_sv( isset( $schemas['github'], $schemas['spotify'] ), 'SecretRegistry should include default github and merged spotify schemas.' );
assert_sv( 2 === count( $schemas['spotify']['plugins'] ), 'Merged spotify schema should list both consuming plugins.' );
assert_sv( 2 === count( $schemas['spotify']['fields']['client_id']['const'] ), 'Merged spotify client_id should combine both constant overrides.' );

// 7. Delete removes entry cleanly.
assert_sv( SecretVault::delete( 'spotify', 'client_secret' ), 'SecretVault::delete should succeed.' );
assert_sv( '' === SecretVault::get( 'spotify', 'client_secret' ), 'Deleted secret should return empty string.' );

echo "MDS Release Manager SecretVault tests passed.\n";

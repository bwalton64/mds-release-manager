<?php
/**
 * Centralized Credentials admin tab, REST API, and Site Health integration for MDS Release Manager.
 *
 * Mounts inside Releases → Settings → Credentials (Hub site) so all MDS plugins
 * share a single encrypted Hub vault, key-rotation tool, and security posture monitor.
 *
 * @package MDS\ReleaseManager
 * @since   2.2.0
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Core\Security;

use MDS\ReleaseManager\Config;
use MDS\ReleaseManager\Core\Sync;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Credentials tab, REST endpoints, admin security banner, and Site Health test.
 *
 * @since 2.2.0
 */
final class CredentialsAdmin {

	/**
	 * Registers hooks for the Credentials settings tab, REST routes, assets, and Site Health.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_filter( 'mds_rm_site_settings_tabs', array( self::class, 'register_settings_tab' ), 20 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'rest_api_init', array( self::class, 'register_rest_routes' ) );
		add_filter( 'site_status_tests', array( self::class, 'register_site_health_test' ) );
		add_action( 'admin_notices', array( self::class, 'render_minimum_security_notice' ) );
	}

	/**
	 * Registers the Credentials tab inside Releases → Settings.
	 *
	 * @param array<string, array<string, mixed>> $tabs Registered extension/core settings tabs.
	 * @return array<string, array<string, mixed>> Updated tabs.
	 */
	public static function register_settings_tab( array $tabs ): array {
		$tabs['credentials'] = array(
			'label'  => __( 'Credentials', 'mds-release-manager' ),
			'render' => array( self::class, 'render_tab' ),
		);
		return $tabs;
	}

	/**
	 * Renders the React root container for Releases → Settings → Credentials.
	 *
	 * @return void
	 */
	public static function render_tab(): void {
		if ( ! current_user_can( Config::CAP_MANAGE ) ) {
			return;
		}
		?>
		<div class="mds-panel mds-rm-credentials-wrap">
			<div id="mds-rm-credentials-admin-root"></div>
		</div>
		<?php
	}

	/**
	 * Enqueues the Credentials React UI on the Release Settings screen.
	 *
	 * @param string $hook_suffix Current admin screen hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( 'release_page_mds-release-settings' !== $hook_suffix ) {
			return;
		}

		$script_rel = 'js/admin-credentials.js';
		if ( ! file_exists( MDS_RM_PATH . $script_rel ) ) {
			return;
		}

		wp_enqueue_script(
			'mds-rm-credentials-admin',
			MDS_RM_URL . $script_rel,
			array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n', 'wp-a11y' ),
			(string) filemtime( MDS_RM_PATH . $script_rel ),
			true
		);
		wp_set_script_translations( 'mds-rm-credentials-admin', 'mds-release-manager' );

		$boot_data = array(
			'restBase' => '/' . Config::REST_NAMESPACE . '/credentials',
			'initial'  => self::build_payload(),
		);

		$encoded = wp_json_encode( $boot_data, JSON_INVALID_UTF8_SUBSTITUTE );
		wp_add_inline_script(
			'mds-rm-credentials-admin',
			'window.mdsRmCredentialsAdmin = ' . ( is_string( $encoded ) ? $encoded : '{}' ) . ';',
			'before'
		);
	}

	/**
	 * Registers authenticated REST routes under `mds/v1/credentials`.
	 *
	 * @return void
	 */
	public static function register_rest_routes(): void {
		$admin_check = static fn(): bool => current_user_can( Config::CAP_MANAGE );

		register_rest_route(
			Config::REST_NAMESPACE,
			'/credentials',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( self::class, 'rest_get_credentials' ),
					'permission_callback' => $admin_check,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'rest_save_credentials' ),
					'permission_callback' => $admin_check,
				),
			)
		);

		register_rest_route(
			Config::REST_NAMESPACE,
			'/credentials/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest_test_connection' ),
				'permission_callback' => $admin_check,
				'args'                => array(
					'namespace' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			Config::REST_NAMESPACE,
			'/credentials/rotate',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest_rotate_all' ),
				'permission_callback' => $admin_check,
			)
		);

		register_rest_route(
			Config::REST_NAMESPACE,
			'/credentials/generate-key',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest_generate_key' ),
				'permission_callback' => $admin_check,
			)
		);
	}

	/**
	 * Returns schemas, masked statuses, security posture, and metadata-only audit log.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_get_credentials(): WP_REST_Response {
		return new WP_REST_Response( self::build_payload(), 200 );
	}

	/**
	 * Saves or clears credentials for a single registered namespace in the Hub vault.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_save_credentials( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! Sync::is_hub() ) {
			return new WP_Error(
				'mds_rm_hub_only',
				__( 'Credentials must be managed on the Hub site.', 'mds-release-manager' ),
				array( 'status' => 403 )
			);
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			return new WP_Error( 'mds_rm_invalid_payload', __( 'Invalid credentials payload.', 'mds-release-manager' ), array( 'status' => 400 ) );
		}

		$ns      = isset( $params['namespace'] ) && is_string( $params['namespace'] ) ? sanitize_key( $params['namespace'] ) : '';
		$schemas = SecretRegistry::get_schemas();
		if ( '' === $ns || ! isset( $schemas[ $ns ] ) ) {
			return new WP_Error( 'mds_rm_unknown_namespace', __( 'Unknown credential namespace.', 'mds-release-manager' ), array( 'status' => 404 ) );
		}

		$schema = $schemas[ $ns ];
		$clear  = ! empty( $params['clear'] );
		$fields = isset( $params['fields'] ) && is_array( $params['fields'] ) ? $params['fields'] : array();

		if ( $clear ) {
			foreach ( array_keys( $schema['fields'] ) as $fkey ) {
				SecretVault::delete( $ns, $fkey );
			}
		} else {
			foreach ( $schema['fields'] as $fkey => $fdef ) {
				if ( ! array_key_exists( $fkey, $fields ) || ! is_string( $fields[ $fkey ] ) ) {
					continue;
				}
				$val = trim( $fields[ $fkey ] );
				if ( '' !== $val ) {
					SecretVault::set( $ns, $fkey, $val );
				}
			}
		}

		$payload = self::build_payload();
		$level   = $payload['posture']['level'] ?? 'good';
		$message = $clear
			? __( 'Stored credentials cleared.', 'mds-release-manager' )
			: ( 'minimum' === $level
				? __( 'Credentials saved with minimum security. Enable PHP sodium or OpenSSL to encrypt secrets at rest.', 'mds-release-manager' )
				: __( 'Credentials encrypted and saved to Hub vault.', 'mds-release-manager' ) );

		return new WP_REST_Response(
			array_merge(
				$payload,
				array(
					'saved'   => true,
					'message' => $message,
				)
			),
			200
		);
	}

	/**
	 * Runs a registered schema's live connection test callback.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_test_connection( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$ns      = sanitize_key( (string) $request->get_param( 'namespace' ) );
		$schemas = SecretRegistry::get_schemas();
		if ( '' === $ns || ! isset( $schemas[ $ns ] ) || empty( $schemas[ $ns ]['test_callback'] ) || ! is_callable( $schemas[ $ns ]['test_callback'] ) ) {
			return new WP_Error( 'mds_rm_no_test', __( 'Connection testing is not available for this service.', 'mds-release-manager' ), array( 'status' => 400 ) );
		}

		$result = call_user_func( $schemas[ $ns ]['test_callback'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_array( $result ) ) {
			$result = array(
				'ok'      => (bool) $result,
				'message' => $result ? __( 'Connection succeeded.', 'mds-release-manager' ) : __( 'Connection failed.', 'mds-release-manager' ),
			);
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Re-encrypts all secrets in the Hub vault with the active cipher and master key.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_rotate_all(): WP_REST_Response|WP_Error {
		if ( ! Sync::is_hub() ) {
			return new WP_Error( 'mds_rm_hub_only', __( 'Key rotation must be run on the Hub site.', 'mds-release-manager' ), array( 'status' => 403 ) );
		}

		$result = SecretVault::rotate_all();
		return new WP_REST_Response(
			array_merge(
				self::build_payload(),
				array(
					'rotation' => $result,
					'message'  => sprintf(
						/* translators: 1: number of rotated secrets, 2: active cipher name. */
						__( 'Re-encrypted %1$d secret(s) using %2$s.', 'mds-release-manager' ),
						(int) $result['rotated'],
						(string) $result['cipher']
					),
				)
			),
			200
		);
	}

	/**
	 * Generates a fresh 256-bit `MDS_RM_SECRETS_KEY` constant snippet for `wp-config.php`.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_generate_key(): WP_REST_Response|WP_Error {
		if ( ! Sync::is_hub() ) {
			return new WP_Error( 'mds_rm_hub_only', __( 'Master keys must be generated on the Hub site.', 'mds-release-manager' ), array( 'status' => 403 ) );
		}

		$key = SecretVault::generate_master_key();
		return new WP_REST_Response(
			array(
				'key'     => $key,
				'snippet' => "define( 'MDS_RM_SECRETS_KEY', '" . $key . "' );",
			),
			200
		);
	}

	/**
	 * Displays a prominent warning notice on Release Settings when secrets are stored with minimum security.
	 *
	 * @return void
	 */
	public static function render_minimum_security_notice(): void {
		if ( ! current_user_can( Config::CAP_MANAGE ) || ! Sync::is_hub() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'release_page_mds-release-settings' !== $screen->id ) {
			return;
		}

		$posture = SecretVault::get_security_posture();
		if ( 'minimum' !== $posture['level'] && empty( $posture['has_minimum_entries'] ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>'
			. esc_html__( 'MDS Credentials Security Notice:', 'mds-release-manager' )
			. '</strong> '
			. esc_html__( 'One or more secrets are stored with minimum security (base64 obfuscation). Open Releases → Settings → Credentials for step-by-step instructions to enable PHP libsodium/OpenSSL encryption or wp-config.php / ENV storage.', 'mds-release-manager' )
			. '</p></div>';
	}

	/**
	 * Registers the MDS Secret Vault security posture test in WordPress Site Health.
	 *
	 * @param array<string, mixed> $tests Registered Site Health tests.
	 * @return array<string, mixed> Updated tests.
	 */
	public static function register_site_health_test( array $tests ): array {
		$tests['direct']['mds_rm_secret_vault'] = array(
			'label' => __( 'MDS Centralized Secret Vault', 'mds-release-manager' ),
			'test'  => array( self::class, 'run_site_health_test' ),
		);
		return $tests;
	}

	/**
	 * Evaluates SecretVault cryptographic posture for WordPress Site Health.
	 *
	 * @return array<string, mixed> Site Health result array.
	 */
	public static function run_site_health_test(): array {
		$posture = SecretVault::get_security_posture();
		$level   = (string) $posture['level'];

		$status = 'good';
		$color  = 'green';
		$label  = __( 'MDS Secret Vault is using authenticated encryption with a dedicated master key', 'mds-release-manager' );

		if ( 'minimum' === $level || ! empty( $posture['has_minimum_entries'] ) ) {
			$status = 'critical';
			$color  = 'red';
			$label  = __( 'MDS Secret Vault is storing secrets with minimum security', 'mds-release-manager' );
		} elseif ( 'good' === $level ) {
			$status = 'recommended';
			$color  = 'orange';
			$label  = __( 'MDS Secret Vault is encrypted with WordPress salts (dedicated MDS_RM_SECRETS_KEY recommended)', 'mds-release-manager' );
		}

		$desc = '<p>' . esc_html( implode( ' ', $posture['warnings'] ) ) . '</p>';
		if ( ! empty( $posture['instructions'] ) ) {
			$desc .= '<ul>';
			foreach ( $posture['instructions'] as $step ) {
				$desc .= '<li>' . esc_html( (string) $step ) . '</li>';
			}
			$desc .= '</ul>';
		}

		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Security', 'mds-release-manager' ),
				'color' => $color,
			),
			'description' => $desc,
			'actions'     => sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=mds-release-settings#tab-credentials' ) ),
				esc_html__( 'Open Release Manager → Settings → Credentials', 'mds-release-manager' )
			),
			'test'        => 'mds_rm_secret_vault',
		);
	}

	/**
	 * Builds the full REST/bootstrap payload for the Credentials UI (never includes raw secrets).
	 *
	 * @return array<string, mixed>
	 */
	public static function build_payload(): array {
		$is_hub  = Sync::is_hub();
		$hub_url = admin_url( 'admin.php?page=mds-release-settings#tab-credentials' );
		if ( ! $is_hub && function_exists( 'get_admin_url' ) ) {
			$hub_url = get_admin_url( Sync::hub_id(), 'admin.php?page=mds-release-settings#tab-credentials' );
		}

		return array(
			'is_hub'           => $is_hub,
			'hub_settings_url' => $hub_url,
			'posture'          => SecretVault::get_security_posture(),
			'schemas'          => SecretRegistry::get_admin_descriptors(),
			'audit'            => SecretVault::get_audit_log(),
		);
	}
}

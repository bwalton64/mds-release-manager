<?php
/**
 * Neutral, opt-in Cloud Gateway Bridge for MDS Release Manager companion plugins.
 *
 * Strictly dormant by default: makes zero external HTTP calls and renders zero UI
 * on standalone WordPress.org Free Core installations unless a companion plugin
 * explicitly registers via `ExtensionApi::register_gateway_plugin()`.
 *
 * @package   MDS\ReleaseManager
 * @copyright 2026 Musty Dungeon Studios (Walton International Business Group LLC)
 * @license   GPL-3.0-or-later
 * @since     2.3.0
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Core;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides a shared license activation, Bearer token cache, Update URI updater,
 * and authenticated RPC client for companion plugins connecting to MDS Cloud Gateway.
 *
 * @since 2.3.0
 */
final class GatewayBridge {

	public const OPTION_CONNECTION = 'mds_rm_gateway_connection';
	public const TRANSIENT_TOKEN   = 'mds_rm_gateway_token';
	public const UPDATE_HOST       = 'api.mustydungeon.com';
	public const REST_BASE         = '/wp-json/mds-gw/v1';
	public const GRACE_TTL_SECONDS = 259200; // 72-hour offline grace window on 5xx errors.

	/**
	 * Tier precedence hierarchy.
	 *
	 * @var array<string, int>
	 */
	private const TIER_RANK = array(
		'free'   => 1,
		'pro'    => 2,
		'label'  => 3,
		'agency' => 4,
	);

	/**
	 * Companion plugins registered with GatewayBridge keyed by plugin basename.
	 *
	 * @var array<string, array<string, string>>
	 */
	private static array $plugins = array();

	/**
	 * Whether updater filters have been hooked.
	 *
	 * @var bool
	 */
	private static bool $updater_hooked = false;

	/**
	 * Registers neutral hooks (completely inert until a companion plugin registers).
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_filter( 'mds_rm_site_settings_tabs', array( self::class, 'maybe_register_settings_tab' ), 90 );
		add_action( 'rest_api_init', array( self::class, 'register_rest_routes' ) );
	}

	/**
	 * Registers a companion plugin to share the suite-wide MDS Cloud Gateway license & updater.
	 *
	 * @param array<string, string> $config Companion config (`slug`, `basename`, `version`, optional `name`, `api_url`).
	 * @return bool True on valid registration.
	 */
	public static function register_plugin( array $config ): bool {
		$slug     = sanitize_key( (string) ( $config['slug'] ?? '' ) );
		$basename = trim( (string) ( $config['basename'] ?? '' ) );
		$version  = trim( (string) ( $config['version'] ?? '' ) );

		if ( '' === $slug || '' === $basename || '' === $version ) {
			return false;
		}

		self::$plugins[ $basename ] = array(
			'slug'     => $slug,
			'basename' => $basename,
			'version'  => $version,
			'name'     => trim( (string) ( $config['name'] ?? $slug ) ),
			'api_url'  => trim( (string) ( $config['api_url'] ?? '' ) ),
		);

		if ( ! self::$updater_hooked ) {
			self::$updater_hooked = true;
			add_filter( 'update_plugins_' . self::UPDATE_HOST, array( self::class, 'filter_update_plugins' ), 10, 4 );
			$detected_host = (string) wp_parse_url( self::detect_api_base_url(), PHP_URL_HOST );
			if ( '' !== $detected_host && self::UPDATE_HOST !== $detected_host ) {
				add_filter( 'update_plugins_' . $detected_host, array( self::class, 'filter_update_plugins' ), 10, 4 );
			}
		}

		return true;
	}

	/**
	 * Returns whether at least one companion plugin has registered with GatewayBridge.
	 *
	 * @return bool
	 */
	public static function has_registered_plugins(): bool {
		return ! empty( self::$plugins );
	}

	/**
	 * Returns registered companion plugin metadata.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function get_registered_plugins(): array {
		return self::$plugins;
	}

	/**
	 * Auto-detects the MDS Cloud Gateway base URL from the active site URL.
	 *
	 * @param string $site_url Optional site URL override (defaults to home_url()).
	 * @return string
	 */
	public static function detect_api_base_url( string $site_url = '' ): string {
		if ( defined( 'MDS_CG_API_URL' ) && '' !== trim( (string) MDS_CG_API_URL ) ) {
			return untrailingslashit( trim( (string) MDS_CG_API_URL ) );
		}

		$url  = '' !== $site_url ? $site_url : ( function_exists( 'home_url' ) ? (string) home_url() : '' );
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		if (
			in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ||
			'.local' === substr( $host, -6 ) ||
			'.test' === substr( $host, -5 )
		) {
			return 'http://api.bgw-dev.local';
		}

		if (
			0 === strpos( $host, 'sqa.' ) ||
			0 === strpos( $host, 'staging.' ) ||
			'mustydungeon.com' === $host ||
			( function_exists( 'wp_get_environment_type' ) && 'staging' === wp_get_environment_type() )
		) {
			return 'https://mustydungeon.com';
		}

		return 'https://api.mustydungeon.com';
	}

	/**
	 * Checks whether the active connected license satisfies a minimum tier.
	 *
	 * @param string $min_tier Minimum tier ('free', 'pro', 'label', 'agency').
	 * @return bool
	 */
	public static function has_tier( string $min_tier ): bool {
		$status = self::get_status();
		if ( empty( $status['connected'] ) ) {
			return false;
		}
		$current = strtolower( (string) ( $status['tier'] ?? 'free' ) );
		$needed  = strtolower( trim( $min_tier ) );

		return ( self::TIER_RANK[ $current ] ?? 0 ) >= ( self::TIER_RANK[ $needed ] ?? 99 );
	}

	/**
	 * Returns safe, masked gateway connection status (never exposes raw license key or Bearer token).
	 *
	 * @return array<string, mixed>
	 */
	public static function get_status(): array {
		$conn = self::read_connection();
		return array(
			'connected'          => ! empty( $conn['connected'] ),
			'tier'               => (string) ( $conn['tier'] ?? '' ),
			'key_prefix'         => (string) ( $conn['key_prefix'] ?? '' ),
			'expires_at'         => (string) ( $conn['expires_at'] ?? '' ),
			'api_base_url'       => self::detect_api_base_url(),
			'registered_plugins' => array_values( array_column( self::$plugins, 'slug' ) ),
			'offline_grace'      => ! empty( $conn['offline_grace'] ),
		);
	}

	/**
	 * Activates a suite-wide license key against MDS Cloud Gateway and caches the Bearer token.
	 *
	 * @param string $license_key Plaintext license key.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function activate( string $license_key ) {
		$clean_key = trim( sanitize_text_field( $license_key ) );
		if ( '' === $clean_key ) {
			return new WP_Error( 'mds_rm_empty_key', __( 'Please enter a valid MDS license key.', 'mds-release-manager' ), array( 'status' => 400 ) );
		}

		$first_plugin = ! empty( self::$plugins ) ? reset( self::$plugins ) : array(
			'slug'    => 'mds-release-manager',
			'version' => defined( 'MDS_RM_VERSION' ) ? (string) MDS_RM_VERSION : '1.0.0',
		);

		$endpoint = self::detect_api_base_url() . self::REST_BASE . '/licenses/activate';
		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode(
					array(
						'license_key'    => $clean_key,
						'site_url'       => home_url( '/' ),
						'plugin_slug'    => (string) $first_plugin['slug'],
						'plugin_version' => (string) $first_plugin['version'],
						'is_multisite'   => is_multisite(),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 || ! is_array( $body ) || empty( $body['token'] ) ) {
			$msg = is_array( $body ) && ! empty( $body['message'] ) ? (string) $body['message'] : __( 'License activation failed.', 'mds-release-manager' );
			return new WP_Error( 'mds_rm_activation_failed', $msg, array( 'status' => $code > 0 ? $code : 400 ) );
		}

		$connection = array(
			'connected'     => true,
			'tier'          => sanitize_key( (string) ( $body['tier'] ?? 'free' ) ),
			'key_prefix'    => substr( $clean_key, 0, 12 ),
			'expires_at'    => sanitize_text_field( (string) ( $body['expires_at'] ?? '' ) ),
			'last_sync_gmt' => gmdate( 'c' ),
			'offline_grace' => false,
		);

		self::write_connection( $connection );
		self::write_token( (string) $body['token'], (int) ( $body['expires_in'] ?? 86400 ) );

		return self::get_status();
	}

	/**
	 * Deactivates and clears the local gateway connection and cached Bearer token.
	 *
	 * @return array<string, mixed>
	 */
	public static function deactivate(): array {
		self::write_connection( array() );
		self::delete_token();
		return self::get_status();
	}

	/**
	 * Executes an authenticated RPC call to `/wp-json/mds-gw/v1/proxy/{service}`.
	 *
	 * @param string               $service Gateway service slug (e.g. 'ai-enrich', 'metadata-lookup').
	 * @param array<string, mixed> $payload Request JSON body.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function call( string $service, array $payload = array() ) {
		$token = self::read_token();
		if ( '' === $token ) {
			return new WP_Error( 'mds_rm_gateway_disconnected', __( 'MDS Cloud Gateway is not connected.', 'mds-release-manager' ), array( 'status' => 401 ) );
		}

		$endpoint = self::detect_api_base_url() . self::REST_BASE . '/proxy/' . rawurlencode( sanitize_key( $service ) );
		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 12,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $token,
					'X-MDS-Site'    => home_url( '/' ),
				),
				'body'    => (string) wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code >= 500 ) {
			self::mark_offline_grace();
			return new WP_Error( 'mds_rm_gateway_unavailable', __( 'Gateway temporarily unavailable (offline grace active).', 'mds-release-manager' ), array( 'status' => $code ) );
		}

		if ( $code >= 400 || ! is_array( $body ) ) {
			$msg = is_array( $body ) && ! empty( $body['error'] ) ? (string) $body['error'] : __( 'Gateway call failed.', 'mds-release-manager' );
			return new WP_Error( 'mds_rm_gateway_error', $msg, array( 'status' => $code ) );
		}

		return $body;
	}

	/**
	 * Native WordPress `update_plugins_{$hostname}` filter for registered companion plugins.
	 *
	 * @param array<string, mixed>|false $update      Existing update payload.
	 * @param array<string, mixed>       $plugin_data Plugin header data.
	 * @param string                     $plugin_file Plugin basename.
	 * @param string[]                   $locales     Site locales.
	 * @return array<string, mixed>|false
	 */
	public static function filter_update_plugins( $update, array $plugin_data, string $plugin_file, array $locales ) {
		unset( $plugin_data, $locales );

		if ( ! isset( self::$plugins[ $plugin_file ] ) ) {
			return $update;
		}

		$token = self::read_token();
		if ( '' === $token ) {
			return $update;
		}

		$plugin   = self::$plugins[ $plugin_file ];
		$endpoint = add_query_arg(
			array(
				'slug'    => $plugin['slug'],
				'version' => $plugin['version'],
			),
			self::detect_api_base_url() . self::REST_BASE . '/updates/check'
		);

		$response = wp_remote_get(
			$endpoint,
			array(
				'timeout' => 8,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'X-MDS-Site'    => home_url( '/' ),
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return $update;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['version'] ) || empty( $data['package'] ) ) {
			return $update;
		}

		return array(
			'slug'    => $plugin['slug'],
			'version' => (string) $data['version'],
			'url'     => (string) ( $data['url'] ?? 'https://mustydungeon.com' ),
			'package' => (string) $data['package'],
		);
	}

	/**
	 * Conditionally registers the "License & Cloud" tab ONLY when a companion plugin registers.
	 *
	 * @param array<string, mixed> $tabs Existing settings tabs.
	 * @return array<string, mixed>
	 */
	public static function maybe_register_settings_tab( array $tabs ): array {
		if ( ! self::has_registered_plugins() ) {
			return $tabs;
		}

		$tabs['gateway'] = array(
			'label'    => __( 'License & Cloud', 'mds-release-manager' ),
			'callback' => array( self::class, 'render_settings_tab' ),
		);

		return $tabs;
	}

	/**
	 * Renders the neutral License & Cloud settings panel when companion plugins are active.
	 *
	 * @return void
	 */
	public static function render_settings_tab(): void {
		$status = self::get_status();
		?>
		<div class="mds-rm-gateway-tab">
			<h2><?php esc_html_e( 'MDS License & Cloud Gateway', 'mds-release-manager' ); ?></h2>
			<p><?php esc_html_e( 'Enter your single MDS suite license key once to activate updates and managed cloud services across all installed MDS extensions.', 'mds-release-manager' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Gateway Endpoint', 'mds-release-manager' ); ?></th>
					<td><code><?php echo esc_html( (string) $status['api_base_url'] ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'mds-release-manager' ); ?></th>
					<td><?php echo ! empty( $status['connected'] ) ? esc_html( sprintf( 'Connected (%s)', strtoupper( (string) $status['tier'] ) ) ) : esc_html__( 'Not Connected', 'mds-release-manager' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Registered Extensions', 'mds-release-manager' ); ?></th>
					<td><code><?php echo esc_html( implode( ', ', (array) $status['registered_plugins'] ) ); ?></code></td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Registers neutral REST endpoints for gateway status, activation, and deactivation.
	 *
	 * @return void
	 */
	public static function register_rest_routes(): void {
		if ( ! self::has_registered_plugins() ) {
			return;
		}

		$can_manage = static function (): bool {
			return is_multisite() ? current_user_can( 'manage_network_options' ) : current_user_can( 'manage_options' );
		};

		register_rest_route(
			'mds/v1',
			'/gateway/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => static function (): WP_REST_Response {
					return new WP_REST_Response( self::get_status(), 200 );
				},
				'permission_callback' => $can_manage,
			)
		);

		register_rest_route(
			'mds/v1',
			'/gateway/activate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => static function ( WP_REST_Request $request ) {
					$params = $request->get_json_params();
					$key    = is_array( $params ) ? (string) ( $params['license_key'] ?? '' ) : '';
					$result = self::activate( $key );
					return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
				},
				'permission_callback' => $can_manage,
			)
		);

		register_rest_route(
			'mds/v1',
			'/gateway/deactivate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => static function (): WP_REST_Response {
					return new WP_REST_Response( self::deactivate(), 200 );
				},
				'permission_callback' => $can_manage,
			)
		);
	}

	/**
	 * Marks connection metadata as operating in 72-hour offline grace mode.
	 *
	 * @return void
	 */
	private static function mark_offline_grace(): void {
		$conn                  = self::read_connection();
		$conn['offline_grace'] = true;
		self::write_connection( $conn );
	}

	/**
	 * Reads stored gateway connection metadata (Network-level on Multisite, site-level on single-site).
	 *
	 * @return array<string, mixed>
	 */
	private static function read_connection(): array {
		$raw = function_exists( 'is_multisite' ) && is_multisite()
			? get_site_option( self::OPTION_CONNECTION, array() )
			: ( function_exists( 'get_option' ) ? get_option( self::OPTION_CONNECTION, array() ) : array() );

		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Persists gateway connection metadata.
	 *
	 * @param array<string, mixed> $connection Connection state.
	 * @return void
	 */
	private static function write_connection( array $connection ): void {
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			update_site_option( self::OPTION_CONNECTION, $connection );
		} elseif ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_CONNECTION, $connection, false );
		}
	}

	/**
	 * Reads cached short-lived Bearer token.
	 *
	 * @return string
	 */
	private static function read_token(): string {
		$token = function_exists( 'is_multisite' ) && is_multisite()
			? get_site_transient( self::TRANSIENT_TOKEN )
			: ( function_exists( 'get_transient' ) ? get_transient( self::TRANSIENT_TOKEN ) : '' );

		return is_string( $token ) ? trim( $token ) : '';
	}

	/**
	 * Persists short-lived Bearer token with offline grace ceiling.
	 *
	 * @param string $token Signed Bearer token.
	 * @param int    $ttl   Token TTL in seconds.
	 * @return void
	 */
	private static function write_token( string $token, int $ttl ): void {
		$store_ttl = max( $ttl, self::GRACE_TTL_SECONDS );
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			set_site_transient( self::TRANSIENT_TOKEN, $token, $store_ttl );
		} elseif ( function_exists( 'set_transient' ) ) {
			set_transient( self::TRANSIENT_TOKEN, $token, $store_ttl );
		}
	}

	/**
	 * Clears cached Bearer token.
	 *
	 * @return void
	 */
	private static function delete_token(): void {
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			delete_site_transient( self::TRANSIENT_TOKEN );
		} elseif ( function_exists( 'delete_transient' ) ) {
			delete_transient( self::TRANSIENT_TOKEN );
		}
	}
}

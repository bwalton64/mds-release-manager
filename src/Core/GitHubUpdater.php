<?php
/**
 * Native WordPress `Update URI` updater for GitHub Releases (private or public repos).
 *
 * Uses WordPress's built-in `update_plugins_github.com`, `plugins_api`, and
 * `http_request_args` hooks with zero third-party runtime dependencies. Supports
 * private repositories via a `wp-config.php` Personal Access Token constant
 * (`MDS_RM_GITHUB_TOKEN`, or a per-plugin constant), automatically switches to
 * unauthenticated public mode when the token is removed, and can be disabled cleanly
 * for WordPress.org submissions by omitting `Update URI`.
 *
 * @package MDS\ReleaseManager
 * @since   2.0.0
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralized GitHub Releases updater shared by Core and companion MDS plugins.
 *
 * @since 2.0.0
 */
final class GitHubUpdater {

	/**
	 * Cache TTL for successful GitHub Release API responses (6 hours).
	 *
	 * @var int
	 */
	private const CACHE_TTL = 21600;

	/**
	 * Backoff TTL when GitHub API returns an error or rate limit (15 minutes).
	 *
	 * @var int
	 */
	private const ERROR_TTL = 900;

	/**
	 * Registered plugins keyed by WordPress plugin basename (`slug/slug.php`).
	 *
	 * @var array<string, array<string, string>>
	 */
	private static array $plugins = array();

	/**
	 * Whether WordPress hooks have been attached.
	 *
	 * @var bool
	 */
	private static bool $hooked = false;

	/**
	 * Registers a plugin for native WordPress updates from GitHub Releases.
	 *
	 * @param array<string, string> $config {
	 *     Updater configuration.
	 *
	 *     @type string $basename       Plugin basename (e.g. `mds-release-manager/mds-release-manager.php`).
	 *     @type string $slug           Plugin directory slug (e.g. `mds-release-manager`).
	 *     @type string $repo           GitHub `owner/repo` (e.g. `bwalton64/mds-release-manager`).
	 *     @type string $version        Currently installed SemVer version.
	 *     @type string $name           Optional human-readable plugin name for the details modal.
	 *     @type string $author         Optional author name for the details modal.
	 *     @type string $homepage       Optional homepage URL.
	 *     @type string $token_constant Optional `wp-config.php` constant name (defaults to `MDS_RM_GITHUB_TOKEN`).
	 *     @type string $requires       Optional minimum WordPress version (defaults to `7.0`).
	 *     @type string $tested         Optional tested-up-to WordPress version (defaults to `7.0`).
	 *     @type string $requires_php   Optional minimum PHP version (defaults to `8.0`).
	 * }
	 * @return bool True when registered, false if required fields are missing.
	 */
	public static function register( array $config ): bool {
		$basename = trim( (string) ( $config['basename'] ?? '' ) );
		$slug     = sanitize_key( (string) ( $config['slug'] ?? '' ) );
		$repo     = trim( (string) ( $config['repo'] ?? '' ), " \t\n\r\0\x0B/" );
		$version  = trim( (string) ( $config['version'] ?? '' ) );

		if ( '' === $basename || '' === $slug || '' === $version || ! preg_match( '/\A[a-z0-9_.-]+\/[a-z0-9_.-]+\z/i', $repo ) ) {
			return false;
		}

		self::$plugins[ $basename ] = array(
			'basename'       => $basename,
			'slug'           => $slug,
			'repo'           => $repo,
			'version'        => $version,
			'name'           => (string) ( $config['name'] ?? $slug ),
			'author'         => (string) ( $config['author'] ?? 'Musty Dungeon Studios' ),
			'homepage'       => (string) ( $config['homepage'] ?? 'https://mustydungeon.com' ),
			'token_constant' => (string) ( $config['token_constant'] ?? 'MDS_RM_GITHUB_TOKEN' ),
			'requires'       => (string) ( $config['requires'] ?? '7.0' ),
			'tested'         => (string) ( $config['tested'] ?? '7.0' ),
			'requires_php'   => (string) ( $config['requires_php'] ?? '8.0' ),
		);

		self::attach_hooks();

		return true;
	}

	/**
	 * Attaches WordPress native Update URI, details modal, download auth, and cache-purge hooks once.
	 *
	 * @return void
	 */
	private static function attach_hooks(): void {
		if ( self::$hooked || ! function_exists( 'add_filter' ) ) {
			return;
		}
		self::$hooked = true;

		add_filter( 'update_plugins_github.com', array( self::class, 'filter_update' ), 10, 4 );
		add_filter( 'plugins_api', array( self::class, 'filter_plugin_info' ), 20, 3 );
		add_filter( 'http_request_args', array( self::class, 'authenticate_download' ), 10, 2 );
		add_action( 'delete_site_transient_update_plugins', array( self::class, 'purge_cache' ) );
	}

	/**
	 * Resets internal registry state (used by unit tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$plugins = array();
		self::$hooked  = false;
	}

	/**
	 * Supplies update metadata to WordPress's native `update_plugins_github.com` hook.
	 *
	 * @param array<string, mixed>|false $update      Existing update payload or false.
	 * @param array<string, mixed>       $plugin_data Plugin header array.
	 * @param string                     $plugin_file Plugin basename.
	 * @param string[]                   $_locales    Installed locales.
	 * @return array<string, mixed>|false Update data when a newer release exists, otherwise false.
	 */
	public static function filter_update( $update, array $plugin_data, string $plugin_file, array $_locales = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress hook signature.
		if ( ! isset( self::$plugins[ $plugin_file ] ) ) {
			return $update;
		}

		$config  = self::$plugins[ $plugin_file ];
		$release = self::get_latest_release( $config );
		if ( ! is_array( $release ) || empty( $release['version'] ) || empty( $release['package'] ) ) {
			return false;
		}

		$current = ! empty( $plugin_data['Version'] ) ? (string) $plugin_data['Version'] : $config['version'];
		if ( ! version_compare( $release['version'], $current, '>' ) ) {
			return false;
		}

		return array(
			'id'           => 'https://github.com/' . $config['repo'],
			'slug'         => $config['slug'],
			'version'      => $release['version'],
			'url'          => ! empty( $release['html_url'] ) ? $release['html_url'] : $config['homepage'],
			'package'      => $release['package'],
			'tested'       => $config['tested'],
			'requires'     => $config['requires'],
			'requires_php' => $config['requires_php'],
			'icons'        => BrandIcon::updater_icons( $config['slug'] ),
		);
	}

	/**
	 * Populates the WP-Admin "View version X.Y.Z details" modal for registered plugins.
	 *
	 * @param false|object|array<string, mixed> $result Existing plugins_api response.
	 * @param string                            $action Requested action (`plugin_information`).
	 * @param object                            $args   Request arguments (`slug`).
	 * @return false|object|array<string, mixed> Plugin details object or original result.
	 */
	public static function filter_plugin_info( $result, string $action, object $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
			return $result;
		}

		$config = self::find_by_slug( (string) $args->slug );
		if ( null === $config ) {
			return $result;
		}

		$release = self::get_latest_release( $config );
		if ( ! is_array( $release ) || empty( $release['version'] ) ) {
			return $result;
		}

		$changelog = ! empty( $release['body'] )
			? '<pre style="white-space:pre-wrap;font-family:inherit;">' . esc_html( (string) $release['body'] ) . '</pre>'
			: '<p>' . esc_html__( 'See the GitHub Release notes for full details.', 'mds-release-manager' ) . '</p>';

		return (object) array(
			'name'          => $config['name'],
			'slug'          => $config['slug'],
			'version'       => $release['version'],
			'author'        => $config['author'],
			'homepage'      => $config['homepage'],
			'requires'      => $config['requires'],
			'tested'        => $config['tested'],
			'requires_php'  => $config['requires_php'],
			'last_updated'  => $release['published_at'] ?? '',
			'download_link' => $release['package'] ?? '',
			'icons'         => BrandIcon::updater_icons( $config['slug'] ),
			'sections'      => array(
				'description' => sprintf(
					/* translators: %s: Plugin name. */
					esc_html__( '%s — managed via GitHub Releases.', 'mds-release-manager' ),
					$config['name']
				),
				'changelog'   => $changelog,
			),
		);
	}

	/**
	 * Injects GitHub Bearer token + octet-stream header ONLY for registered private release asset downloads.
	 *
	 * @param array<string, mixed> $args HTTP request arguments.
	 * @param string               $url  Destination URL.
	 * @return array<string, mixed> Possibly authenticated request arguments.
	 */
	public static function authenticate_download( array $args, string $url ): array {
		if ( '' === $url || ! str_starts_with( $url, 'https://api.github.com/repos/' ) ) {
			return $args;
		}

		foreach ( self::$plugins as $config ) {
			$prefix = 'https://api.github.com/repos/' . $config['repo'] . '/releases/assets/';
			if ( str_starts_with( $url, $prefix ) ) {
				$token = self::resolve_token( $config );
				if ( '' !== $token ) {
					$headers                  = is_array( $args['headers'] ?? null ) ? $args['headers'] : array();
					$headers['Authorization'] = 'Bearer ' . $token;
					$headers['Accept']        = 'application/octet-stream';
					$headers['User-Agent']    = 'MDS-Release-Manager/' . ( defined( 'MDS_RM_VERSION' ) ? MDS_RM_VERSION : '2.0.0' );
					$args['headers']          = $headers;
				}
				break;
			}
		}

		return $args;
	}

	/**
	 * Clears cached GitHub Release transients when WordPress forces an update check.
	 *
	 * @return void
	 */
	public static function purge_cache(): void {
		if ( ! function_exists( 'delete_site_transient' ) ) {
			return;
		}
		foreach ( self::$plugins as $config ) {
			delete_site_transient( self::transient_key( $config['slug'] ) );
		}
	}

	/**
	 * Fetches (or returns cached) latest release info from GitHub Releases API.
	 *
	 * @param array<string, string> $config Registered plugin configuration.
	 * @return array<string, string>|null Parsed release array or null on failure.
	 */
	public static function get_latest_release( array $config ): ?array {
		$key = self::transient_key( $config['slug'] );
		if ( function_exists( 'get_site_transient' ) ) {
			$cached = get_site_transient( $key );
			if ( is_array( $cached ) ) {
				return ! empty( $cached['_error'] ) ? null : $cached;
			}
		}

		if ( ! function_exists( 'wp_remote_get' ) ) {
			return null;
		}

		$token   = self::resolve_token( $config );
		$headers = array(
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'           => 'MDS-Release-Manager/' . ( defined( 'MDS_RM_VERSION' ) ? MDS_RM_VERSION : '2.0.0' ),
		);
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$api_url  = 'https://api.github.com/repos/' . $config['repo'] . '/releases/latest';
		$response = wp_remote_get(
			$api_url,
			array(
				'timeout' => 10,
				'headers' => $headers,
			)
		);

		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( is_wp_error( $response ) || 200 !== $code ) {
			if ( function_exists( 'set_site_transient' ) ) {
				set_site_transient( $key, array( '_error' => true ), self::ERROR_TTL );
			}
			return null;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return null;
		}

		$parsed = self::parse_release_payload( $data, $config['slug'], '' !== $token );
		if ( function_exists( 'set_site_transient' ) ) {
			set_site_transient( $key, $parsed ?? array( '_error' => true ), null !== $parsed ? self::CACHE_TTL : self::ERROR_TTL );
		}

		return $parsed;
	}

	/**
	 * Parses a GitHub Releases API JSON payload into a normalized update descriptor.
	 *
	 * Prefers the attached production ZIP asset (`{slug}-*.zip` or first `.zip` asset)
	 * so Composer `vendor/` autoloaders and top-level directory names match WordPress expectations.
	 *
	 * @param array<string, mixed> $data       Decoded GitHub release JSON.
	 * @param string               $slug       Plugin slug.
	 * @param bool                 $is_private Whether a GitHub auth token is active.
	 * @return array<string, string>|null Normalized release descriptor or null if no ZIP asset found.
	 */
	public static function parse_release_payload( array $data, string $slug, bool $is_private ): ?array {
		if ( ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			return null;
		}

		$tag     = trim( (string) ( $data['tag_name'] ?? '' ) );
		$version = ltrim( $tag, 'vV' );
		if ( ! preg_match( '/\A\d+\.\d+\.\d+\z/', $version ) ) {
			return null;
		}

		$assets       = is_array( $data['assets'] ?? null ) ? $data['assets'] : array();
		$chosen_asset = null;
		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) || empty( $asset['name'] ) ) {
				continue;
			}
			$name = strtolower( (string) $asset['name'] );
			if ( ! str_ends_with( $name, '.zip' ) ) {
				continue;
			}
			if ( str_starts_with( $name, strtolower( $slug ) ) ) {
				$chosen_asset = $asset;
				break;
			}
			if ( null === $chosen_asset ) {
				$chosen_asset = $asset;
			}
		}

		if ( ! is_array( $chosen_asset ) ) {
			return null;
		}

		// Private repos must download via the authenticated GitHub API asset endpoint (`url`);
		// public repos use `browser_download_url` directly with zero authentication.
		$package = $is_private
			? (string) ( $chosen_asset['url'] ?? '' )
			: (string) ( $chosen_asset['browser_download_url'] ?? '' );

		if ( '' === $package ) {
			return null;
		}

		return array(
			'version'      => $version,
			'package'      => $package,
			'html_url'     => (string) ( $data['html_url'] ?? '' ),
			'body'         => (string) ( $data['body'] ?? '' ),
			'published_at' => (string) ( $data['published_at'] ?? '' ),
		);
	}

	/**
	 * Resolves the optional GitHub PAT from `wp-config.php` constants or filter.
	 *
	 * @param array<string, string> $config Registered plugin configuration.
	 * @return string Bearer token or empty string for public repos.
	 */
	public static function resolve_token( array $config ): string {
		$token          = '';
		$token_constant = $config['token_constant'] ?? 'MDS_RM_GITHUB_TOKEN';

		if ( '' !== $token_constant && defined( $token_constant ) ) {
			$token = trim( (string) constant( $token_constant ) );
		} elseif ( defined( 'MDS_RM_GITHUB_TOKEN' ) ) {
			$token = trim( (string) constant( 'MDS_RM_GITHUB_TOKEN' ) );
		} elseif ( class_exists( \MDS\ReleaseManager\Core\Security\SecretVault::class ) ) {
			$token = \MDS\ReleaseManager\Core\Security\SecretVault::get(
				'github',
				'token',
				array(
					'const' => array( $token_constant, 'MDS_RM_GITHUB_TOKEN' ),
					'env'   => array( $token_constant, 'MDS_RM_GITHUB_TOKEN' ),
				)
			);
		}

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the GitHub Personal Access Token used for private repository updates.
			 *
			 * @since 2.0.0
			 * @param string                $token  Resolved token from wp-config.php constant, or empty string.
			 * @param string                $slug   Plugin slug.
			 * @param array<string, string> $config Updater configuration.
			 */
			$filtered = apply_filters( 'mds_rm_github_token', $token, $config['slug'], $config );
			$token    = is_string( $filtered ) ? trim( $filtered ) : $token;
		}

		return $token;
	}

	/**
	 * Finds a registered plugin configuration by slug.
	 *
	 * @param string $slug Plugin slug.
	 * @return array<string, string>|null
	 */
	private static function find_by_slug( string $slug ): ?array {
		foreach ( self::$plugins as $config ) {
			if ( $config['slug'] === $slug ) {
				return $config;
			}
		}
		return null;
	}

	/**
	 * Returns the site transient key for a plugin's cached GitHub release metadata.
	 *
	 * @param string $slug Plugin slug.
	 * @return string Transient key.
	 */
	private static function transient_key( string $slug ): string {
		return 'mds_rm_gh_rel_' . md5( $slug );
	}
}

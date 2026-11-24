<?php
/**
 * Centralized, multi-plugin cache manager with request coalescing and
 * Multisite Hub-and-Spoke support.
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects active WordPress cache plugins, manages per-plugin purge modes,
 * coalesces purges to once per site at request shutdown, and exposes a public
 * purge API for companion plugins (e.g. Bandcamp, WooCommerce).
 *
 * @since 1.1.0
 */
final class Cache {

	/**
	 * Site option storing cache management settings.
	 *
	 * @var string
	 */
	public const OPTION_NAME = 'mds_rm_cache_settings';

	/**
	 * Blog IDs queued to purge at request shutdown.
	 *
	 * @var array<int, true>
	 */
	private static array $queued_blogs = array();

	/**
	 * Whether the shutdown flush hook has been registered in this request.
	 *
	 * @var bool
	 */
	private static bool $shutdown_registered = false;

	/**
	 * Registers hooks for companion plugin purge actions.
	 */
	public static function register_hooks(): void {
		add_action( 'mds_rm_purge_cache', array( self::class, 'handle_purge_action' ), 10, 2 );
	}

	/**
	 * Returns default cache settings.
	 *
	 * @return array{purge_on_post_save: bool, plugins: array<string, string>}
	 */
	public static function defaults(): array {
		return array(
			'purge_on_post_save' => true,
			'plugins'            => array(),
		);
	}

	/**
	 * Returns stored cache settings merged with defaults.
	 *
	 * @return array{purge_on_post_save: bool, plugins: array<string, string>}
	 */
	public static function get_settings(): array {
		$defaults = self::defaults();
		$stored   = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $stored ) ) {
			return $defaults;
		}

		return array(
			'purge_on_post_save' => array_key_exists( 'purge_on_post_save', $stored ) ? (bool) $stored['purge_on_post_save'] : true,
			'plugins'            => is_array( $stored['plugins'] ?? null ) ? $stored['plugins'] : array(),
		);
	}

	/**
	 * Validates and sanitizes submitted cache settings.
	 *
	 * @param mixed $raw Submitted settings array.
	 * @return array{purge_on_post_save: bool, plugins: array<string, string>}|WP_Error
	 */
	public static function validate_settings( mixed $raw ): array|\WP_Error {
		if ( ! is_array( $raw ) ) {
			return new \WP_Error(
				'mds_rm_invalid_cache_settings',
				__( 'The cache settings are invalid.', 'mds-release-manager' ),
				array( 'status' => 400 )
			);
		}

		$adapters = self::adapters();
		$clean    = array(
			'purge_on_post_save' => ! empty( $raw['purge_on_post_save'] ),
			'plugins'            => array(),
		);

		$submitted_plugins = $raw['plugins'] ?? array();
		if ( ! is_array( $submitted_plugins ) ) {
			return new \WP_Error(
				'mds_rm_invalid_cache_settings',
				__( 'The cache plugin mode settings are invalid.', 'mds-release-manager' ),
				array( 'status' => 400 )
			);
		}

		foreach ( $submitted_plugins as $slug => $mode ) {
			$slug_key = sanitize_key( (string) $slug );
			if ( ! isset( $adapters[ $slug_key ] ) ) {
				continue;
			}
			$mode_str = is_scalar( $mode ) ? sanitize_key( (string) $mode ) : '';
			$allowed  = array_keys( $adapters[ $slug_key ]['modes'] );
			if ( ! in_array( $mode_str, $allowed, true ) ) {
				return new \WP_Error(
					'mds_rm_invalid_cache_settings',
					__( 'An invalid cache purge mode was selected.', 'mds-release-manager' ),
					array( 'status' => 400 )
				);
			}
			$clean['plugins'][ $slug_key ] = $mode_str;
		}

		return $clean;
	}

	/**
	 * Returns the registry of supported WordPress cache plugin adapters.
	 *
	 * Third-party plugins or custom hosts can register additional adapters via
	 * the `mds_rm_cache_adapters` filter.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function adapters(): array {
		$wpfc_premium = class_exists( 'WpFastestCachePremium' ) || defined( 'WPFC_PREMIUM_VERSION' );

		$adapters = array(
			'litespeed'        => array(
				'slug'         => 'litespeed',
				'label'        => __( 'LiteSpeed Cache', 'mds-release-manager' ),
				'tier'         => __( 'Free', 'mds-release-manager' ),
				'detect'       => static fn(): bool => defined( 'LSCWP_V' ) || ( function_exists( 'has_action' ) && (bool) has_action( 'litespeed_purge_all' ) ),
				'default_mode' => 'all',
				'modes'        => array(
					'all'      => __( 'Purge All (Pages + CSS/JS)', 'mds-release-manager' ),
					'assets'   => __( 'Purge CSS & JS Only', 'mds-release-manager' ),
					'disabled' => __( 'Disabled (let LiteSpeed auto-purge)', 'mds-release-manager' ),
				),
				'purge'        => static function ( string $mode ): bool {
					if ( 'assets' === $mode ) {
						do_action( 'litespeed_purge_cssjs' );
						return true;
					}
					if ( 'all' === $mode ) {
						do_action( 'litespeed_purge_all' );
						return true;
					}
					return false;
				},
			),
			'wp_rocket'        => array(
				'slug'         => 'wp_rocket',
				'label'        => __( 'WP Rocket', 'mds-release-manager' ),
				'tier'         => __( 'Paid', 'mds-release-manager' ),
				'detect'       => static fn(): bool => defined( 'WP_ROCKET_VERSION' ) || function_exists( 'rocket_clean_domain' ),
				'default_mode' => 'all',
				'modes'        => array(
					'all'      => __( 'Purge All (Pages + Minified CSS/JS)', 'mds-release-manager' ),
					'assets'   => __( 'Purge Minified CSS & JS Only', 'mds-release-manager' ),
					'disabled' => __( 'Disabled (let WP Rocket auto-purge)', 'mds-release-manager' ),
				),
				'purge'        => static function ( string $mode ): bool {
					if ( 'assets' === $mode && function_exists( 'rocket_clean_minify' ) ) {
						rocket_clean_minify();
						return true;
					}
					if ( 'all' === $mode ) {
						$did = false;
						if ( function_exists( 'rocket_clean_domain' ) ) {
							rocket_clean_domain();
							$did = true;
						}
						if ( function_exists( 'rocket_clean_minify' ) ) {
							rocket_clean_minify();
							$did = true;
						}
						return $did;
					}
					return false;
				},
			),
			'w3tc'             => array(
				'slug'         => 'w3tc',
				'label'        => __( 'W3 Total Cache', 'mds-release-manager' ),
				'tier'         => __( 'Free / Pro', 'mds-release-manager' ),
				'detect'       => static fn(): bool => defined( 'W3TC' ) || function_exists( 'w3tc_flush_all' ) || function_exists( 'w3tc_minify_flush' ),
				'default_mode' => 'all',
				'modes'        => array(
					'all'      => __( 'Purge All Caches (Pages + Minify)', 'mds-release-manager' ),
					'assets'   => __( 'Purge Minified CSS & JS Only', 'mds-release-manager' ),
					'disabled' => __( 'Disabled (let W3 Total Cache auto-purge)', 'mds-release-manager' ),
				),
				'purge'        => static function ( string $mode ): bool {
					if ( 'assets' === $mode && function_exists( 'w3tc_minify_flush' ) ) {
						w3tc_minify_flush();
						return true;
					}
					if ( 'all' === $mode ) {
						if ( function_exists( 'w3tc_flush_all' ) ) {
							w3tc_flush_all();
							return true;
						}
						if ( class_exists( '\\W3TC\\Dispatcher' ) ) {
							$flush = \W3TC\Dispatcher::component( 'CacheFlush' );
							if ( is_object( $flush ) && method_exists( $flush, 'flush_all' ) ) {
								$flush->flush_all();
								return true;
							}
						}
					}
					return false;
				},
			),
			'wp_fastest_cache' => array(
				'slug'         => 'wp_fastest_cache',
				'label'        => $wpfc_premium ? __( 'WP Fastest Cache (Premium)', 'mds-release-manager' ) : __( 'WP Fastest Cache', 'mds-release-manager' ),
				'tier'         => $wpfc_premium ? __( 'Premium', 'mds-release-manager' ) : __( 'Free', 'mds-release-manager' ),
				'detect'       => static fn(): bool => class_exists( 'WpFastestCache' ),
				'default_mode' => 'all',
				'modes'        => $wpfc_premium
					? array(
						'all'      => __( 'Purge All (HTML + Minified CSS/JS)', 'mds-release-manager' ),
						'html'     => __( 'Purge HTML Cache Only', 'mds-release-manager' ),
						'disabled' => __( 'Disabled (let WP Fastest Cache auto-purge)', 'mds-release-manager' ),
					)
					: array(
						'all'      => __( 'Purge All Cache', 'mds-release-manager' ),
						'disabled' => __( 'Disabled (let WP Fastest Cache auto-purge)', 'mds-release-manager' ),
					),
				'purge'        => static function ( string $mode ): bool {
					if ( ! class_exists( 'WpFastestCache' ) ) {
						return false;
					}
					$wpfc = new \WpFastestCache();
					if ( 'html' === $mode && method_exists( $wpfc, 'deleteCache' ) ) {
						$wpfc->deleteCache( false );
						return true;
					}
					if ( 'all' === $mode && method_exists( $wpfc, 'deleteCache' ) ) {
						$wpfc->deleteCache( true );
						return true;
					}
					return false;
				},
			),
			'sg_optimizer'     => array(
				'slug'         => 'sg_optimizer',
				'label'        => __( 'SiteGround Speed Optimizer', 'mds-release-manager' ),
				'tier'         => __( 'Free', 'mds-release-manager' ),
				'detect'       => static fn(): bool => function_exists( 'sg_cachepress_purge_cache' ) || function_exists( 'sg_cachepress_purge_everything' ),
				'default_mode' => 'all',
				'modes'        => array(
					'all'      => __( 'Purge All (Dynamic + Assets)', 'mds-release-manager' ),
					'disabled' => __( 'Disabled (let SG Optimizer auto-purge)', 'mds-release-manager' ),
				),
				'purge'        => static function ( string $mode ): bool {
					if ( 'all' !== $mode ) {
						return false;
					}
					if ( function_exists( 'sg_cachepress_purge_everything' ) ) {
						sg_cachepress_purge_everything();
						return true;
					}
					if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
						sg_cachepress_purge_cache();
						return true;
					}
					return false;
				},
			),
			'wp_super_cache'   => array(
				'slug'         => 'wp_super_cache',
				'label'        => __( 'WP Super Cache', 'mds-release-manager' ),
				'tier'         => __( 'Free', 'mds-release-manager' ),
				'detect'       => static fn(): bool => function_exists( 'wp_cache_clear_cache' ),
				'default_mode' => 'all',
				'modes'        => array(
					'all'      => __( 'Purge All Cache', 'mds-release-manager' ),
					'disabled' => __( 'Disabled (let WP Super Cache auto-purge)', 'mds-release-manager' ),
				),
				'purge'        => static function ( string $mode, int $blog_id ): bool {
					if ( 'all' !== $mode || ! function_exists( 'wp_cache_clear_cache' ) ) {
						return false;
					}
					wp_cache_clear_cache( $blog_id );
					return true;
				},
			),
			'autoptimize'      => array(
				'slug'         => 'autoptimize',
				'label'        => __( 'Autoptimize', 'mds-release-manager' ),
				'tier'         => __( 'Free', 'mds-release-manager' ),
				'detect'       => static fn(): bool => class_exists( 'autoptimizeCache' ),
				'default_mode' => 'assets',
				'modes'        => array(
					'assets'   => __( 'Purge Aggregated CSS & JS Cache', 'mds-release-manager' ),
					'disabled' => __( 'Disabled', 'mds-release-manager' ),
				),
				'purge'        => static function ( string $mode ): bool {
					if ( 'assets' !== $mode || ! class_exists( 'autoptimizeCache' ) ) {
						return false;
					}
					\autoptimizeCache::clearall();
					return true;
				},
			),
			'flying_press'     => array(
				'slug'         => 'flying_press',
				'label'        => __( 'FlyingPress', 'mds-release-manager' ),
				'tier'         => __( 'Paid', 'mds-release-manager' ),
				'detect'       => static fn(): bool => class_exists( '\\FlyingPress\\Purge' ),
				'default_mode' => 'all',
				'modes'        => array(
					'all'      => __( 'Purge Everything', 'mds-release-manager' ),
					'disabled' => __( 'Disabled (let FlyingPress auto-purge)', 'mds-release-manager' ),
				),
				'purge'        => static function ( string $mode ): bool {
					if ( 'all' !== $mode || ! class_exists( '\\FlyingPress\\Purge' ) ) {
						return false;
					}
					\FlyingPress\Purge::purge_everything();
					return true;
				},
			),
			'breeze'           => array(
				'slug'         => 'breeze',
				'label'        => __( 'Breeze (Cloudways)', 'mds-release-manager' ),
				'tier'         => __( 'Free', 'mds-release-manager' ),
				'detect'       => static fn(): bool => defined( 'BREEZE_VERSION' ) || ( function_exists( 'has_action' ) && (bool) has_action( 'breeze_clear_all_cache' ) ),
				'default_mode' => 'all',
				'modes'        => array(
					'all'      => __( 'Purge All Cache', 'mds-release-manager' ),
					'disabled' => __( 'Disabled (let Breeze auto-purge)', 'mds-release-manager' ),
				),
				'purge'        => static function ( string $mode ): bool {
					if ( 'all' !== $mode ) {
						return false;
					}
					do_action( 'breeze_clear_all_cache' );
					return true;
				},
			),
		);

		/**
		 * Filters the registered cache manager adapters.
		 *
		 * @since 1.1.0
		 * @param array<string, array<string, mixed>> $adapters Keyed cache adapter definitions.
		 */
		$filtered = apply_filters( 'mds_rm_cache_adapters', $adapters );
		return is_array( $filtered ) ? $filtered : $adapters;
	}

	/**
	 * Returns only the cache adapters currently detected as active on this site.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function detected_adapters(): array {
		$detected = array();
		$settings = self::get_settings();

		foreach ( self::adapters() as $slug => $adapter ) {
			if ( empty( $adapter['detect'] ) || ! is_callable( $adapter['detect'] ) ) {
				continue;
			}
			if ( ! (bool) call_user_func( $adapter['detect'] ) ) {
				continue;
			}
			$mode = $settings['plugins'][ $slug ] ?? (string) ( $adapter['default_mode'] ?? 'all' );
			if ( ! isset( $adapter['modes'][ $mode ] ) ) {
				$mode = (string) ( $adapter['default_mode'] ?? 'all' );
			}
			$adapter['active_mode'] = $mode;
			$detected[ $slug ]      = $adapter;
		}

		return $detected;
	}

	/**
	 * Action callback for `do_action( 'mds_rm_purge_cache', $context, $args )`.
	 *
	 * @param string               $context Context label (e.g. 'bandcamp_settings', 'woocommerce_sync').
	 * @param array<string, mixed> $args    Optional arguments (`immediate`, `force`, `blog_id`).
	 */
	public static function handle_purge_action( string $context = 'external', array $args = array() ): void {
		self::purge( $context, $args );
	}

	/**
	 * Requests a cache purge for the current (or specified) site.
	 *
	 * Unless `$args['immediate']` is true, purges are coalesced so each site is
	 * flushed at most once at PHP request `shutdown`.
	 *
	 * @param string               $context Context identifier ('post_save', 'settings', 'sync', 'manual', or companion slug).
	 * @param array<string, mixed> $args    Optional flags: `immediate` (bool), `force` (bool), `blog_id` (int).
	 * @return array{queued: bool, purged: string[], skipped: string[], blog_id: int}
	 */
	public static function purge( string $context = 'general', array $args = array() ): array {
		$blog_id  = isset( $args['blog_id'] ) ? absint( $args['blog_id'] ) : ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1 );
		$force    = ! empty( $args['force'] );
		$settings = self::get_settings();

		if ( 'post_save' === $context && ! $force && empty( $settings['purge_on_post_save'] ) ) {
			return array(
				'queued'  => false,
				'purged'  => array(),
				'skipped' => array( 'post_save_disabled' ),
				'blog_id' => $blog_id,
			);
		}

		if ( ! empty( $args['immediate'] ) ) {
			return self::execute_for_blog( $blog_id, $context );
		}

		self::queue_blog( $blog_id );

		return array(
			'queued'  => true,
			'purged'  => array(),
			'skipped' => array(),
			'blog_id' => $blog_id,
		);
	}

	/**
	 * Queues a blog ID to be purged once at request shutdown.
	 *
	 * @param int $blog_id Site ID to flush.
	 */
	public static function queue_blog( int $blog_id ): void {
		$blog_id                        = max( 1, $blog_id );
		self::$queued_blogs[ $blog_id ] = true;

		if ( ! self::$shutdown_registered && function_exists( 'add_action' ) ) {
			self::$shutdown_registered = true;
			add_action( 'shutdown', array( self::class, 'flush_queued' ), 99 );
		}
	}

	/**
	 * Flushes all queued sites once at request shutdown.
	 *
	 * @return array<int, array{queued: bool, purged: string[], skipped: string[], blog_id: int}>
	 */
	public static function flush_queued(): array {
		if ( empty( self::$queued_blogs ) ) {
			return array();
		}

		$blogs              = array_keys( self::$queued_blogs );
		self::$queued_blogs = array();
		$results            = array();

		$current_blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		$is_multisite = function_exists( 'is_multisite' ) && is_multisite();

		foreach ( $blogs as $blog_id ) {
			if ( $is_multisite && $blog_id !== $current_blog ) {
				switch_to_blog( $blog_id );
				try {
					$results[ $blog_id ] = self::execute_for_blog( $blog_id, 'shutdown' );
				} finally {
					restore_current_blog();
				}
			} else {
				$results[ $blog_id ] = self::execute_for_blog( $blog_id, 'shutdown' );
			}
		}

		return $results;
	}

	/**
	 * Executes configured purge callbacks for every detected active adapter on the current blog.
	 *
	 * @param int    $blog_id Active blog ID.
	 * @param string $context Trigger context.
	 * @return array{queued: bool, purged: string[], skipped: string[], blog_id: int}
	 */
	private static function execute_for_blog( int $blog_id, string $context ): array {
		$purged   = array();
		$skipped  = array();
		$detected = self::detected_adapters();

		foreach ( $detected as $slug => $adapter ) {
			$mode = (string) ( $adapter['active_mode'] ?? 'all' );
			if ( 'disabled' === $mode || empty( $adapter['purge'] ) || ! is_callable( $adapter['purge'] ) ) {
				$skipped[] = $slug;
				continue;
			}

			try {
				$did_purge = (bool) call_user_func( $adapter['purge'], $mode, $blog_id );
				if ( $did_purge ) {
					$purged[] = (string) $adapter['label'];
				} else {
					$skipped[] = $slug;
				}
			} catch ( \Throwable $e ) {
				$skipped[] = $slug;
				if ( class_exists( 'MDS\ReleaseManager\Core\Logger' ) ) {
					Logger::report_failure( sprintf( 'Cache purge failed for %s: %s', $slug, $e->getMessage() ) );
				}
			}
		}

		/**
		 * Fires after MDS Release Manager executes a cache purge for a site.
		 *
		 * @since 1.1.0
		 * @param string[] $purged  Labels of cache plugins purged.
		 * @param string[] $skipped Slugs of detected cache plugins skipped.
		 * @param int      $blog_id Site ID.
		 * @param string   $context Trigger context.
		 */
		if ( function_exists( 'do_action' ) ) {
			do_action( 'mds_rm_after_cache_purge', $purged, $skipped, $blog_id, $context );
		}

		return array(
			'queued'  => false,
			'purged'  => $purged,
			'skipped' => $skipped,
			'blog_id' => $blog_id,
		);
	}
}

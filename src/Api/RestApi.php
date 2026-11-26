<?php
/**
 * REST API Endpoints
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Api;

use WP_Error;
use WP_Post;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin's REST API endpoints.
 *
 * @since 0.9.0
 */
final class RestApi {

	/**
	 * Registers REST API initialization.
	 *
	 * @since 0.9.0
	 */
	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'register_rest_routes' ) );
	}

	/**
	 * Registers the plugin's REST API routes.
	 *
	 * @since 0.9.0
	 */
	public static function register_rest_routes(): void {
		register_rest_route(
			'mds/v1',
			'/releases',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_releases_api' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'page'     => array(
						'default'           => 1,
						'sanitize_callback' => static function ( $value ) {
							return is_scalar( $value ) ? absint( $value ) : 0;
						},
						'validate_callback' => static function ( $value ) {
							return is_numeric( $value ) && (int) $value >= 1;
						},
					),
					'per_page' => array(
						'default'           => 100,
						'sanitize_callback' => static function ( $value ) {
							return is_scalar( $value ) ? absint( $value ) : 0;
						},
						'validate_callback' => static function ( $value ) {
							return is_numeric( $value ) && (int) $value >= 1 && (int) $value <= 100;
						},
					),
				),
			)
		);

		register_rest_route(
			'mds/v1',
			'/artists',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'search_artists_api' ),
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'search' => array(
						'required'          => true,
						'sanitize_callback' => static function ( $value ) {
							return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
						},
						'validate_callback' => static function ( $value ) {
							return is_string( $value ) && '' !== trim( $value );
						},
					),
					'page'   => array(
						'default'           => 1,
						'sanitize_callback' => static function ( $value ) {
							return is_scalar( $value ) ? absint( $value ) : 0;
						},
						'validate_callback' => static function ( $value ) {
							return is_numeric( $value ) && (int) $value >= 1;
						},
					),
				),
			)
		);

		register_rest_route(
			'mds/v1',
			'/releases/(?P<id>\d+)/preview',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'preview_release_api' ),
				'permission_callback' => static function ( \WP_REST_Request $request ): bool {
					return current_user_can( 'edit_post', (int) $request->get_param( 'id' ) );
				},
			)
		);

		register_rest_route(
			'mds/v1',
			'/artists/(?P<id>\d+)/preview',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'preview_artist_api' ),
				'permission_callback' => static function ( \WP_REST_Request $request ): bool {
					return current_user_can( 'edit_post', (int) $request->get_param( 'id' ) );
				},
			)
		);

		register_rest_route(
			'mds/v1',
			'/settings',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'save_settings_api' ),
				'permission_callback' =>
					/**
					 * Checks whether the current user can manage plugin settings.
					 *
					 * @since 0.9.0
					 * @return bool Whether the current user can manage settings.
					 */
					function () {
						return current_user_can( 'manage_options' );
					},
			)
		);

		register_rest_route(
			'mds/v1',
			'/purge-cache',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'purge_cache_api' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'mds/v1',
			'/network-settings',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'save_network_settings_api' ),
				'permission_callback' =>
					/**
					 * Checks whether the current user can manage network settings.
					 *
					 * @since 0.9.0
					 * @return bool Whether the current user can manage network settings.
					 */
					function () {
						return current_user_can( 'manage_network_options' );
					},
			)
		);

		register_rest_route(
			'mds/v1',
			'/sync-network',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'sync_network_api' ),
				'args'                => array(
					'job_id' => array(
						'sanitize_callback' => static function ( $value ) {
							if ( ! is_string( $value ) ) {
								return new WP_Error( 'mds_invalid_sync_job_id', __( 'The synchronization job ID must be text.', 'mds-release-manager' ), array( 'status' => 400 ) );
							}
							return sanitize_text_field( $value );
						},
						'validate_callback' => static function ( $value ) {
							return is_string( $value );
						},
					),
					'mode'   => array(
						'default'           => 'step',
						'sanitize_callback' => 'sanitize_key',
					),
				),
				'permission_callback' =>
					/**
					 * Checks whether the current user can manage network settings.
					 *
					 * @since 0.9.0
					 * @return bool Whether the current user can manage network settings.
					 */
					function () {
						return current_user_can( 'manage_network_options' );
					},
			)
		);

		register_rest_route(
			'mds/v1',
			'/sync-network/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'sync_network_status_api' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_network_options' );
				},
			)
		);

		register_rest_route(
			'mds/v1',
			'/artists/(?P<id>\d+)/release-routing',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'save_artist_release_routing_api' ),
				'permission_callback' => static function ( WP_REST_Request $request ): bool {
					$artist_id = absint( $request->get_param( 'id' ) );
					return $artist_id > 0 && \MDS\ReleaseManager\Core\Sync::is_hub() && current_user_can( 'edit_post', $artist_id );
				},
			)
		);

		register_rest_route(
			'mds/v1',
			'/releases/(?P<id>\d+)/audio',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_release_audio_api' ),
				'permission_callback' => static function ( WP_REST_Request $request ): bool {
					$release_id = absint( $request->get_param( 'id' ) );
					if ( $release_id <= 0 || 'release' !== get_post_type( $release_id ) ) {
						return false;
					}
					if ( 'publish' === get_post_status( $release_id ) && ! post_password_required( $release_id ) ) {
						return true;
					}
					return current_user_can( 'edit_post', $release_id );
				},
				'args'                => array(
					'provider' => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
					'fresh'    => array(
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			)
		);

		register_rest_route(
			'mds/v1',
			'/audio-providers',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_audio_providers_api' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}

	/**
	 * Gets releases through the REST API.
	 *
	 * @since 0.9.0
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response The response containing the releases.
	 */
	public static function get_releases_api( WP_REST_Request $request ): WP_REST_Response {
		$page     = $request->get_param( 'page' );
		$per_page = $request->get_param( 'per_page' );
		$query    = new WP_Query(
			array(
				'post_type'      => 'release',
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => min( 100, max( 1, null === $per_page ? 100 : absint( $per_page ) ) ),
				'paged'          => max( 1, null === $page ? 1 : absint( $page ) ),
			)
		);
		$data     = array();

		foreach ( $query->posts as $rel ) {
			$data[] = array(
				'id'    => $rel->ID,
				'title' => $rel->post_title,
				'upc'   => get_post_meta( $rel->ID, 'upc_ean', true ),
			);
		}

		$response = rest_ensure_response( $data );
		$response->header( 'X-WP-Total', (string) $query->found_posts );
		$response->header( 'X-WP-TotalPages', (string) $query->max_num_pages );

		return $response;
	}

	/**
	 * Searches Artists for the Release editor picker.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response Paginated Artist matches.
	 */
	public static function search_artists_api( WP_REST_Request $request ): WP_REST_Response {
		$query = new WP_Query(
			array(
				'post_type'      => 'artist',
				'post_status'    => 'any',
				'posts_per_page' => 20,
				'paged'          => max( 1, absint( $request->get_param( 'page' ) ) ),
				'orderby'        => 'title',
				'order'          => 'ASC',
				's'              => trim( sanitize_text_field( $request->get_param( 'search' ) ) ),
				'search_columns' => array( 'post_title' ),
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Restricts artist picker to synced artists with UUIDs.
					array(
						'key'     => '_mds_artist_uuid',
						'compare' => 'EXISTS',
					),
				),
			)
		);
		$items = array();

		foreach ( $query->posts as $artist ) {
			$uuid = get_post_meta( $artist->ID, '_mds_artist_uuid', true );
			if ( ! is_string( $uuid ) || '' === $uuid ) {
				continue;
			}
			$items[] = array(
				'uuid'  => $uuid,
				'title' => $artist->post_title,
			);
		}

		return rest_ensure_response(
			array(
				'items' => $items,
				'pages' => (int) $query->max_num_pages,
			)
		);
	}

	/**
	 * Saves plugin settings through the REST API.
	 *
	 * @since 0.9.0
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error The response indicating whether settings were saved.
	 */
	public static function save_settings_api( $request ): WP_REST_Response|WP_Error {
		$params = $request->get_body_params();

		$block_defaults = null;
		if ( isset( $params['mds_rm_block_defaults'] ) ) {
			if ( ! is_string( $params['mds_rm_block_defaults'] ) || strlen( $params['mds_rm_block_defaults'] ) > 65536 ) {
				return new WP_Error( 'mds_rm_invalid_block_defaults', __( 'The block defaults are invalid.', 'mds-release-manager' ), array( 'status' => 400 ) );
			}
			$block_defaults = json_decode( $params['mds_rm_block_defaults'], true );
			$valid          = \MDS\ReleaseManager\Blocks\BlockDefaults::validate( $block_defaults );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}

		$template_defaults = null;
		if ( isset( $params['mds_rm_template_defaults'] ) ) {
			$raw_tpl = $params['mds_rm_template_defaults'];
			if ( is_string( $raw_tpl ) ) {
				if ( strlen( $raw_tpl ) > 65536 ) {
					return new WP_Error( 'mds_rm_invalid_template_defaults', __( 'The default template settings are invalid.', 'mds-release-manager' ), array( 'status' => 400 ) );
				}
				$raw_tpl = json_decode( wp_unslash( $raw_tpl ), true );
			}
			$valid_tpl = \MDS\ReleaseManager\Frontend\TemplateDefaults::validate( $raw_tpl );
			if ( is_wp_error( $valid_tpl ) ) {
				return $valid_tpl;
			}
			$template_defaults = $valid_tpl;
		}

		if ( array_key_exists( 'mds_rm_providers', $params ) && ! is_array( $params['mds_rm_providers'] ) ) {
			return new WP_Error( 'mds_rm_invalid_providers', __( 'The streaming providers are invalid.', 'mds-release-manager' ), array( 'status' => 400 ) );
		}

		$cache_settings = null;
		if ( isset( $params['mds_rm_cache_present'] ) || isset( $params['mds_rm_cache_plugins'] ) || isset( $params['mds_rm_cache_purge_on_post_save'] ) ) {
			$existing_cache  = class_exists( 'MDS\ReleaseManager\Core\Cache' ) ? \MDS\ReleaseManager\Core\Cache::get_settings() : array( 'plugins' => array() );
			$raw_plugins     = isset( $params['mds_rm_cache_plugins'] ) && is_array( $params['mds_rm_cache_plugins'] )
				? array_merge( $existing_cache['plugins'] ?? array(), wp_unslash( $params['mds_rm_cache_plugins'] ) )
				: ( $existing_cache['plugins'] ?? array() );
			$validated_cache = \MDS\ReleaseManager\Core\Cache::validate_settings(
				array(
					'purge_on_post_save' => ! empty( $params['mds_rm_cache_purge_on_post_save'] ),
					'plugins'            => $raw_plugins,
				)
			);
			if ( is_wp_error( $validated_cache ) ) {
				return $validated_cache;
			}
			$cache_settings = $validated_cache;
		}

		if ( isset( $params['mds_rm_providers'] ) ) {
			$providers = array();
			$names     = isset( $params['mds_rm_providers']['name'] ) ? (array) $params['mds_rm_providers']['name'] : array();
			$icons     = isset( $params['mds_rm_providers']['icon'] ) ? (array) $params['mds_rm_providers']['icon'] : array();
			$colors    = isset( $params['mds_rm_providers']['color'] ) ? (array) $params['mds_rm_providers']['color'] : array();

			$count = count( $names );
			for ( $i = 0; $i < $count; $i++ ) {
				$name = sanitize_text_field( wp_unslash( $names[ $i ] ?? '' ) );
				if ( empty( $name ) ) {
					continue;
				}
				$providers[] = array(
					'name'  => $name,
					'icon'  => sanitize_text_field( wp_unslash( $icons[ $i ] ?? '' ) ),
					'color' => sanitize_text_field( wp_unslash( $colors[ $i ] ?? '' ) ),
				);
			}
			update_option( 'mds_rm_providers', $providers );
		}

		if ( isset( $params['mds_rm_fa_support'] ) ) {
			update_option( 'mds_rm_fa_support', \MDS\ReleaseManager\Frontend\Assets::sanitize_font_awesome_source( wp_unslash( $params['mds_rm_fa_support'] ) ) );
		}

		if ( null !== $block_defaults ) {
			update_option( 'mds_rm_block_defaults', $block_defaults, true );
		}

		if ( null !== $template_defaults ) {
			update_option( \MDS\ReleaseManager\Frontend\TemplateDefaults::OPTION_NAME, $template_defaults, true );
		}

		if ( null !== $cache_settings ) {
			update_option( \MDS\ReleaseManager\Core\Cache::OPTION_NAME, $cache_settings );
		}

		\MDS\ReleaseManager\Core\Sync::purge_cache( 'settings' );

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Renders a non-destructive live preview of a Release using unsaved editor values.
	 *
	 * @since 1.1.0
	 * @param \WP_REST_Request $request The REST request carrying unsaved draft fields.
	 * @return \WP_REST_Response|\WP_Error Rendered preview HTML, stylesheet URLs, and routing notice.
	 */
	public static function preview_release_api( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = absint( $request->get_param( 'id' ) );
		$post    = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'release' !== $post->post_type ) {
			return new \WP_Error( 'mds_rm_invalid_release', __( 'Release not found.', 'mds-release-manager' ), array( 'status' => 404 ) );
		}

		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			$data = $request->get_body_params();
		}

		$title        = isset( $data['title'] ) && is_string( $data['title'] ) ? sanitize_text_field( $data['title'] ) : $post->post_title;
		$raw_content  = isset( $data['content'] ) && is_string( $data['content'] ) ? wp_kses_post( $data['content'] ) : $post->post_content;
		$thumbnail_id = isset( $data['thumbnail_id'] ) ? absint( $data['thumbnail_id'] ) : (int) get_post_thumbnail_id( $post_id );

		// Build in-memory meta overrides without modifying the database.
		$meta_overrides = array();
		if ( array_key_exists( 'upc_ean', $data ) ) {
			$meta_overrides['upc_ean'] = sanitize_text_field( (string) $data['upc_ean'] );
		}
		if ( array_key_exists( 'artist_id', $data ) ) {
			$meta_overrides['artist_id'] = sanitize_text_field( (string) $data['artist_id'] );
		}
		if ( array_key_exists( 'record_label', $data ) ) {
			$meta_overrides['record_label'] = sanitize_text_field( (string) $data['record_label'] );
		}
		if ( array_key_exists( 'distributor', $data ) ) {
			$meta_overrides['distributor'] = sanitize_text_field( (string) $data['distributor'] );
		}
		if ( array_key_exists( 'audio_preview_url', $data ) ) {
			$meta_overrides['audio_preview_url'] = esc_url_raw( (string) $data['audio_preview_url'] );
		}
		if ( array_key_exists( 'buy_now_mode', $data ) ) {
			$mode                           = sanitize_key( (string) $data['buy_now_mode'] );
			$meta_overrides['buy_now_mode'] = in_array( $mode, array( 'direct', 'picker' ), true ) ? $mode : '';
		}
		if ( array_key_exists( 'release_routing_mode', $data ) ) {
			$meta_overrides['release_routing_mode'] = \MDS\ReleaseManager\Frontend\ArtistRouting::sanitize_release_mode( $data['release_routing_mode'] );
		}
		if ( array_key_exists( 'release_routing_url', $data ) ) {
			$meta_overrides['release_routing_url'] = esc_url_raw( (string) $data['release_routing_url'] );
		}
		if ( isset( $data['streaming_links'] ) && is_array( $data['streaming_links'] ) ) {
			$clean_streaming = array();
			foreach ( $data['streaming_links'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$prov = sanitize_text_field( (string) ( $row['provider'] ?? '' ) );
				$url  = esc_url_raw( (string) ( $row['url'] ?? '' ) );
				if ( '' !== $prov && '' !== $url ) {
					$clean_streaming[ $prov ] = $url;
				}
			}
			$meta_overrides['streaming_links'] = wp_json_encode( $clean_streaming );
		}
		if ( isset( $data['buy_now_links'] ) && is_array( $data['buy_now_links'] ) ) {
			$clean_buy = array();
			$primary   = '';
			foreach ( $data['buy_now_links'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$url = esc_url_raw( (string) ( $row['url'] ?? '' ) );
				if ( '' === $url ) {
					continue;
				}
				$is_primary  = ! empty( $row['primary'] );
				$clean_buy[] = array(
					'format'  => sanitize_key( (string) ( $row['format'] ?? '' ) ),
					'store'   => sanitize_text_field( (string) ( $row['store'] ?? '' ) ),
					'url'     => $url,
					'primary' => $is_primary,
				);
				if ( $is_primary && '' === $primary ) {
					$primary = $url;
				}
			}
			if ( '' === $primary && ! empty( $clean_buy[0]['url'] ) ) {
				$primary                 = $clean_buy[0]['url'];
				$clean_buy[0]['primary'] = true;
			}
			$meta_overrides['buy_now_links'] = wp_json_encode( $clean_buy );
			$meta_overrides['buy_now_link']  = $primary;
		}

		$draft_type_id = isset( $data['release_type'] ) ? absint( $data['release_type'] ) : 0;

		$meta_filter = static function ( $value, $object_id, $meta_key, $single ) use ( $post_id, $meta_overrides ) {
			if ( (int) $object_id !== $post_id || ! is_string( $meta_key ) || ! array_key_exists( $meta_key, $meta_overrides ) ) {
				return $value;
			}
			return $single ? $meta_overrides[ $meta_key ] : array( $meta_overrides[ $meta_key ] );
		};

		$terms_filter = static function ( $terms, $object_id, $taxonomy ) use ( $post_id, $draft_type_id ) {
			if ( (int) $object_id === $post_id && 'release_type' === $taxonomy && $draft_type_id > 0 ) {
				$term = get_term( $draft_type_id, 'release_type' );
				if ( $term instanceof \WP_Term ) {
					return array( $term );
				}
			}
			return $terms;
		};

		add_filter( 'get_post_metadata', $meta_filter, 99, 4 );
		add_filter( 'get_the_terms', $terms_filter, 99, 3 );

		try {
			$tpl          = \MDS\ReleaseManager\Frontend\TemplateDefaults::get( 'release' );
			$redirect_url = \MDS\ReleaseManager\Frontend\ArtistRouting::resolve_release_redirect( $post_id );
			$image_html   = '';
			if ( ! empty( $tpl['show_image'] ) && $thumbnail_id > 0 ) {
				$image_html = (string) wp_get_attachment_image(
					$thumbnail_id,
					'large',
					false,
					array( 'class' => 'mds-release-fallback__image' )
				);
			}

			$meta_html = ! empty( $tpl['show_meta'] ) ? render_block(
				array(
					'blockName'    => 'mds-rm/release-meta',
					'attrs'        => array( 'releaseId' => $post_id ),
					'innerBlocks'  => array(),
					'innerHTML'    => '',
					'innerContent' => array(),
				)
			) : '';

			$player_html = ! empty( $tpl['show_player'] ) ? render_block(
				array(
					'blockName'    => 'mds-rm/release-player',
					'attrs'        => array( 'releaseId' => $post_id ),
					'innerBlocks'  => array(),
					'innerHTML'    => '',
					'innerContent' => array(),
				)
			) : '';

			$links_html = ! empty( $tpl['show_links'] ) ? render_block(
				array(
					'blockName'    => 'mds-rm/release-links',
					'attrs'        => array( 'releaseId' => $post_id ),
					'innerBlocks'  => array(),
					'innerHTML'    => '',
					'innerContent' => array(),
				)
			) : '';

			$rendered = \MDS\ReleaseManager\Core\TemplateLoader::render(
				'templates/release-fallback.php',
				array(
					'content'         => wpautop( $raw_content ),
					'image'           => $image_html,
					'layout'          => (string) ( $tpl['layout'] ?? 'split' ),
					'image_position'  => (string) ( $tpl['image_position'] ?? 'left' ),
					'sticky_image'    => ! empty( $tpl['sticky_image'] ),
					'mobile_order'    => (string) ( $tpl['mobile_order'] ?? 'image_first' ),
					'component_order' => \MDS\ReleaseManager\Frontend\TemplateDefaults::normalize_release_component_order( $tpl['component_order'] ?? null ),
					'style'           => \MDS\ReleaseManager\Frontend\TemplateDefaults::get_release_style_attribute(),
					'meta_html'       => $meta_html,
					'player_html'     => $player_html,
					'links_html'      => $links_html,
				)
			);
		} finally {
			remove_filter( 'get_post_metadata', $meta_filter, 99 );
			remove_filter( 'get_the_terms', $terms_filter, 99 );
		}

		$css_urls = array(
			includes_url( 'css/dashicons.min.css' ),
			MDS_RM_URL . 'css/release-fallback.css?ver=' . filemtime( MDS_RM_PATH . 'css/release-fallback.css' ),
			MDS_RM_URL . 'css/release-meta.css?ver=' . filemtime( MDS_RM_PATH . 'css/release-meta.css' ),
			MDS_RM_URL . 'css/release-links.css?ver=' . filemtime( MDS_RM_PATH . 'css/release-links.css' ),
		);
		if ( 'none' !== \MDS\ReleaseManager\Frontend\Assets::resolve_font_awesome_source() ) {
			$css_urls[] = (string) apply_filters( 'mds_rm_font_awesome_cdn_url', \MDS\ReleaseManager\Frontend\Assets::FA_CDN_URL );
		}

		$routing_notice = '';
		if ( '' !== $redirect_url ) {
			$routing_notice = sprintf(
				/* translators: %s: Active redirect URL. */
				__( 'Page Routing active: visitors will be redirected (302) to %s instead of seeing this fallback layout.', 'mds-release-manager' ),
				$redirect_url
			);
		}

		return rest_ensure_response(
			array(
				'title'            => $title,
				'html'             => $rendered,
				'css_urls'         => $css_urls,
				'buy_now_modal_js' => MDS_RM_URL . 'js/buy-now-modal.js?ver=' . filemtime( MDS_RM_PATH . 'js/buy-now-modal.js' ),
				'routing_notice'   => $routing_notice,
			)
		);
	}

	/**
	 * Renders a non-destructive live preview of an Artist using unsaved editor values.
	 *
	 * @since 1.1.0
	 * @param \WP_REST_Request $request The REST request carrying unsaved draft fields.
	 * @return \WP_REST_Response|\WP_Error Rendered preview HTML, stylesheet URLs, and routing notice.
	 */
	public static function preview_artist_api( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = absint( $request->get_param( 'id' ) );
		$post    = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'artist' !== $post->post_type ) {
			return new \WP_Error( 'mds_rm_invalid_artist', __( 'Artist not found.', 'mds-release-manager' ), array( 'status' => 404 ) );
		}

		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			$data = $request->get_body_params();
		}

		$title            = isset( $data['title'] ) && is_string( $data['title'] ) ? sanitize_text_field( $data['title'] ) : $post->post_title;
		$raw_content      = isset( $data['content'] ) && is_string( $data['content'] ) ? wp_kses_post( $data['content'] ) : $post->post_content;
		$thumbnail_id     = isset( $data['thumbnail_id'] ) ? absint( $data['thumbnail_id'] ) : (int) get_post_thumbnail_id( $post_id );
		$contact_html     = isset( $data['artist_contact'] ) && is_string( $data['artist_contact'] )
			? wp_kses_post( $data['artist_contact'] )
			: (string) get_post_meta( $post_id, 'artist_contact', true );
		$homepage_url     = isset( $data['artist_homepage_url'] ) && is_string( $data['artist_homepage_url'] )
			? esc_url_raw( $data['artist_homepage_url'] )
			: (string) get_post_meta( $post_id, 'artist_homepage_url', true );
		$homepage_routing = array_key_exists( 'artist_homepage_routing', $data )
			? ! empty( $data['artist_homepage_routing'] )
			: '1' === (string) get_post_meta( $post_id, 'artist_homepage_routing', true );

		$tpl          = \MDS\ReleaseManager\Frontend\TemplateDefaults::get( 'artist' );
		$show_image   = ! empty( $tpl['show_portrait'] );
		$show_discog  = ! empty( $tpl['show_discography'] );
		$show_contact = ! empty( $tpl['show_contact'] );

		$image_html = ( $show_image && $thumbnail_id > 0 )
			? (string) wp_get_attachment_image( $thumbnail_id, 'large', false, array( 'class' => 'mds-artist-fallback__image' ) )
			: '';

		$discography_html = $show_discog ? render_block(
			array(
				'blockName'    => 'mds-rm/artist-discography',
				'attrs'        => array( 'artistId' => $post_id ),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		) : '';

		$rendered = \MDS\ReleaseManager\Core\TemplateLoader::render(
			'templates/artist-fallback.php',
			array(
				'content'             => wpautop( $raw_content ),
				'image'               => $image_html,
				'discography'         => $discography_html,
				'discography_heading' => (string) ( $tpl['discography_heading'] ?? '' ),
				'contact'             => $show_contact ? $contact_html : '',
				'contact_heading'     => (string) ( $tpl['contact_heading'] ?? __( 'Contact Information', 'mds-release-manager' ) ),
				'layout'              => (string) ( $tpl['layout'] ?? 'stacked' ),
				'section_order'       => (string) ( $tpl['section_order'] ?? 'discography_first' ),
				'style'               => \MDS\ReleaseManager\Frontend\TemplateDefaults::get_artist_style_attribute(),
			)
		);

		$css_urls = array(
			includes_url( 'css/dashicons.min.css' ),
			MDS_RM_URL . 'css/artist-fallback.css?ver=' . filemtime( MDS_RM_PATH . 'css/artist-fallback.css' ),
			MDS_RM_URL . 'blocks/artist-discography/style.css?ver=' . filemtime( MDS_RM_PATH . 'blocks/artist-discography/style.css' ),
		);

		$routing_notice = '';
		if ( $homepage_routing && \MDS\ReleaseManager\Frontend\ArtistRouting::valid_url( $homepage_url, $post_id ) ) {
			$routing_notice = sprintf(
				/* translators: %s: Active artist homepage redirect URL. */
				__( 'Homepage Redirect active: visitors will be redirected (302) to %s instead of seeing this fallback profile.', 'mds-release-manager' ),
				$homepage_url
			);
		}

		return rest_ensure_response(
			array(
				'title'          => $title,
				'html'           => $rendered,
				'css_urls'       => $css_urls,
				'routing_notice' => $routing_notice,
			)
		);
	}

	/**
	 * Immediately executes the configured cache purge strategy for testing/manual clearing.
	 *
	 * @since 1.1.0
	 * @return WP_REST_Response
	 */
	public static function purge_cache_api(): WP_REST_Response {
		$result = \MDS\ReleaseManager\Core\Cache::purge(
			'manual',
			array(
				'immediate' => true,
				'force'     => true,
			)
		);

		if ( ! empty( $result['purged'] ) ) {
			$message = sprintf(
				/* translators: %s: Comma-separated list of purged cache plugins. */
				__( 'Cache purged: %s.', 'mds-release-manager' ),
				implode( ', ', $result['purged'] )
			);
		} else {
			$message = __( 'No active cache plugin was purged (none detected or all set to Disabled).', 'mds-release-manager' );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'purged'  => $result['purged'],
				'skipped' => $result['skipped'],
				'message' => $message,
			)
		);
	}

	/**
	 * Saves network settings through the REST API.
	 *
	 * @since 0.9.0
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response The response indicating whether network settings were saved.
	 */
	public static function save_network_settings_api( $request ): WP_REST_Response {
		$params        = $request->get_body_params();
		$master_hub_id = isset( $params['mds_rm_master_blog_id'] )
			? absint( $params['mds_rm_master_blog_id'] )
			: (int) get_site_option( 'mds_rm_master_blog_id', get_main_site_id() );

		if ( isset( $params['mds_rm_master_blog_id'] ) ) {
			update_site_option( 'mds_rm_master_blog_id', $master_hub_id );
		}

		$raw_spokes = $params['mds_rm_spoke_sites'] ?? ( $params['spoke_sites'] ?? null );
		if ( is_array( $raw_spokes ) ) {
			$spoke_ids = array_values(
				array_diff(
					array_unique( array_filter( array_map( 'absint', $raw_spokes ) ) ),
					array( $master_hub_id )
				)
			);
			update_site_option( 'mds_rm_spoke_sites', $spoke_ids );
			delete_site_option( 'spoke_sites' );
		}

		if ( isset( $params['mds_rm_wipe_on_uninstall'] ) ) {
			update_site_option( 'mds_rm_wipe_on_uninstall', '1' );
		} else {
			update_site_option( 'mds_rm_wipe_on_uninstall', '0' );
		}

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Synchronizes release and artist data across the network (interactive step or background queue).
	 *
	 * @since 0.9.0
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error The response or error from the network sync.
	 */
	public static function sync_network_api( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$job_id = $request->get_param( 'job_id' );
		$clean  = is_string( $job_id ) ? sanitize_text_field( $job_id ) : '';
		$mode   = sanitize_key( (string) ( $request->get_param( 'mode' ) ?? 'step' ) );

		if ( 'async' === $mode ) {
			$queued = \MDS\ReleaseManager\Core\Sync\SyncQueue::enqueue( $clean );
			return rest_ensure_response(
				array_merge(
					\MDS\ReleaseManager\Core\Sync::get_network_sync_status(),
					$queued
				)
			);
		}

		$result = \MDS\ReleaseManager\Core\Sync::run_network_sync_batch( $clean );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Returns current network synchronization progress for background UI polling.
	 *
	 * @since 2.1.0
	 * @return WP_REST_Response Current sync status.
	 */
	public static function sync_network_status_api(): WP_REST_Response {
		return rest_ensure_response( \MDS\ReleaseManager\Core\Sync::get_network_sync_status() );
	}

	/**
	 * Saves the Artist-level default release routing policy and per-release routing overrides,
	 * then batch-broadcasts changed records to configured spoke sites.
	 *
	 * @since 1.1.0
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error Updated destination previews or an error.
	 */
	public static function save_artist_release_routing_api( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$artist_id = absint( $request->get_param( 'id' ) );
		$artist    = get_post( $artist_id );
		if ( ! $artist instanceof WP_Post || 'artist' !== $artist->post_type ) {
			return new WP_Error( 'mds_artist_not_found', __( 'Artist not found.', 'mds-release-manager' ), array( 'status' => 404 ) );
		}

		$artist_uuid = (string) get_post_meta( $artist_id, '_mds_artist_uuid', true );
		$json_body   = $request->get_json_params();
		$payload     = is_array( $json_body ) && ! empty( $json_body ) ? $json_body : $request->get_body_params();

		$sync_items = array();

		if ( isset( $payload['artist_releases_routing_mode'] ) ) {
			$artist_mode = \MDS\ReleaseManager\Frontend\ArtistRouting::sanitize_artist_release_mode( $payload['artist_releases_routing_mode'] );
			update_post_meta( $artist_id, 'artist_releases_routing_mode', $artist_mode );
			$sync_items[] = array(
				'id'        => $artist_id,
				'post_type' => 'artist',
			);
		}

		if ( isset( $payload['artist_homepage_url'] ) && is_string( $payload['artist_homepage_url'] ) ) {
			update_post_meta( $artist_id, 'artist_homepage_url', esc_url_raw( trim( $payload['artist_homepage_url'] ) ) );
		}

		$submitted_releases = isset( $payload['releases'] ) && is_array( $payload['releases'] ) ? $payload['releases'] : array();
		$updated_releases   = 0;
		$previews           = array();

		foreach ( $submitted_releases as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) ) {
				continue;
			}
			$release_id = absint( $row['id'] );
			$release    = get_post( $release_id );
			if ( ! $release instanceof WP_Post || 'release' !== $release->post_type || ! current_user_can( 'edit_post', $release_id ) ) {
				continue;
			}
			// Verify the release belongs to this Artist.
			if ( '' !== $artist_uuid && (string) get_post_meta( $release_id, 'artist_id', true ) !== $artist_uuid ) {
				continue;
			}

			$mode       = \MDS\ReleaseManager\Frontend\ArtistRouting::sanitize_release_mode( $row['mode'] ?? 'inherit' );
			$custom_url = isset( $row['custom_url'] ) && is_string( $row['custom_url'] ) ? esc_url_raw( trim( $row['custom_url'] ) ) : '';

			if ( 'inherit' === $mode ) {
				delete_post_meta( $release_id, 'release_routing_mode' );
				delete_post_meta( $release_id, 'release_routing_url' );
			} else {
				update_post_meta( $release_id, 'release_routing_mode', $mode );
				if ( 'custom_url' === $mode && '' !== $custom_url ) {
					update_post_meta( $release_id, 'release_routing_url', $custom_url );
				} else {
					delete_post_meta( $release_id, 'release_routing_url' );
				}
			}

			$sync_items[] = array(
				'id'        => $release_id,
				'post_type' => 'release',
			);
			++$updated_releases;
			$previews[ (string) $release_id ] = \MDS\ReleaseManager\Frontend\ArtistRouting::describe_release_destination( $release_id, $artist_id );
		}

		$sync_errors = array();
		if ( $sync_items ) {
			$sync_results = \MDS\ReleaseManager\Core\Sync::broadcast_batch_to_spokes( $sync_items );
			foreach ( $sync_results as $item_key => $item_result ) {
				if ( is_wp_error( $item_result ) ) {
					$sync_errors[] = $item_key . ': ' . $item_result->get_error_message();
				}
			}
		}

		\MDS\ReleaseManager\Core\Sync::purge_cache();

		return rest_ensure_response(
			array(
				'success'          => empty( $sync_errors ),
				'updated_releases' => $updated_releases,
				'previews'         => $previews,
				'errors'           => $sync_errors,
				'message'          => empty( $sync_errors )
					? sprintf(
						/* translators: %d: Number of updated releases. */
						__( 'Routing saved and synchronized (%d releases).', 'mds-release-manager' ),
						$updated_releases
					)
					: __( 'Routing saved, but some spoke synchronizations reported errors.', 'mds-release-manager' ),
			)
		);
	}

	/**
	 * Resolves a normalized audio preview manifest for a release (Just-in-Time endpoint).
	 *
	 * @since 1.2.0
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error Normalized manifest response or 404 error.
	 */
	public static function get_release_audio_api( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$release_id = absint( $request->get_param( 'id' ) );
		$provider   = sanitize_key( (string) $request->get_param( 'provider' ) );
		$fresh      = (bool) $request->get_param( 'fresh' ) && current_user_can( 'edit_post', $release_id );

		$manifest = \MDS\ReleaseManager\Core\Audio\AudioApi::resolve(
			$release_id,
			array(
				'provider' => $provider,
				'fresh'    => $fresh,
			)
		);

		if ( null === $manifest ) {
			return new WP_Error(
				'mds_rm_audio_unavailable',
				__( 'No audio preview is available for this release.', 'mds-release-manager' ),
				array( 'status' => 404 )
			);
		}

		$response = new WP_REST_Response( $manifest, 200 );
		if ( 'publish' === get_post_status( $release_id ) && ! $fresh ) {
			$remaining = max( 0, (int) ( $manifest['expiresAt'] ?? 0 ) - time() );
			$max_age   = min( 300, $remaining );
			if ( $max_age > 0 ) {
				$response->header( 'Cache-Control', 'public, max-age=' . $max_age );
			} else {
				$response->header( 'Cache-Control', 'no-store' );
			}
		} else {
			$response->header( 'Cache-Control', 'no-store' );
		}

		return $response;
	}

	/**
	 * Returns serializable metadata for all registered audio preview providers.
	 *
	 * @since 1.2.0
	 *
	 * @return WP_REST_Response Provider list response.
	 */
	public static function get_audio_providers_api(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'providers' => \MDS\ReleaseManager\Core\Audio\AudioApi::get_provider_descriptors(),
			),
			200
		);
	}
}

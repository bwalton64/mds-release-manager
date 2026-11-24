<?php
/**
 * REST API Endpoints
 *
 * @package MDS_Release_Manager
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin's REST API endpoints.
 *
 * @since 0.9.0
 */
final class MDS_RM_REST_API {

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
			'/artists/(?P<id>\d+)/release-routing',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'save_artist_release_routing_api' ),
				'permission_callback' => static function ( WP_REST_Request $request ): bool {
					$artist_id = absint( $request->get_param( 'id' ) );
					return $artist_id > 0 && MDS_RM_Sync::is_hub() && current_user_can( 'edit_post', $artist_id );
				},
			)
		);
	}

	/**
	 * Gets releases through the REST API.
	 *
	 * @since 0.9.0
	 * @return WP_REST_Response The response containing the releases.
	 */
	public static function get_releases_api( WP_REST_Request $request ): WP_REST_Response {
		$page     = $request->get_param( 'page' );
		$per_page = $request->get_param( 'per_page' );
		$query = new WP_Query(
			array(
				'post_type'      => 'release',
				'post_status'    => 'publish',
				'posts_per_page' => min( 100, max( 1, null === $per_page ? 100 : absint( $per_page ) ) ),
				'paged'          => max( 1, null === $page ? 1 : absint( $page ) ),
			)
		);
		$data  = array();

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
				'meta_query'     => array(
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
			$valid          = MDS_RM_Block_Defaults::validate( $block_defaults );
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
			$valid_tpl = MDS_RM_Template_Defaults::validate( $raw_tpl );
			if ( is_wp_error( $valid_tpl ) ) {
				return $valid_tpl;
			}
			$template_defaults = $valid_tpl;
		}

		if ( array_key_exists( 'mds_rm_providers', $params ) && ! is_array( $params['mds_rm_providers'] ) ) {
			return new WP_Error( 'mds_rm_invalid_providers', __( 'The streaming providers are invalid.', 'mds-release-manager' ), array( 'status' => 400 ) );
		}

		$audio_player_settings = null;
		if ( isset( $params['mds_rm_audio_player_settings'] ) ) {
			$raw_audio_settings = $params['mds_rm_audio_player_settings'];
			if ( is_string( $raw_audio_settings ) ) {
				if ( strlen( $raw_audio_settings ) > 65536 ) {
					return new WP_Error( 'mds_rm_invalid_audio_player_settings', __( 'The audio player settings are invalid.', 'mds-release-manager' ), array( 'status' => 400 ) );
				}
				$raw_audio_settings = json_decode( wp_unslash( $raw_audio_settings ), true );
			}
			$validated_audio_settings = MDS_RM_Audio_Players::validate_settings( $raw_audio_settings );
			if ( is_wp_error( $validated_audio_settings ) ) {
				return $validated_audio_settings;
			}
			$audio_player_settings = $validated_audio_settings;
		}

		$cache_settings = null;
		if ( isset( $params['mds_rm_cache_present'] ) || isset( $params['mds_rm_cache_plugins'] ) || isset( $params['mds_rm_cache_purge_on_post_save'] ) ) {
			$existing_cache = class_exists( 'MDS_RM_Cache' ) ? MDS_RM_Cache::get_settings() : array( 'plugins' => array() );
			$raw_plugins    = isset( $params['mds_rm_cache_plugins'] ) && is_array( $params['mds_rm_cache_plugins'] )
				? array_merge( $existing_cache['plugins'] ?? array(), wp_unslash( $params['mds_rm_cache_plugins'] ) )
				: ( $existing_cache['plugins'] ?? array() );
			$validated_cache = MDS_RM_Cache::validate_settings(
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
			$names  = isset( $params['mds_rm_providers']['name'] ) ? (array) $params['mds_rm_providers']['name'] : array();
			$icons  = isset( $params['mds_rm_providers']['icon'] ) ? (array) $params['mds_rm_providers']['icon'] : array();
			$colors = isset( $params['mds_rm_providers']['color'] ) ? (array) $params['mds_rm_providers']['color'] : array();

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

		$players = array();
		if ( isset( $params['mds_rm_audio_players'] ) && is_array( $params['mds_rm_audio_players'] ) ) {
			$players = MDS_RM_Audio_Players::sanitize_active_players( wp_unslash( $params['mds_rm_audio_players'] ) );
			update_option( 'mds_rm_audio_players', $players );
		} else {
			update_option( 'mds_rm_audio_players', array() );
		}

		if ( null !== $audio_player_settings ) {
			update_option( 'mds_rm_audio_player_settings', $audio_player_settings );
		}

		if ( isset( $params['mds_rm_fa_support'] ) ) {
			update_option( 'mds_rm_fa_support', MDS_RM_Assets::sanitize_font_awesome_source( wp_unslash( $params['mds_rm_fa_support'] ) ) );
		}

		if ( null !== $block_defaults ) {
			update_option( 'mds_rm_block_defaults', $block_defaults );
		}

		if ( null !== $template_defaults ) {
			update_option( MDS_RM_Template_Defaults::OPTION_NAME, $template_defaults );
		}

		if ( null !== $cache_settings ) {
			update_option( MDS_RM_Cache::OPTION_NAME, $cache_settings );
		}

		if ( function_exists( 'do_action' ) ) {
			/**
			 * Fires after Audio Player Manager settings are saved.
			 *
			 * @since 1.1.0
			 * @param string $active_player         Active audio player slug, or empty string.
			 * @param array  $audio_player_settings Validated per-player settings array.
			 * @param array  $params                Raw request parameters.
			 */
			do_action( 'mds_rm_save_audio_player_settings', $players[0] ?? '', $audio_player_settings ?? array(), $params );
		}

		MDS_RM_Sync::purge_cache( 'settings' );

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Immediately executes the configured cache purge strategy for testing/manual clearing.
	 *
	 * @since 1.1.0
	 * @return WP_REST_Response
	 */
	public static function purge_cache_api(): WP_REST_Response {
		$result = MDS_RM_Cache::purge(
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
		$params = $request->get_body_params();

		if ( isset( $params['mds_rm_master_blog_id'] ) ) {
			update_site_option( 'mds_rm_master_blog_id', absint( $params['mds_rm_master_blog_id'] ) );
		}

		if ( isset( $params['mds_rm_wipe_on_uninstall'] ) ) {
			update_site_option( 'mds_rm_wipe_on_uninstall', '1' );
		} else {
			update_site_option( 'mds_rm_wipe_on_uninstall', '0' );
		}

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Synchronizes release and artist data across the network.
	 *
	 * @since 0.9.0
	 * @return WP_REST_Response|WP_Error The response or error from the network sync.
	 */
	public static function sync_network_api( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$job_id = $request->get_param( 'job_id' );
		$result = MDS_RM_Sync::run_network_sync_batch( is_string( $job_id ) ? sanitize_text_field( $job_id ) : '' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
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
			$artist_mode = MDS_RM_Artist_Routing::sanitize_artist_release_mode( $payload['artist_releases_routing_mode'] );
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

			$mode       = MDS_RM_Artist_Routing::sanitize_release_mode( $row['mode'] ?? 'inherit' );
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
			$previews[ (string) $release_id ] = MDS_RM_Artist_Routing::describe_release_destination( $release_id, $artist_id );
		}

		$sync_errors = array();
		if ( $sync_items ) {
			$sync_results = MDS_RM_Sync::broadcast_batch_to_spokes( $sync_items );
			foreach ( $sync_results as $item_key => $item_result ) {
				if ( is_wp_error( $item_result ) ) {
					$sync_errors[] = $item_key . ': ' . $item_result->get_error_message();
				}
			}
		}

		MDS_RM_Sync::purge_cache();

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
}

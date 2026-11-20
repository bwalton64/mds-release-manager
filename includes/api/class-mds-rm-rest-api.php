<?php
declare(strict_types=1);

/**
 * REST API Endpoints
 *
 * @package MDS_Release_Manager
 */

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
	}

	/**
	 * Gets releases through the REST API.
	 *
	 * @since 0.9.0
	 * @return WP_REST_Response The response containing the releases.
	 */
	public static function get_releases_api(): WP_REST_Response {
		$args     = array(
			'post_type'      => 'release',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
		);
		$releases = get_posts( $args );
		$data     = array();

		foreach ( $releases as $rel ) {
			$data[] = array(
				'id'    => $rel->ID,
				'title' => $rel->post_title,
				'upc'   => get_post_meta( $rel->ID, 'upc_ean', true ),
			);
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Saves plugin settings through the REST API.
	 *
	 * @since 0.9.0
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response The response indicating whether settings were saved.
	 */
	public static function save_settings_api( $request ): WP_REST_Response {
		$params = $request->get_body_params();

		$providers = array();
		if ( isset( $params['mds_rm_providers'] ) && is_array( $params['mds_rm_providers'] ) ) {
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
		}
		update_option( 'mds_rm_providers', $providers );

		if ( isset( $params['mds_rm_audio_players'] ) && is_array( $params['mds_rm_audio_players'] ) ) {
			$players = array_map( 'sanitize_text_field', wp_unslash( $params['mds_rm_audio_players'] ) );
			update_option( 'mds_rm_audio_players', $players );
		} else {
			update_option( 'mds_rm_audio_players', array() );
		}

		if ( isset( $params['mds_rm_global_redirect'] ) ) {
			update_option( 'mds_rm_global_redirect', '1' );
		} else {
			update_option( 'mds_rm_global_redirect', '0' );
		}

		if ( isset( $params['mds_rm_fa_support'] ) ) {
			update_option( 'mds_rm_fa_support', sanitize_text_field( wp_unslash( $params['mds_rm_fa_support'] ) ) );
		}

		MDS_RM_Sync::purge_cache();

		return rest_ensure_response( array( 'success' => true ) );
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
	public static function sync_network_api(): WP_REST_Response|WP_Error {
		if ( get_current_blog_id() !== (int) get_site_option( 'mds_rm_master_blog_id', get_main_site_id() ) ) {
			return new WP_Error( 'not_master', 'Must run sync from the master hub site.', array( 'status' => 403 ) );
		}

		$artists      = get_posts(
			array(
				'post_type'   => 'artist',
				'numberposts' => -1,
				'post_status' => 'any',
			)
		);
		$artist_count = 0;
		$errors       = array();
		foreach ( $artists as $a ) {
			$result = MDS_RM_Sync::broadcast_to_spokes( $a->ID, 'artist' );
			if ( is_wp_error( $result ) ) {
				$errors[] = sprintf( 'Artist %1$d: %2$s', $a->ID, $result->get_error_message() );
				continue;
			}
			++$artist_count;
		}

		$releases      = get_posts(
			array(
				'post_type'   => 'release',
				'numberposts' => -1,
				'post_status' => 'any',
			)
		);
		$release_count = 0;
		foreach ( $releases as $r ) {
			$result = MDS_RM_Sync::broadcast_to_spokes( $r->ID, 'release' );
			if ( is_wp_error( $result ) ) {
				$errors[] = sprintf( 'Release %1$d: %2$s', $r->ID, $result->get_error_message() );
				continue;
			}
			++$release_count;
		}

		MDS_RM_Sync::purge_cache();
		if ( $errors ) {
			return new WP_Error( 'mds_sync_failed', implode( ' ', $errors ), array( 'status' => 500 ) );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => sprintf( 'Successfully synced %d releases and %d artists to spoke sites.', $release_count, $artist_count ),
			)
		);
	}
}

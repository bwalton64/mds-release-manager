<?php
/**
 * REST API Endpoints
 *
 * @package MDS_Release_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', 'mds_rm_register_rest_routes' );

/**
 * Register REST Routes
 */
function mds_rm_register_rest_routes() {
    register_rest_route( 'mds/v1', '/releases', array(
        'methods'             => 'GET',
        'callback'            => 'mds_rm_get_releases_api',
        'permission_callback' => '__return_true',
    ) );
    
    register_rest_route( 'mds/v1', '/settings', array(
        'methods'             => 'POST',
        'callback'            => 'mds_rm_save_settings_api',
        'permission_callback' => function () {
            return current_user_can( 'manage_options' );
        },
    ) );
    
    register_rest_route( 'mds/v1', '/network-settings', array(
        'methods'             => 'POST',
        'callback'            => 'mds_rm_save_network_settings_api',
        'permission_callback' => function () {
            return current_user_can( 'manage_network_options' );
        },
    ) );
    
    register_rest_route( 'mds/v1', '/sync-network', array(
        'methods'             => 'POST',
        'callback'            => 'mds_rm_sync_network_api',
        'permission_callback' => function () {
            return current_user_can( 'manage_network_options' );
        },
    ) );
}

/**
 * Get Releases API
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response
 */
function mds_rm_get_releases_api( $request ) {
    $args = array(
        'post_type'      => 'release',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
    );
    $releases = get_posts( $args );
    $data = array();
    
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
 * Save Settings API
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response
 */
function mds_rm_save_settings_api( $request ) {
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

    mds_rm_purge_cache();

    return rest_ensure_response( array( 'success' => true ) );
}

/**
 * Save Network Settings API
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response
 */
function mds_rm_save_network_settings_api( $request ) {
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
 * Sync Network API
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error
 */
function mds_rm_sync_network_api( $request ) {
    if ( get_current_blog_id() !== (int) get_site_option( 'mds_rm_master_blog_id', get_main_site_id() ) ) {
        return new WP_Error( 'not_master', 'Must run sync from the master hub site.', array( 'status' => 403 ) );
    }
    
    $artists = get_posts( array(
        'post_type'   => 'artist',
        'numberposts' => -1,
        'post_status' => 'any',
    ) );
    $artist_count = 0;
    foreach ( $artists as $a ) {
        mds_rm_broadcast_to_spokes( $a->ID, 'artist' );
        $artist_count++;
    }
    
    $releases = get_posts( array(
        'post_type'   => 'release',
        'numberposts' => -1,
        'post_status' => 'any',
    ) );
    $release_count = 0;
    foreach ( $releases as $r ) {
        mds_rm_broadcast_to_spokes( $r->ID, 'release' );
        $release_count++;
    }
    
    mds_rm_purge_cache();
    
    return rest_ensure_response( array(
        'success' => true,
        'message' => sprintf( 'Successfully synced %d releases and %d artists to spoke sites.', $release_count, $artist_count ),
    ) );
}

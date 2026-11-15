<?php
/**
 * Plugin Name: MDS Release Manager
 * Description: Manages the "Releases" and "Artist" CPTs, uses global custom tables as the source of truth, exposes CRUD REST APIs, integrates with WooCommerce, and provides frontend widgets via shortcodes and Elementor.
 * Version: 0.9.0
 * Author: Brian Walton for Musty Dungeon Studios
 * Network: true
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MDS_RELEASE_MANAGER_VERSION', '0.8.0' );
define( 'MDS_RM_PATH', plugin_dir_path( __FILE__ ) );
define( 'MDS_RM_URL', plugin_dir_url( __FILE__ ) );
define( 'MDS_RM_MAIN_FILE', __FILE__ );

require_once MDS_RM_PATH . 'includes/core-functions.php';
require_once MDS_RM_PATH . 'includes/post-types.php';
require_once MDS_RM_PATH . 'includes/admin-interface.php';
require_once MDS_RM_PATH . 'includes/network-admin.php';
require_once MDS_RM_PATH . 'includes/customizer.php';
require_once MDS_RM_PATH . 'includes/frontend-templates.php';
require_once MDS_RM_PATH . 'includes/rest-api.php';
require_once MDS_RM_PATH . 'includes/shortcodes.php';
require_once MDS_RM_PATH . 'includes/integrations.php';
require_once MDS_RM_PATH . 'includes/elementor-tags.php';
require_once MDS_RM_PATH . 'includes/csv-handler.php';
require_once MDS_RM_PATH . 'includes/gutenberg-blocks.php';
require_once MDS_RM_PATH . 'includes/woocommerce.php';
require_once MDS_RM_PATH . 'includes/virtual-media.php';

// --- 3. Enqueue Assets & Global Redirect ---
add_action( 'wp_enqueue_scripts', 'mds_release_manager_enqueue_assets' );
function mds_release_manager_enqueue_assets() {
    $fa_support = get_option( 'mds_rm_fa_support', array( 'internal' ) );
    if ( in_array( 'internal', (array) $fa_support, true ) ) {
        wp_enqueue_style( 'mds-font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css', array(), '6.4.0' );
    }
    mds_rm_enqueue_editable_style( 'mds-release-links-css', 'css/frontend.css' );
}

add_action( 'template_redirect', 'mds_rm_handle_global_redirect' );
function mds_rm_handle_global_redirect() {
    if ( is_admin() || ! is_singular( 'release' ) ) {
        return;
    }
    if ( get_option( 'mds_rm_global_redirect', '0' ) !== '1' ) {
        return;
    }

    $post_id = get_queried_object_id();
    if ( ! $post_id ) {
        return;
    }

    $req = new WP_REST_Request( 'GET', '/mds/v1/releases/post/' . $post_id );
    $req->set_param( 'blog_id', get_current_blog_id() );
    $res = rest_do_request( $req );
    $release = $res->is_error() ? array() : $res->get_data();

    if ( ! empty( $release['buy_now_link'] ) ) {
        $target_url  = esc_url_raw( $release['buy_now_link'] );
        $current_url = home_url( add_query_arg( array(), $GLOBALS['wp']->request ?? '' ) );
        if ( ! empty( $target_url ) && untrailingslashit( $target_url ) !== untrailingslashit( $current_url ) ) {
            wp_redirect( $target_url, 302 );
            exit;
        }
    }
}

add_action( 'admin_enqueue_scripts', 'mds_rm_admin_assets' );
function mds_rm_admin_assets( $hook ) {
    $screen = get_current_screen();

    if ( $screen && ( $screen->post_type === 'release' || $screen->post_type === 'artist' ) ) {
        wp_enqueue_media();
        
        // Dependency Fix: Hub likely doesn't have WooCommerce active, so 'selectWoo' doesn't exist in Core.
        // We gracefully fallback to enqueueing Select2 from CDN if missing, to ensure our JS actually loads!
        if ( ! wp_script_is( 'selectWoo', 'registered' ) && ! wp_script_is( 'select2', 'registered' ) ) {
            wp_enqueue_script( 'mds-select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js', array('jquery'), '4.0.13', true );
            wp_enqueue_style( 'mds-select2-css', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css', array(), '4.0.13' );
            $select_handle = 'mds-select2';
        } else {
            $select_handle = wp_script_is( 'selectWoo', 'registered' ) ? 'selectWoo' : 'select2';
            wp_enqueue_script( $select_handle );
            wp_enqueue_style( $select_handle );
        }

        mds_rm_enqueue_editable_style( 'mds-admin-styles', 'css/admin-styles.css' );
        mds_rm_enqueue_editable_script( 'mds-meta-boxes-js', 'js/meta-boxes.js', array( 'jquery', $select_handle ), true );
        
        wp_localize_script( 'mds-meta-boxes-js', 'mds_rm_data', array(
            'rest_url'       => esc_url_raw( rest_url( 'mds/v1/releases' ) ), // legacy
            'wc_search_url'  => esc_url_raw( rest_url( 'mds/v1/wc-products' ) ),
            'wc_generate_url'=> esc_url_raw( rest_url( 'mds/v1/wc-generate' ) ),
            'nonce'          => wp_create_nonce( 'wp_rest' ),
        ) );
    }
}

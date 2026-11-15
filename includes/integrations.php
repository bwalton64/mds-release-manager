<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// --- 10. Inject into WooCommerce via Templates ---
add_action( 'woocommerce_single_product_summary', 'mds_inject_release_links_by_sku', 25 );
function mds_inject_release_links_by_sku() {
    if ( is_multisite() ) {
        $woo_sites = array_map( 'intval', (array) get_site_option( 'mds_rm_woo_sites', get_option( 'mds_rm_woo_sites', array() ) ) );
        if ( empty( $woo_sites ) || ! in_array( (int) get_current_blog_id(), $woo_sites, true ) ) {
            return;
        }
    }

    global $product;
    if ( ! is_a( $product, 'WC_Product' ) && function_exists( 'wc_get_product' ) ) {
        $product = wc_get_product( get_the_ID() );
    }
    if ( is_a( $product, 'WC_Product' ) ) {
        $sku = $product->get_sku();
        $sku_numeric = preg_replace( '/[^0-9]/', '', (string) $sku );
        if ( ! empty( $sku_numeric ) ) {
            echo mds_render_meta_bar_html( $sku_numeric, true );
        }
    }
}

function mds_rm_detect_plugins() {
    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $all_plugins = get_plugins();
    $data = array( 'fa_plugins' => array(), 'audio_plugins' => array(), 'bandcamp_detected' => false, 'official_fa_path' => '' );
    foreach ( $all_plugins as $path => $plugin ) {
        if ( ! is_plugin_active( $path ) && ! is_plugin_active_for_network( $path ) ) {
            continue;
        }
        if ( stripos( $plugin['Name'], 'font-awesome' ) !== false || stripos( $plugin['Name'], 'fontawesome' ) !== false || stripos( $plugin['Name'], 'Font Awesome' ) !== false ) {
            $data['fa_plugins'][ $path ] = $plugin;
            if ( stripos( $plugin['Name'], 'Font Awesome' ) !== false ) {
                $data['official_fa_path'] = $path;
            }
        }
        if ( stripos( $plugin['Name'], 'audio' ) !== false || stripos( $plugin['Name'], 'player' ) !== false ) {
            $data['audio_plugins'][ $path ] = $plugin;
        }
        if ( stripos( $plugin['Name'], 'blocks for bandcamp' ) !== false ) {
            $data['bandcamp_detected'] = true;
        }
    }
    return $data;
}

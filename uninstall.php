<?php
/**
 * Fired when the plugin is uninstalled.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    die;
}

$wipe_all = get_site_option( 'mds_rm_wipe_on_uninstall', '0' ) === '1';

// We clear out localized plugin settings to clean up the options table.
$options = array(
    'mds_rm_fa_support', 'mds_rm_widget_bg_color', 'mds_rm_shortcode_gap',
    'mds_rm_shortcode_align', 'mds_rm_links_icon_bg', 'mds_rm_links_color',
    'mds_rm_links_bg', 'mds_rm_links_radius', 'mds_rm_links_gap',
    'mds_rm_links_padding', 'mds_rm_links_align', 'mds_rm_buy_now_icon',
    'mds_rm_buy_now_color', 'mds_rm_buy_now_bg', 'mds_rm_meta_color',
    'mds_rm_meta_bg', 'mds_rm_meta_radius', 'mds_rm_meta_gap',
    'mds_rm_meta_padding', 'mds_rm_meta_align', 'mds_rm_woo_meta_bg',
    'mds_rm_woo_meta_color', 'mds_rm_woo_meta_gap', 'mds_rm_woo_meta_align',
    'mds_rm_audio_players', 'mds_rm_bandcamp_template', 'mds_rm_providers',
    'mds_rm_global_redirect'
);

foreach ( $options as $opt ) {
    delete_option( $opt );
}

if ( $wipe_all ) {
    // Delete Network Options
    delete_site_option( 'mds_rm_woo_sites' );
    delete_site_option( 'mds_rm_master_blog_id' );
    delete_site_option( 'mds_rm_db_templates' );
    delete_site_option( 'mds_rm_wipe_on_uninstall' );

    // Delete all CPTs network-wide
    if ( is_multisite() ) {
        $sites = function_exists('get_sites') ? get_sites() : array();
        foreach ( $sites as $site ) {
            switch_to_blog( $site->blog_id );
            $posts = get_posts( array( 'post_type' => array( 'release', 'artist' ), 'numberposts' => -1, 'post_status' => 'any' ) );
            foreach ( $posts as $p ) {
                wp_delete_post( $p->ID, true );
            }
            restore_current_blog();
        }
    } else {
        $posts = get_posts( array( 'post_type' => array( 'release', 'artist' ), 'numberposts' => -1, 'post_status' => 'any' ) );
        foreach ( $posts as $p ) {
            wp_delete_post( $p->ID, true );
        }
    }
}

<?php
/**
 * Fired when the plugin is uninstalled.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    die;
}

// NOTE: Destructive operations (DROP TABLE, wp_delete_post) have been permanently 
// removed from this file. WordPress automatically executes uninstall.php when a user 
// deletes the plugin from the WP Admin dashboard to install an update.
// Leaving data intact prevents catastrophic data loss during manual plugin updates.

// We only clear out localized plugin settings to clean up the options table.
$options = [
    'mds_rm_fa_support', 'mds_rm_widget_bg_color', 'mds_rm_shortcode_gap', 
    'mds_rm_shortcode_align', 'mds_rm_links_icon_bg', 'mds_rm_links_color', 
    'mds_rm_links_bg', 'mds_rm_links_radius', 'mds_rm_links_gap', 
    'mds_rm_links_padding', 'mds_rm_links_align', 'mds_rm_buy_now_icon', 
    'mds_rm_buy_now_color', 'mds_rm_buy_now_bg', 'mds_rm_meta_color', 
    'mds_rm_meta_bg', 'mds_rm_meta_radius', 'mds_rm_meta_gap', 
    'mds_rm_meta_padding', 'mds_rm_meta_align', 'mds_rm_woo_meta_bg', 
    'mds_rm_woo_meta_color', 'mds_rm_woo_meta_gap', 'mds_rm_woo_meta_align', 
    'mds_rm_audio_players', 'mds_rm_bandcamp_template', 'mds_rm_providers',
    'mds_rm_global_redirect', 'mds_rm_woo_sites'
];

foreach($options as $opt) {
    delete_option($opt);
    if ( is_multisite() ) {
        delete_site_option($opt);
    }
}

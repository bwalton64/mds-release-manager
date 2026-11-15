<?php
/**
 * Network Admin Settings
 *
 * @package MDS_Release_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'network_admin_menu', 'mds_rm_network_menu' );

/**
 * Register Network Menu
 */
function mds_rm_network_menu() {
    add_submenu_page(
        'settings.php',
        'MDS Release Manager',
        'MDS Release Manager',
        'manage_network_options',
        'mds-release-network-settings',
        'mds_rm_network_settings_page'
    );
}

/**
 * Render Network Settings Page
 */
function mds_rm_network_settings_page() {
    $master_hub_id = (int) get_site_option( 'mds_rm_master_blog_id', get_main_site_id() );
    $sites = function_exists( 'get_sites' ) ? get_sites() : array();
    
    $master_hub_html = '<select name="mds_rm_master_blog_id">';
    foreach ( $sites as $site ) {
        $master_hub_html .= sprintf(
            '<option value="%s" %s>Site #%s - %s</option>',
            esc_attr( $site->blog_id ),
            selected( $master_hub_id, (int) $site->blog_id, false ),
            esc_html( $site->blog_id ),
            esc_html( get_blog_details( $site->blog_id )->blogname )
        );
    }
    $master_hub_html .= '</select>';

    /**
     * Hook: mds_rm_network_settings_fields
     * Allows feature plugins to inject HTML rows into the network settings page.
     */
    $custom_fields = apply_filters( 'mds_rm_network_settings_fields', '' );

    $tokens = array(
        'master_hub_html'   => $master_hub_html,
        'wipe_on_uninstall' => checked( get_site_option( 'mds_rm_wipe_on_uninstall', '0' ), '1', false ),
        'custom_fields'     => $custom_fields,
    );
    
    echo mds_render_template( 'templates/network-settings-page.html', $tokens ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

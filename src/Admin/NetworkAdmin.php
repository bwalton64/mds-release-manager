<?php
/**
 * Network Admin Settings
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encapsulates network settings registration, rendering, and persistence.
 *
 * @since 0.9.0
 */
final class NetworkAdmin {

	/**
	 * Registers the network menu and settings-save hooks.
	 *
	 * @since 0.9.0
	 */
	public static function register_hooks(): void {
		add_action( 'network_admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'network_admin_edit_mds_rm_save_network_settings', array( self::class, 'save_settings' ) );
	}

	/**
	 * Registers the network settings page.
	 *
	 * @since 0.9.0
	 */
	public static function register_menu(): void {
		add_submenu_page(
			'settings.php',
			'MDS Release Manager',
			'MDS Release Manager',
			'manage_network_options',
			'mds-release-network-settings',
			array( self::class, 'render_settings_page' )
		);
	}

	/**
	 * Renders the network admin settings page.
	 *
	 * @since 0.9.0
	 */
	public static function render_settings_page(): void {
		$master_hub_id = (int) get_site_option( 'mds_rm_master_blog_id', get_main_site_id() );
		$sites         = function_exists( 'get_sites' ) ? get_sites() : array();
		$args          = self::get_settings_template_args( $sites, $master_hub_id );

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The template escapes values and renders trusted extension markup.
		echo \MDS\ReleaseManager\Core\TemplateLoader::render_admin_view( 'network-settings-page.php', $args );
	}

	/**
	 * Save submitted network settings and redirect back to the settings page.
	 *
	 * @since 0.9.0
	 */
	public static function save_settings(): void {
		self::verify_save_permission();
		check_admin_referer( 'mds_rm_save_network_settings' );

		$master_hub_id  = self::get_posted_master_hub_id();
		$spoke_site_ids = self::get_posted_spoke_site_ids( $master_hub_id );
		self::persist_settings( $master_hub_id, $spoke_site_ids );
		self::redirect_after_save();
	}

	/**
	 * Prepare data passed to the settings template.
	 *
	 * @since 0.9.0
	 * @param array $sites       The network sites.
	 * @param int   $master_hub_id The master hub site ID.
	 * @return array Template arguments.
	 */
	private static function get_settings_template_args( array $sites, int $master_hub_id ): array {
		$stored_spokes = get_site_option( 'mds_rm_spoke_sites', null );
		if ( null === $stored_spokes ) {
			$stored_spokes = get_site_option( 'spoke_sites', array() );
		}
		$spoke_site_ids = array_map( 'intval', (array) $stored_spokes );
		$site_data      = array();

		foreach ( $sites as $site ) {
			$site_id      = (int) $site->blog_id;
			$site_details = get_blog_details( $site_id );
			$site_data[]  = array(
				'site_id'   => $site_id,
				'site_name' => $site_details ? $site_details->blogname : 'Site #' . $site_id,
				'site_url'  => $site->domain . $site->path,
			);
		}

		return array(
			'form_action'       => network_admin_url( 'edit.php?action=mds_rm_save_network_settings' ),
			'nonce_field'       => wp_nonce_field( 'mds_rm_save_network_settings', '_wpnonce', false, false ),
			'settings_saved'    => self::is_settings_saved(),
			'sites'             => $site_data,
			'master_hub_id'     => $master_hub_id,
			'spoke_site_ids'    => $spoke_site_ids,
			'wipe_on_uninstall' => '1' === (string) get_site_option( 'mds_rm_wipe_on_uninstall', '0' ),
			'extension_tabs'    => self::extension_tabs(),
		);
	}

	/**
	 * Returns extension-provided network settings tabs.
	 *
	 * @return array Valid tab definitions.
	 */
	public static function extension_tabs(): array {
		return AdminInterface::extension_tabs( 'mds_rm_network_settings_tabs', array( 'network' ) );
	}

	/**
	 * Checks whether the settings page should show its success notice.
	 *
	 * @since 0.9.0
	 * @return bool Whether the settings were just saved.
	 */
	private static function is_settings_saved(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This query flag only displays a notice; the save handler verifies its own nonce.
		if ( ! isset( $_GET['updated'] ) || 'true' !== sanitize_text_field( wp_unslash( $_GET['updated'] ) ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Ensures the current user can update network settings.
	 *
	 * @since 0.9.0
	 */
	private static function verify_save_permission(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage network settings.', 'mds-release-manager' ) );
		}
	}

	/**
	 * Gets the submitted master hub site ID.
	 *
	 * @since 0.9.0
	 * @return int The master hub site ID.
	 */
	private static function get_posted_master_hub_id(): int {
		check_admin_referer( 'mds_rm_save_network_settings' );

		if ( ! isset( $_POST['mds_rm_master_blog_id'] ) ) {
			return (int) get_site_option( 'mds_rm_master_blog_id', get_main_site_id() );
		}

		return absint( wp_unslash( $_POST['mds_rm_master_blog_id'] ) );
	}

	/**
	 * Get submitted spoke IDs, excluding the selected master hub.
	 *
	 * @since 0.9.0
	 * @param int $master_hub_id The master hub site ID.
	 * @return array Spoke site IDs.
	 */
	private static function get_posted_spoke_site_ids( int $master_hub_id ): array {
		check_admin_referer( 'mds_rm_save_network_settings' );

		if ( ! isset( $_POST['spoke_sites'] ) || ! is_array( $_POST['spoke_sites'] ) ) {
			return array();
		}

		$spoke_site_ids = array_map( 'absint', wp_unslash( $_POST['spoke_sites'] ) );
		$spoke_site_ids = array_unique( array_filter( $spoke_site_ids ) );

		return array_values( array_diff( $spoke_site_ids, array( $master_hub_id ) ) );
	}

	/**
	 * Persists the submitted network settings.
	 *
	 * @since 0.9.0
	 * @param int   $master_hub_id The master hub site ID.
	 * @param array $spoke_site_ids The spoke site IDs.
	 */
	private static function persist_settings( int $master_hub_id, array $spoke_site_ids ): void {
		check_admin_referer( 'mds_rm_save_network_settings' );

		update_site_option( 'mds_rm_master_blog_id', $master_hub_id );
		update_site_option( 'mds_rm_spoke_sites', $spoke_site_ids );
		delete_site_option( 'spoke_sites' );
		update_site_option(
			'mds_rm_wipe_on_uninstall',
			isset( $_POST['mds_rm_wipe_on_uninstall'] ) ? '1' : '0'
		);
	}

	/**
	 * Redirects to the network settings page after saving.
	 *
	 * @since 0.9.0
	 */
	private static function redirect_after_save(): void {
		wp_safe_redirect( network_admin_url( 'settings.php?page=mds-release-network-settings&updated=true' ) );
		exit;
	}
}

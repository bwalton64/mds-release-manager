<?php
/**
 * Release Manager bootstrap.
 *
 * @package MDS_Release_Manager
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin's lifecycle and runtime callbacks.
 */
final class MDS_RM_Plugin {

	/** Registers the plugin lifecycle and bootstrap hooks. */
	public static function register_hooks(): void {
		// During activation, WordPress includes the plugin after plugins_loaded.
		register_activation_hook( MDS_RM_MAIN_FILE, array( MDS_RM_Post_Types::class, 'activate' ) );
		register_deactivation_hook( MDS_RM_MAIN_FILE, array( MDS_RM_Post_Types::class, 'deactivate' ) );
		add_action( 'plugins_loaded', array( self::class, 'init' ), 0 );
	}

	/** Registers the hooks for each plugin component. */
	public static function init(): void {
		MDS_RM_Assets::register_hooks();
		MDS_RM_Post_Types::register_hooks();
		MDS_RM_Release_Model::register_hooks();
		MDS_RM_Admin_Interface::register_hooks();
		MDS_RM_Editor::register_hooks();
		MDS_RM_Network_Admin::register_hooks();
		MDS_RM_Frontend_Templates::register_hooks();
		MDS_RM_Artist_Routing::register_hooks();
		MDS_RM_REST_API::register_hooks();
		MDS_RM_Shortcodes::register_hooks();
		MDS_RM_Elementor_Tags::register_hooks();
		MDS_RM_CSV_Handler::register_hooks();
		MDS_RM_Gutenberg_Blocks::register_hooks();
		MDS_RM_Block_Defaults::register_hooks();
		MDS_RM_Virtual_Media::register_hooks();
		MDS_RM_Abilities::register_hooks();
	}
}

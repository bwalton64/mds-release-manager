<?php
/**
 * Plugin Name: MDS Release Manager
 * Description: Comprehensive Artist Release Manager.
 * Version: 1.0.0
 * Author: ©2026 Walton International Business Group
 * Network: true
 *
 * @package MDS_Release_Manager
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MDS_RELEASE_MANAGER_VERSION', '1.0.0' );
define( 'MDS_RM_PATH', plugin_dir_path( __FILE__ ) );
define( 'MDS_RM_URL', plugin_dir_url( __FILE__ ) );
define( 'MDS_RM_MAIN_FILE', __FILE__ );
spl_autoload_register(
	/**
	 * Loads plugin classes from the includes directory.
	 *
	 * @since 0.9.0
	 * @param string $class_name The requested class name.
	 */
	function ( $class_name ) {
		$prefix = 'MDS_RM_';
		if ( strpos( $class_name, $prefix ) !== 0 ) {
			return;
		}

		$class_file = MDS_RM_PATH . 'includes/class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';
		if ( is_readable( $class_file ) ) {
			require_once $class_file;
		}
	}
);
MDS_RM_Assets::register_hooks();
MDS_RM_Post_Types::register_hooks();
MDS_RM_Release_Model::register_hooks();
MDS_RM_Admin_Interface::register_hooks();
MDS_RM_Network_Admin::register_hooks();
MDS_RM_Customizer::register_hooks();
MDS_RM_Frontend_Templates::register_hooks();
MDS_RM_Artist_Routing::register_hooks();
MDS_RM_REST_API::register_hooks();
MDS_RM_Shortcodes::register_hooks();
MDS_RM_Elementor_Tags::register_hooks();
MDS_RM_CSV_Handler::register_hooks();
MDS_RM_Gutenberg_Blocks::register_hooks();
MDS_RM_Virtual_Media::register_hooks();
MDS_RM_Abilities::register_hooks();

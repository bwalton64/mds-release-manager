<?php
/**
 * Plugin Name: MDS Release Manager
 * Description: Comprehensive Artist Release Manager.
 * Version: 1.0.0
 * Requires at least: 6.9
 * Requires PHP: 8.0
 * Author: Walton International Business Group
 * Author URI: https://mustydungeon.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: mds-release-manager
 * Domain Path: /languages
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
	 * Loads plugin classes from their functional folders or legacy flat paths.
	 *
	 * @since 0.9.0
	 * @param string $class_name The requested class name.
	 */
	function ( $class_name ) {
		$prefix = 'MDS_RM_';
		if ( strpos( $class_name, $prefix ) !== 0 ) {
			return;
		}

		static $class_paths = array(
			'MDS_RM_PLUGIN'                     => 'class-mds-rm-plugin.php',
			'MDS_RM_POST_TYPES'                 => 'core/class-mds-rm-post-types.php',
			'MDS_RM_RELEASE_MODEL'              => 'core/class-mds-rm-release-model.php',
			'MDS_RM_BUILDER_DETECTION'          => 'core/class-mds-rm-builder-detection.php',
			'MDS_RM_LOGGER'                     => 'core/class-mds-rm-logger.php',
			'MDS_RM_SYNC'                       => 'core/class-mds-rm-sync.php',
			'MDS_RM_PROVIDERS'                  => 'core/class-mds-rm-providers.php',
			'MDS_RM_AUDIO_PLAYERS'              => 'core/class-mds-rm-audio-players.php',
			'MDS_RM_TEMPLATE_LOADER'            => 'core/class-mds-rm-template-loader.php',
			'MDS_RM_RELEASE_SEARCH'             => 'core/class-mds-rm-release-search.php',
			'MDS_RM_ADMIN_INTERFACE'            => 'admin/class-mds-rm-admin-interface.php',
			'MDS_RM_EDITOR'                     => 'admin/class-mds-rm-editor.php',
			'MDS_RM_NETWORK_ADMIN'              => 'admin/class-mds-rm-network-admin.php',
			'MDS_RM_CSV_HANDLER'                => 'admin/class-mds-rm-csv-handler.php',
			'MDS_RM_ASSETS'                     => 'frontend/class-mds-rm-assets.php',
			'MDS_RM_FRONTEND_TEMPLATES'         => 'frontend/class-mds-rm-frontend-templates.php',
			'MDS_RM_TEMPLATE_DEFAULTS'          => 'frontend/class-mds-rm-template-defaults.php',
			'MDS_RM_CUSTOMIZER'                 => 'frontend/class-mds-rm-customizer.php',
			'MDS_RM_ARTIST_ROUTING'             => 'frontend/class-mds-rm-artist-routing.php',
			'MDS_RM_DISCOGRAPHY'                => 'frontend/class-mds-rm-discography.php',
			'MDS_RM_SHORTCODES'                 => 'frontend/class-mds-rm-shortcodes.php',
			'MDS_RM_GUTENBERG_BLOCKS'           => 'blocks/class-mds-rm-gutenberg-blocks.php',
			'MDS_RM_BLOCK_DEFAULTS'             => 'blocks/class-mds-rm-block-defaults.php',
			'MDS_RM_RELEASE_META'               => 'blocks/class-mds-rm-release-meta.php',
			'MDS_RM_RELEASE_LINKS'              => 'blocks/class-mds-rm-release-links.php',
			'MDS_RM_RELEASE_LINKS_BLOCK'        => 'blocks/class-mds-rm-release-links-block.php',
			'MDS_RM_REST_API'                   => 'api/class-mds-rm-rest-api.php',
			'MDS_RM_ABILITIES'                  => 'api/class-mds-rm-abilities.php',
			'MDS_RM_VIRTUAL_MEDIA'              => 'api/class-mds-rm-virtual-media.php',
			'MDS_RM_ELEMENTOR_TAGS'             => 'integrations/elementor/class-mds-rm-elementor-tags.php',
			'MDS_RM_ELEMENTOR_WIDGET'           => 'integrations/elementor/class-mds-rm-elementor-widget.php',
			'MDS_RM_ELEMENTOR_LINKS_WIDGET'     => 'integrations/elementor/class-mds-rm-elementor-links-widget.php',
			'MDS_RM_ELEMENTOR_RELEASE_META_TAG' => 'integrations/elementor/class-mds-rm-elementor-release-meta-tag.php',
		);

		$includes = MDS_RM_PATH . 'includes/';
		$relative = $class_paths[ strtoupper( $class_name ) ] ?? null;
		if ( null !== $relative && is_readable( $includes . $relative ) ) {
			require_once $includes . $relative;
			return;
		}

		$class_file = $includes . 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';
		if ( is_readable( $class_file ) ) {
			require_once $class_file;
		}
	}
);
MDS_RM_Plugin::register_hooks();

<?php
/**
 * Release Manager bootstrap.
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

namespace MDS\ReleaseManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin's lifecycle and runtime callbacks.
 */
final class Plugin {

	/** Registers the plugin lifecycle and bootstrap hooks. */
	public static function register_hooks(): void {
		// During activation, WordPress includes the plugin after plugins_loaded.
		register_activation_hook( MDS_RM_FILE, array( \MDS\ReleaseManager\Core\PostTypes::class, 'activate' ) );
		register_deactivation_hook( MDS_RM_FILE, array( \MDS\ReleaseManager\Core\PostTypes::class, 'deactivate' ) );
		add_action( 'plugins_loaded', array( self::class, 'init' ), 0 );
		add_action( 'init', array( self::class, 'load_textdomain' ) );
	}

	/**
	 * Loads the plugin text domain on `init` per WordPress 6.7+ / 7.x guidelines.
	 *
	 * @since 1.1.0
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain( 'mds-release-manager', false, dirname( MDS_RM_BASENAME ) . '/languages' );
	}

	/** Registers the hooks for each plugin component. */
	public static function init(): void {
		\MDS\ReleaseManager\Frontend\Assets::register_hooks();
		\MDS\ReleaseManager\Core\Cache::register_hooks();
		\MDS\ReleaseManager\Core\Audio\AudioApi::register_hooks();
		\MDS\ReleaseManager\Core\Sync::register_hooks();
		\MDS\ReleaseManager\Core\PostTypes::register_hooks();
		\MDS\ReleaseManager\Core\ReleaseModel::register_hooks();
		\MDS\ReleaseManager\Admin\AdminInterface::register_hooks();
		\MDS\ReleaseManager\Admin\Editor::register_hooks();
		\MDS\ReleaseManager\Admin\NetworkAdmin::register_hooks();
		\MDS\ReleaseManager\Frontend\FrontendTemplates::register_hooks();
		\MDS\ReleaseManager\Frontend\ArtistRouting::register_hooks();
		\MDS\ReleaseManager\Api\RestApi::register_hooks();
		\MDS\ReleaseManager\Frontend\Shortcodes::register_hooks();
		\MDS\ReleaseManager\Integrations\Elementor\Tags::register_hooks();
		\MDS\ReleaseManager\Admin\CsvHandler::register_hooks();
		\MDS\ReleaseManager\Blocks\GutenbergBlocks::register_hooks();
		\MDS\ReleaseManager\Blocks\BlockDefaults::register_hooks();
		\MDS\ReleaseManager\Api\VirtualMedia::register_hooks();
		\MDS\ReleaseManager\Api\Abilities::register_hooks();

		/**
		 * Fires once MDS Release Manager core components are bootstrapped.
		 *
		 * Companion plugins should hook `mds_rm_loaded` (or check `MDS_RM_API_VERSION`)
		 * to register their integration hooks safely after Core is initialized.
		 *
		 * @since 2.0.0
		 *
		 * @param string $api_version Core Extension API semantic version.
		 */
		do_action( 'mds_rm_loaded', defined( 'MDS_RM_API_VERSION' ) ? MDS_RM_API_VERSION : \MDS\ReleaseManager\Api\ExtensionApi::API_VERSION );
	}
}

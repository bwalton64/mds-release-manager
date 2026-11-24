<?php
/**
 * Lazy backward-compatibility class aliases for legacy MDS_RM_* global classes.
 *
 * Ensures companion plugins (such as mds-bandcamp-integration and
 * mds-woocommerce-integration) and theme templates referencing legacy
 * `MDS_RM_*` class names resolve seamlessly to `MDS\ReleaseManager\*`
 * PSR-4 classes without eager loading.
 *
 * @package MDS\ReleaseManager
 * @since   1.1.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register(
	static function ( string $class_name ): void {
		if ( 0 !== stripos( $class_name, 'MDS_RM_' ) ) {
			return;
		}

		static $aliases = array(
			'MDS_RM_PLUGIN'                     => \MDS\ReleaseManager\Plugin::class,
			'MDS_RM_AUDIO_PLAYERS'              => \MDS\ReleaseManager\Core\AudioPlayers::class,
			'MDS_RM_BUILDER_DETECTION'          => \MDS\ReleaseManager\Core\BuilderDetection::class,
			'MDS_RM_CACHE'                      => \MDS\ReleaseManager\Core\Cache::class,
			'MDS_RM_LOGGER'                     => \MDS\ReleaseManager\Core\Logger::class,
			'MDS_RM_POST_TYPES'                 => \MDS\ReleaseManager\Core\PostTypes::class,
			'MDS_RM_PROVIDERS'                  => \MDS\ReleaseManager\Core\Providers::class,
			'MDS_RM_RELEASE_MODEL'              => \MDS\ReleaseManager\Core\ReleaseModel::class,
			'MDS_RM_RELEASE_SEARCH'             => \MDS\ReleaseManager\Core\ReleaseSearch::class,
			'MDS_RM_SYNC'                       => \MDS\ReleaseManager\Core\Sync::class,
			'MDS_RM_TEMPLATE_LOADER'            => \MDS\ReleaseManager\Core\TemplateLoader::class,
			'MDS_RM_ADMIN_INTERFACE'            => \MDS\ReleaseManager\Admin\AdminInterface::class,
			'MDS_RM_CSV_HANDLER'                => \MDS\ReleaseManager\Admin\CsvHandler::class,
			'MDS_RM_EDITOR'                     => \MDS\ReleaseManager\Admin\Editor::class,
			'MDS_RM_NETWORK_ADMIN'              => \MDS\ReleaseManager\Admin\NetworkAdmin::class,
			'MDS_RM_ABILITIES'                  => \MDS\ReleaseManager\Api\Abilities::class,
			'MDS_RM_REST_API'                   => \MDS\ReleaseManager\Api\RestApi::class,
			'MDS_RM_VIRTUAL_MEDIA'              => \MDS\ReleaseManager\Api\VirtualMedia::class,
			'MDS_RM_BLOCK_DEFAULTS'             => \MDS\ReleaseManager\Blocks\BlockDefaults::class,
			'MDS_RM_GUTENBERG_BLOCKS'           => \MDS\ReleaseManager\Blocks\GutenbergBlocks::class,
			'MDS_RM_RELEASE_LINKS'              => \MDS\ReleaseManager\Blocks\ReleaseLinks::class,
			'MDS_RM_RELEASE_LINKS_BLOCK'        => \MDS\ReleaseManager\Blocks\ReleaseLinksBlock::class,
			'MDS_RM_RELEASE_META'               => \MDS\ReleaseManager\Blocks\ReleaseMeta::class,
			'MDS_RM_ARTIST_ROUTING'             => \MDS\ReleaseManager\Frontend\ArtistRouting::class,
			'MDS_RM_ASSETS'                     => \MDS\ReleaseManager\Frontend\Assets::class,
			'MDS_RM_CUSTOMIZER'                 => \MDS\ReleaseManager\Frontend\Customizer::class,
			'MDS_RM_DISCOGRAPHY'                => \MDS\ReleaseManager\Frontend\Discography::class,
			'MDS_RM_FRONTEND_TEMPLATES'         => \MDS\ReleaseManager\Frontend\FrontendTemplates::class,
			'MDS_RM_SHORTCODES'                 => \MDS\ReleaseManager\Frontend\Shortcodes::class,
			'MDS_RM_TEMPLATE_DEFAULTS'          => \MDS\ReleaseManager\Frontend\TemplateDefaults::class,
			'MDS_RM_ELEMENTOR_TAGS'             => \MDS\ReleaseManager\Integrations\Elementor\Tags::class,
			'MDS_RM_ELEMENTOR_WIDGET'           => \MDS\ReleaseManager\Integrations\Elementor\Widget::class,
			'MDS_RM_ELEMENTOR_LINKS_WIDGET'     => \MDS\ReleaseManager\Integrations\Elementor\LinksWidget::class,
			'MDS_RM_ELEMENTOR_RELEASE_META_TAG' => \MDS\ReleaseManager\Integrations\Elementor\ReleaseMetaTag::class,
		);

		$key = strtoupper( $class_name );
		if ( isset( $aliases[ $key ] ) && class_exists( $aliases[ $key ] ) ) {
			class_alias( $aliases[ $key ], $class_name );
		}
	}
);

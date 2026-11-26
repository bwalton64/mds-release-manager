<?php
/**
 * Elementor Catalog / Discography Explorer widget.
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Integrations\Elementor;

use Elementor\Controls_Manager;
use MDS\ReleaseManager\Blocks\CatalogExplorer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Displays the searchable, filterable, paginated Catalog / Discography Explorer in Elementor.
 *
 * @since 2.2.0
 */
class CatalogExplorerWidget extends Widget {

	/**
	 * Gets the registered widget identifier.
	 *
	 * @return string Widget identifier.
	 */
	public function get_name() {
		return 'mds_catalog_explorer';
	}

	/**
	 * Gets the widget title shown in the editor.
	 *
	 * @return string Translated title.
	 */
	public function get_title() {
		return __( 'Catalog / Discography Explorer', 'mds-release-manager' );
	}

	/**
	 * Gets the Elementor icon class.
	 *
	 * @return string Icon class.
	 */
	public function get_icon() {
		return 'eicon-gallery-grid';
	}

	/** Registers the Catalog Explorer controls. */
	protected function register_controls() {
		$this->start_controls_section(
			'catalog_explorer_content',
			array(
				'label' => __( 'Catalog Explorer', 'mds-release-manager' ),
			)
		);

		$this->add_control(
			'artist_id',
			array(
				'label'       => __( 'Scope to Artist (Optional)', 'mds-release-manager' ),
				'type'        => Controls_Manager::TEXT,
				'description' => __( 'Leave blank for the full catalog, or enter an Artist Post ID or UUID.', 'mds-release-manager' ),
			)
		);

		$this->add_control(
			'columns',
			array(
				'label'   => __( 'Grid Columns (Desktop)', 'mds-release-manager' ),
				'type'    => Controls_Manager::SELECT,
				'default' => '3',
				'options' => array(
					'2' => '2',
					'3' => '3',
					'4' => '4',
				),
			)
		);

		$this->add_control(
			'per_page',
			array(
				'label'   => __( 'Releases Per Page', 'mds-release-manager' ),
				'type'    => Controls_Manager::NUMBER,
				'default' => 12,
				'min'     => 1,
				'max'     => 48,
			)
		);

		$this->add_control(
			'pagination',
			array(
				'label'   => __( 'Pagination Style', 'mds-release-manager' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'numbered',
				'options' => array(
					'numbered'  => __( 'Numbered Pagination', 'mds-release-manager' ),
					'load_more' => __( 'Load More Button', 'mds-release-manager' ),
				),
			)
		);

		$this->add_control(
			'default_sort',
			array(
				'label'   => __( 'Default Sort Order', 'mds-release-manager' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'date_desc',
				'options' => array(
					'date_desc'  => __( 'Newest First', 'mds-release-manager' ),
					'date_asc'   => __( 'Oldest First', 'mds-release-manager' ),
					'title_asc'  => __( 'Title (A–Z)', 'mds-release-manager' ),
					'artist_asc' => __( 'Artist (A–Z)', 'mds-release-manager' ),
				),
			)
		);

		$this->add_control(
			'show_search',
			array(
				'label'        => __( 'Show Search Box', 'mds-release-manager' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_filters',
			array(
				'label'        => __( 'Show Faceted Filters', 'mds-release-manager' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_sort',
			array(
				'label'        => __( 'Show Sort Selector', 'mds-release-manager' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_audio',
			array(
				'label'        => __( 'Show Inline Audio Preview Button', 'mds-release-manager' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_links',
			array(
				'label'        => __( 'Show Quick Buy Now / Streaming Links', 'mds-release-manager' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();
	}

	/** Renders the Catalog Explorer HTML. */
	protected function render() {
		$settings = $this->get_settings_for_display();

		$attrs = array(
			'artistId'    => trim( (string) ( $settings['artist_id'] ?? '' ) ),
			'columns'     => absint( $settings['columns'] ?? 3 ),
			'perPage'     => absint( $settings['per_page'] ?? 12 ),
			'pagination'  => (string) ( $settings['pagination'] ?? 'numbered' ),
			'defaultSort' => (string) ( $settings['default_sort'] ?? 'date_desc' ),
			'showSearch'  => 'yes' === ( $settings['show_search'] ?? 'yes' ),
			'showFilters' => 'yes' === ( $settings['show_filters'] ?? 'yes' ),
			'showSort'    => 'yes' === ( $settings['show_sort'] ?? 'yes' ),
			'showAudio'   => 'yes' === ( $settings['show_audio'] ?? 'yes' ),
			'showLinks'   => 'yes' === ( $settings['show_links'] ?? 'yes' ),
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered and escaped by CatalogExplorer::render().
		echo CatalogExplorer::render( $attrs );
	}
}

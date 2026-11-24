<?php
/**
 * Elementor Release Links widget.
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Integrations\Elementor;

use Elementor\Controls_Manager;
use Elementor\Repeater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Displays release listening and purchase links in Elementor. */
class LinksWidget extends Widget {
	/**
	 * Gets the registered widget identifier.
	 *
	 * @return string Widget identifier.
	 */
	public function get_name() {
		return 'mds_release_links';
	}

	/**
	 * Gets the widget title shown in the editor.
	 *
	 * @return string Translated title.
	 */
	public function get_title() {
		return __( 'Release Links Block', 'mds-release-manager' );
	}

	/** Registers the Release Links content and style controls. */
	protected function register_controls() {
		$this->start_controls_section( 'release_links_content', array( 'label' => __( 'Release Links', 'mds-release-manager' ) ) );
		$this->add_control(
			'upc',
			array(
				'label'       => __( 'UPC/EAN Override', 'mds-release-manager' ),
				'type'        => Controls_Manager::TEXT,
				'description' => __( 'Leave blank to use the current release or supported context.', 'mds-release-manager' ),
				'dynamic'     => array( 'active' => true ),
			)
		);
		$this->add_control(
			'new_tab',
			array(
				'label'        => __( 'Open links in a new tab', 'mds-release-manager' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);
		$this->add_control(
			'show_audio',
			array(
				'label'        => __( 'Show audio player', 'mds-release-manager' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);
		$repeater = new Repeater();
		$repeater->add_control(
			'name',
			array(
				'label'       => __( 'Provider name', 'mds-release-manager' ),
				'type'        => Controls_Manager::TEXT,
				'description' => __( 'Use the provider name from Release Settings, or __buy_now for Buy Now.', 'mds-release-manager' ),
			)
		);
		$repeater->add_control(
			'enabled',
			array(
				'label'        => __( 'Show link', 'mds-release-manager' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);
		$repeater->add_control(
			'icon',
			array(
				'label'       => __( 'Icon classes', 'mds-release-manager' ),
				'type'        => Controls_Manager::TEXT,
				'description' => __( 'Leave blank for the provider default. Dashicons and Font Awesome are supported.', 'mds-release-manager' ),
			)
		);
		$repeater->add_control(
			'title',
			array(
				'label'       => __( 'Tooltip title', 'mds-release-manager' ),
				'type'        => Controls_Manager::TEXT,
				'description' => __( 'Leave blank for the provider name.', 'mds-release-manager' ),
			)
		);
		$defaults = array();
		foreach ( \MDS\ReleaseManager\Blocks\ReleaseLinks::providers() as $provider ) {
			if ( isset( $provider['name'] ) ) {
				$defaults[] = array(
					'name'    => $provider['name'],
					'enabled' => 'yes',
				);
			}
		}
		$defaults[] = array(
			'name'    => '__buy_now',
			'enabled' => 'yes',
		);
		$this->add_control(
			'items',
			array(
				'label'       => __( 'Provider overrides', 'mds-release-manager' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'default'     => $defaults,
				'title_field' => '{{{ name }}}',
				'description' => __( 'Reorder or hide links here. Other saved providers remain visible.', 'mds-release-manager' ),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'release_links_style',
			array(
				'label' => __( 'Release Links Styles', 'mds-release-manager' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_control(
			'responsive_note',
			array(
				'type' => Controls_Manager::RAW_HTML,
				'raw'  => esc_html__( 'Tablet styles apply at 1024px and below; mobile styles apply at 767px and below. Blank values inherit.', 'mds-release-manager' ),
			)
		);
		$this->add_release_style_controls( \MDS\ReleaseManager\Blocks\ReleaseLinks::style_fields() );
		$this->end_controls_section();
	}

	/** Outputs release links using the shared renderer. */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$items    = array();
		foreach ( isset( $settings['items'] ) && is_array( $settings['items'] ) ? $settings['items'] : array() as $item ) {
			$items[] = array(
				'name'    => isset( $item['name'] ) ? $item['name'] : '',
				'enabled' => isset( $item['enabled'] ) && 'yes' === $item['enabled'],
				'icon'    => isset( $item['icon'] ) ? $item['icon'] : '',
				'title'   => isset( $item['title'] ) ? $item['title'] : '',
			);
		}
		$styles = array();
		foreach ( \MDS\ReleaseManager\Blocks\ReleaseLinks::style_fields() as $key => $_field ) {
			foreach ( array(
				'desktop' => '',
				'tablet'  => '_tablet',
				'mobile'  => '_mobile',
			) as $device => $suffix ) {
				$name = 'style_' . $key . $suffix;
				if ( isset( $settings[ $name ] ) && '' !== $settings[ $name ] ) {
					$styles[ $device ][ $key ] = $settings[ $name ];
				}
			}
		}
		$html = \MDS\ReleaseManager\Blocks\ReleaseLinks::render(
			array(
				'upc'       => isset( $settings['upc'] ) ? $settings['upc'] : '',
				'items'     => $items,
				'newTab'    => isset( $settings['new_tab'] ) && 'yes' === $settings['new_tab'],
				'showAudio' => isset( $settings['show_audio'] ) && 'yes' === $settings['show_audio'],
				'styles'    => $styles,
			),
			get_the_ID()
		);
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The renderer escapes values and sanitizes embedded audio.
		echo $html;
	}
}

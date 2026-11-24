<?php
/**
 * Elementor Release Meta widget.
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

/** Displays release metadata in Elementor. */
class Widget extends \Elementor\Widget_Base {
	/**
	 * Gets the registered widget identifier.
	 *
	 * @return string Widget identifier.
	 */
	public function get_name() {
		return 'mds_release_meta';
	}

	/**
	 * Gets the widget title shown in the editor.
	 *
	 * @return string Translated title.
	 */
	public function get_title() {
		return __( 'Release Meta Block', 'mds-release-manager' );
	}

	/**
	 * Gets the widget icon shown in the editor.
	 *
	 * @return string Icon classes.
	 */
	public function get_icon() {
		return 'dashicons dashicons-album';
	}

	/**
	 * Gets the editor categories containing this widget.
	 *
	 * @return string[] Category identifiers.
	 */
	public function get_categories() {
		return array( 'general' );
	}

	/** Registers the Release Meta content and style controls. */
	protected function register_controls() {
		$this->start_controls_section( 'release_meta_content', array( 'label' => __( 'Release Meta', 'mds-release-manager' ) ) );
		$this->add_control(
			'upc',
			array(
				'label'       => __( 'UPC/EAN Override', 'mds-release-manager' ),
				'type'        => Controls_Manager::TEXT,
				'description' => __( 'Leave blank to use the current release or supported context.', 'mds-release-manager' ),
				'dynamic'     => array( 'active' => true ),
			)
		);
		$options  = array();
		$defaults = array();
		foreach ( \MDS\ReleaseManager\Blocks\ReleaseMeta::fields() as $key => $field ) {
			$options[ $key ] = $field['label'];
			$defaults[]      = array(
				'field'   => $key,
				'enabled' => $field['enabled'] ? 'yes' : '',
				'icon'    => $field['icon'],
				'title'   => $field['label'],
			);
		}
		$repeater = new Repeater();
		$repeater->add_control(
			'field',
			array(
				'label'   => __( 'Field', 'mds-release-manager' ),
				'type'    => Controls_Manager::SELECT,
				'options' => $options,
			)
		);
		$repeater->add_control(
			'enabled',
			array(
				'label'        => __( 'Show field', 'mds-release-manager' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);
		$repeater->add_control(
			'icon_preset',
			array(
				'label'   => __( 'Choose icon', 'mds-release-manager' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'custom',
				'options' => array(
					'custom'                           => __( 'Custom icon classes', 'mds-release-manager' ),
					'none'                             => __( 'No icon', 'mds-release-manager' ),
					'dashicons dashicons-album'        => 'Album (Dashicons)',
					'dashicons dashicons-calendar-alt' => 'Calendar (Dashicons)',
					'dashicons dashicons-groups'       => 'Artist (Dashicons)',
					'dashicons dashicons-tag'          => 'Tag (Dashicons)',
					'dashicons dashicons-star-filled'  => 'Star (Dashicons)',
					'fas fa-calendar'                  => 'Calendar (Font Awesome)',
					'fas fa-compact-disc'              => 'Disc (Font Awesome)',
					'fas fa-tower-broadcast'           => 'Distributor (Font Awesome)',
					'fas fa-barcode'                   => 'Barcode (Font Awesome)',
					'fas fa-music'                     => 'Music (Font Awesome)',
				),
			)
		);
		$repeater->add_control(
			'icon',
			array(
				'label'       => __( 'Icon classes', 'mds-release-manager' ),
				'type'        => Controls_Manager::TEXT,
				'description' => __( 'Use Dashicons or Font Awesome classes; leave blank for no icon.', 'mds-release-manager' ),
				'condition'   => array( 'icon_preset' => 'custom' ),
			)
		);
		$repeater->add_control(
			'title',
			array(
				'label' => __( 'Tooltip title', 'mds-release-manager' ),
				'type'  => Controls_Manager::TEXT,
			)
		);
		$this->add_control(
			'items',
			array(
				'label'       => __( 'Fields', 'mds-release-manager' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'default'     => $defaults,
				'title_field' => '{{ field }}',
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'release_meta_style',
			array(
				'label' => __( 'Release Meta Styles', 'mds-release-manager' ),
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
		$this->add_release_style_controls( \MDS\ReleaseManager\Blocks\ReleaseMeta::style_fields() );
		$this->end_controls_section();
	}

	/**
	 * Adds shared responsive style controls for a release widget.
	 *
	 * @param array $fields Style field definitions keyed by field name.
	 */
	protected function add_release_style_controls( $fields ) {
		foreach ( $fields as $key => $field ) {
			$control = array(
				'label' => $field['label'],
				'type'  => Controls_Manager::TEXT,
			);
			if ( 'align' === $field['type'] ) {
				$control['type']    = Controls_Manager::SELECT;
				$control['options'] = array(
					''              => 'Default / inherit',
					'flex-start'    => 'Start',
					'center'        => 'Center',
					'flex-end'      => 'End',
					'space-between' => 'Space between',
				);
			} elseif ( in_array( $field['type'], array( 'vertical_align', 'direction', 'wrap' ), true ) ) {
				$choices            = array(
					'vertical_align' => array( 'flex-start', 'center', 'flex-end', 'stretch' ),
					'direction'      => array( 'row', 'row-reverse', 'column', 'column-reverse' ),
					'wrap'           => array( 'wrap', 'nowrap', 'wrap-reverse' ),
				);
				$control['type']    = Controls_Manager::SELECT;
				$control['options'] = array( '' => 'Default / inherit' );
				foreach ( $choices[ $field['type'] ] as $choice ) {
					$control['options'][ $choice ] = $choice;
				}
			} elseif ( 'border_style' === $field['type'] ) {
				$control['type']    = Controls_Manager::SELECT;
				$control['options'] = array(
					''       => 'Default / inherit',
					'none'   => 'None',
					'solid'  => 'Solid',
					'dashed' => 'Dashed',
					'dotted' => 'Dotted',
					'double' => 'Double',
				);
			} elseif ( 'color' === $field['type'] ) {
				$control['description'] = __( 'Hex color, transparent, or inherit.', 'mds-release-manager' );
			} elseif ( in_array( $field['type'], array( 'length', 'spacing' ), true ) ) {
				$control['description'] = __( 'CSS lengths such as 12px or 1em; spacing accepts up to four values.', 'mds-release-manager' );
			} elseif ( 'width' === $field['type'] ) {
				$control['description'] = __( 'Use auto or a CSS length such as 100% or 20rem.', 'mds-release-manager' );
			}
			$this->add_responsive_control( 'style_' . $key, $control );
		}
	}

	/** Outputs release metadata using the shared renderer. */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$items    = array();
		foreach ( isset( $settings['items'] ) && is_array( $settings['items'] ) ? $settings['items'] : array() as $item ) {
			$preset  = isset( $item['icon_preset'] ) ? $item['icon_preset'] : 'custom';
			$items[] = array(
				'field'   => isset( $item['field'] ) ? $item['field'] : '',
				'enabled' => isset( $item['enabled'] ) && 'yes' === $item['enabled'],
				'icon'    => 'custom' === $preset ? ( isset( $item['icon'] ) ? $item['icon'] : '' ) : ( 'none' === $preset ? '' : $preset ),
				'title'   => isset( $item['title'] ) ? $item['title'] : '',
			);
		}
		$styles = array();
		foreach ( \MDS\ReleaseManager\Blocks\ReleaseMeta::style_fields() as $key => $_field ) {
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
		$html = \MDS\ReleaseManager\Blocks\ReleaseMeta::render(
			array(
				'upc'    => isset( $settings['upc'] ) ? $settings['upc'] : '',
				'items'  => $items,
				'styles' => $styles,
			),
			get_the_ID()
		);
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The shared renderer escapes values and validates CSS custom properties.
		echo $html;
	}
}

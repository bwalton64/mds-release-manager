<?php
/**
 * Elementor Release Player widget.
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Integrations\Elementor;

use Elementor\Controls_Manager;
use MDS\ReleaseManager\Blocks\ReleasePlayer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Displays the unified mobile-first Release Player in Elementor.
 *
 * @since 2.2.0
 */
class PlayerWidget extends Widget {

	/**
	 * Gets the registered widget identifier.
	 *
	 * @return string Widget identifier.
	 */
	public function get_name() {
		return 'mds_release_player';
	}

	/**
	 * Gets the widget title shown in the editor.
	 *
	 * @return string Translated title.
	 */
	public function get_title() {
		return __( 'Release Player', 'mds-release-manager' );
	}

	/**
	 * Gets the Elementor icon class.
	 *
	 * @return string Icon class.
	 */
	public function get_icon() {
		return 'eicon-play';
	}

	/** Registers the Release Player controls. */
	protected function register_controls() {
		$this->start_controls_section(
			'release_player_content',
			array(
				'label' => __( 'Release Player', 'mds-release-manager' ),
			)
		);

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
			'show_artwork',
			array(
				'label'        => __( 'Show Cover Artwork', 'mds-release-manager' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_tracklist',
			array(
				'label'        => __( 'Show Tracklist', 'mds-release-manager' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_attribution',
			array(
				'label'        => __( 'Show Provider Attribution', 'mds-release-manager' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();
	}

	/** Renders the Release Player HTML. */
	protected function render() {
		$settings = $this->get_settings_for_display();

		$attrs = array(
			'upc'             => trim( (string) ( $settings['upc'] ?? '' ) ),
			'showArtwork'     => 'yes' === ( $settings['show_artwork'] ?? 'yes' ),
			'showTracklist'   => 'yes' === ( $settings['show_tracklist'] ?? 'yes' ),
			'showAttribution' => 'yes' === ( $settings['show_attribution'] ?? 'yes' ),
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered and escaped by ReleasePlayer::render().
		echo ReleasePlayer::render( $attrs );
	}
}

<?php
/**
 * Elementor Artist Discography widget.
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Integrations\Elementor;

use Elementor\Controls_Manager;
use MDS\ReleaseManager\Frontend\Discography;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Displays a responsive, paginated Artist Discography table in Elementor.
 *
 * @since 2.2.0
 */
class DiscographyWidget extends Widget {

	/**
	 * Gets the registered widget identifier.
	 *
	 * @return string Widget identifier.
	 */
	public function get_name() {
		return 'mds_artist_discography';
	}

	/**
	 * Gets the widget title shown in the editor.
	 *
	 * @return string Translated title.
	 */
	public function get_title() {
		return __( 'Artist Discography', 'mds-release-manager' );
	}

	/**
	 * Gets the Elementor icon class.
	 *
	 * @return string Icon class.
	 */
	public function get_icon() {
		return 'eicon-table';
	}

	/** Registers the Artist Discography controls. */
	protected function register_controls() {
		$this->start_controls_section(
			'discography_content',
			array(
				'label' => __( 'Artist Discography', 'mds-release-manager' ),
			)
		);

		$this->add_control(
			'artist_id',
			array(
				'label'       => __( 'Artist Post ID (Optional)', 'mds-release-manager' ),
				'type'        => Controls_Manager::NUMBER,
				'description' => __( 'Leave blank to use the current Artist page context.', 'mds-release-manager' ),
				'min'         => 0,
			)
		);

		$this->add_control(
			'per_page',
			array(
				'label'   => __( 'Releases Per Page', 'mds-release-manager' ),
				'type'    => Controls_Manager::NUMBER,
				'default' => 10,
				'min'     => 1,
				'max'     => 100,
			)
		);

		$this->add_control(
			'striped',
			array(
				'label'        => __( 'Striped Rows', 'mds-release-manager' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();
	}

	/** Renders the Artist Discography HTML. */
	protected function render() {
		$settings = $this->get_settings_for_display();

		$shortcode_atts = array(
			'artist_id' => absint( $settings['artist_id'] ?? 0 ),
			'per_page'  => max( 1, absint( $settings['per_page'] ?? 10 ) ),
			'striped'   => 'yes' === ( $settings['striped'] ?? 'yes' ) ? '1' : '0',
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered and escaped by Discography::shortcode().
		echo Discography::shortcode( $shortcode_atts );
	}
}

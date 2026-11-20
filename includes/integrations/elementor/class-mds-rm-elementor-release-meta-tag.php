<?php
declare(strict_types=1);

/**
 * Registers the Elementor dynamic tag for release metadata.
 *
 * @package MDS_Release_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes release metadata as an Elementor dynamic tag.
 *
 * @since 0.9.0
 */
class MDS_RM_Elementor_Release_Meta_Tag extends \Elementor\Core\DynamicTags\Data_Tag {

	/**
	 * Returns the dynamic tag identifier.
	 *
	 * @since 0.9.0
	 * @return string The dynamic tag identifier.
	 */
	public function get_name() {
		return 'mds_release_meta';
	}

	/**
	 * Returns the dynamic tag title.
	 *
	 * @since 0.9.0
	 * @return string The dynamic tag title.
	 */
	public function get_title() {
		return 'Release Metadata';
	}

	/**
	 * Returns the Elementor group for the dynamic tag.
	 *
	 * @since 0.9.0
	 * @return string The Elementor group identifier.
	 */
	public function get_group() {
		return 'post';
	}

	/**
	 * Returns the categories supported by the dynamic tag.
	 *
	 * @since 0.9.0
	 * @return string[] The supported dynamic tag categories.
	 */
	public function get_categories() {
		return array(
			\Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY,
			\Elementor\Modules\DynamicTags\Module::URL_CATEGORY,
			\Elementor\Modules\DynamicTags\Module::IMAGE_CATEGORY,
		);
	}

	/**
	 * Registers the metadata selector control.
	 *
	 * @since 0.9.0
	 */
	protected function register_controls() {
		$this->add_control(
			'mds_meta_key',
			array(
				'label'   => 'Select Field',
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					'upc_ean'              => 'UPC/EAN',
					'release_year'         => 'Release Year (Derived)',
					'record_label'         => 'Record Label',
					'distributor'          => 'Distributor',
					'release_format'       => 'Release Format',
					'release_tags'         => 'Release Tags',
					'buy_now_link'         => 'Buy Now Link',
					'featured_image_url'   => 'Featured Image URL',
					'bandcamp_shortcode'   => 'Bandcamp Shortcode',
					'stream_Spotify'       => 'Spotify Link',
					'stream_Apple Music'   => 'Apple Music Link',
					'stream_Amazon Music'  => 'Amazon Music Link',
					'stream_YouTube Music' => 'YouTube Music Link',
					'stream_Soundcloud'    => 'Soundcloud Link',
				),
			)
		);
	}

	/**
	 * Returns the selected release metadata value.
	 *
	 * @since 0.9.0
	 * @param array $options Dynamic tag options.
	 * @return mixed The selected metadata value, which can include structured image data.
	 */
	public function get_value( array $options = array() ) {
		$key = $this->get_settings( 'mds_meta_key' );
		if ( empty( $key ) ) {
			return '';
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return '';
		}

		if ( 0 === strpos( $key, 'stream_' ) ) {
			$provider = str_replace( 'stream_', '', $key );
			$json     = get_post_meta( $post_id, 'streaming_links', true );
			$links    = json_decode( $json, true );
			return isset( $links[ $provider ] ) ? $links[ $provider ] : '';
		}

		if ( 'release_format' === $key ) {
			$format = MDS_RM_Release_Model::format_name( $post_id );
			if ( is_wp_error( $format ) ) {
				error_log( '[MDS Release Manager] Release ' . $post_id . ': ' . $format->get_error_message() );
				return '';
			}
			return $format;
		}

		if ( 'release_tags' === $key ) {
			$tags = get_post_meta( $post_id, 'release_tags', true );
			if ( $tags ) {
				return $tags;
			}

			$terms = wp_get_post_terms( $post_id, 'release_tag', array( 'fields' => 'names' ) );
			return ! is_wp_error( $terms ) && ! empty( $terms ) ? implode( ', ', $terms ) : $tags;
		}

		if ( 'release_year' === $key ) {
			$date = get_post_field( 'post_date', $post_id );
			return ( ! empty( $date ) && '0000-00-00 00:00:00' !== $date ) ? gmdate( 'Y', strtotime( $date ) ) : '';
		}

		if ( 'featured_image_url' === $key ) {
			$image = get_post_meta( $post_id, 'featured_image_url', true );
			return array(
				'id'  => '',
				'url' => $image ? $image : '',
			);
		}

		$value = get_post_meta( $post_id, $key, true );
		return $value ? $value : '';
	}
}

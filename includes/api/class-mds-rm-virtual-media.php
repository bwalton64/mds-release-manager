<?php
declare(strict_types=1);

/**
 * Virtual media handling for the plugin.
 *
 * @package MDS_Release_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides virtual media metadata and REST representations.
 *
 * @since 0.9.0
 */
final class MDS_RM_Virtual_Media {

	/**
	 * Registers virtual-media filters.
	 *
	 * @since 0.9.0
	 */
	public static function register_hooks(): void {
		add_filter( 'wp_get_attachment_url', array( self::class, 'virtual_attachment_url' ), 10, 2 );
		add_filter( 'image_downsize', array( self::class, 'virtual_image_downsize' ), 10, 2 );
		add_filter( 'get_post_metadata', array( self::class, 'virtual_media_meta_fallback' ), 10, 3 );
		add_filter( 'rest_prepare_attachment', array( self::class, 'force_rest_virtual_attachment' ), 10, 2 );
	}

	/**
	 * Returns the hub URL for a virtual attachment.
	 *
	 * @since 0.9.0
	 * @param mixed $url     The current attachment URL filter value.
	 * @param int   $post_id The attachment ID.
	 * @return mixed The stored hub URL or original filter value.
	 */
	public static function virtual_attachment_url( mixed $url, $post_id ): mixed {
		$hub_url = get_post_meta( $post_id, '_mds_is_hub_media', true );
		if ( ! empty( $hub_url ) ) {
			return $hub_url;
		}
		return $url;
	}

	/**
	 * Filter image_downsize to spoof the image for Gutenberg, Elementor, and WooCommerce.
	 *
	 * @since 0.9.0
	 * @param mixed $downsize      The current image-downsize result.
	 * @param int   $attachment_id The attachment ID.
	 * @return mixed The virtual image dimensions or original result.
	 */
	public static function virtual_image_downsize( mixed $downsize, $attachment_id ): mixed {
		$hub_url = get_post_meta( $attachment_id, '_mds_is_hub_media', true );
		if ( ! empty( $hub_url ) ) {
			return array( $hub_url, 800, 800, false );
		}
		return $downsize;
	}

	/**
	 * Make WordPress treat virtual media as valid image attachments.
	 *
	 * @since 0.9.0
	 * @param mixed  $value    The current metadata value.
	 * @param int    $object_id The metadata object ID.
	 * @param string $meta_key The metadata key.
	 * @return mixed The virtual attachment metadata or original value.
	 */
	public static function virtual_media_meta_fallback( mixed $value, $object_id, $meta_key ): mixed {
		if ( ! in_array( $meta_key, array( '_wp_attached_file', '_wp_attachment_metadata' ), true ) ) {
			return $value;
		}

		$hub_url = get_post_meta( $object_id, '_mds_is_hub_media', true );
		if ( empty( $hub_url ) ) {
			return $value;
		}

		if ( '_wp_attached_file' === $meta_key ) {
			return array( basename( $hub_url ) );
		}

		return array(
			array(
				'width'  => 800,
				'height' => 800,
				'file'   => basename( $hub_url ),
				'sizes'  => array(),
			),
		);
	}

	/**
	 * Get or create a virtual attachment on a spoke site.
	 *
	 * @since 0.9.0
	 * @param string $hub_image_url The hub attachment URL.
	 * @param int    $hub_image_id  The hub attachment ID.
	 * @return int The local virtual attachment ID, or zero on failure.
	 */
	public static function get_or_create_virtual_attachment( $hub_image_url, $hub_image_id ) {
		if ( empty( $hub_image_url ) ) {
			return 0;
		}

		// Search all post types to catch previously malformed attachments.
		$args  = array(
			'post_type'   => 'any',
			'post_status' => 'any',
			'meta_key'    => '_mds_is_hub_media',
			'meta_value'  => $hub_image_url,
			'fields'      => 'ids',
			'numberposts' => 1,
		);
		$found = get_posts( $args );

		if ( ! empty( $found ) ) {
			$attach_id = $found[0];

			// Correct previously malformed virtual attachments.
			if ( 'attachment' !== get_post_type( $attach_id ) ) {
				wp_update_post(
					array(
						'ID'          => $attach_id,
						'post_type'   => 'attachment',
						'post_status' => 'inherit',
					)
				);
			}

			if ( ! get_post_meta( $attach_id, '_wp_attached_file', true ) ) {
				update_post_meta( $attach_id, '_wp_attached_file', basename( $hub_image_url ) );
				update_post_meta(
					$attach_id,
					'_wp_attachment_metadata',
					array(
						'width'  => 800,
						'height' => 800,
						'file'   => basename( $hub_image_url ),
						'sizes'  => array(),
					)
				);
			}
			return $attach_id;
		}

		$attachment = array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image/jpeg',
			'post_title'     => 'Hub Media ' . $hub_image_id,
			'post_content'   => '',
			'post_status'    => 'inherit',
		);
		$attach_id  = wp_insert_post( $attachment );
		if ( ! is_wp_error( $attach_id ) ) {
			update_post_meta( $attach_id, '_mds_is_hub_media', $hub_image_url );
			update_post_meta( $attach_id, '_wp_attached_file', basename( $hub_image_url ) );
			update_post_meta(
				$attach_id,
				'_wp_attachment_metadata',
				array(
					'width'  => 800,
					'height' => 800,
					'file'   => basename( $hub_image_url ),
					'sizes'  => array(),
				)
			);
			return $attach_id;
		}
		return 0;
	}
	/**
	 * Adds virtual media details to the attachment REST response.
	 *
	 * @since 0.9.0
	 * @param WP_REST_Response $response The attachment REST response.
	 * @param WP_Post          $post     The attachment post.
	 * @return WP_REST_Response The updated attachment REST response.
	 */
	public static function force_rest_virtual_attachment( $response, $post ) {
		$hub_url = get_post_meta( $post->ID, '_mds_is_hub_media', true );
		if ( ! empty( $hub_url ) ) {
			$data                  = $response->get_data();
			$data['source_url']    = $hub_url;
			$data['media_details'] = array(
				'width'  => 800,
				'height' => 800,
				'file'   => basename( $hub_url ),
				'sizes'  => array(
					'full' => array(
						'file'       => basename( $hub_url ),
						'width'      => 800,
						'height'     => 800,
						'mime_type'  => 'image/jpeg',
						'source_url' => $hub_url,
					),
				),
			);
			$response->set_data( $data );
		}
		return $response;
	}
}

<?php
/**
 * Virtual media handling for the plugin.
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides virtual media metadata and REST representations.
 *
 * @since 0.9.0
 */
final class VirtualMedia {

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
	 * Returns stored dimensions and MIME type for a virtual attachment.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return array{width:int,height:int,mime_type:string} Media dimensions and MIME type.
	 */
	private static function stored_media_info( int $attachment_id ): array {
		$dims      = get_post_meta( $attachment_id, '_mds_hub_media_dims', true );
		$width     = is_array( $dims ) && ! empty( $dims['width'] ) ? absint( $dims['width'] ) : 800;
		$height    = is_array( $dims ) && ! empty( $dims['height'] ) ? absint( $dims['height'] ) : 800;
		$mime_type = get_post_mime_type( $attachment_id );
		if ( ! is_string( $mime_type ) || '' === $mime_type ) {
			$mime_type = is_array( $dims ) && ! empty( $dims['mime_type'] ) ? (string) $dims['mime_type'] : 'image/jpeg';
		}

		return array(
			'width'     => $width > 0 ? $width : 800,
			'height'    => $height > 0 ? $height : 800,
			'mime_type' => $mime_type,
		);
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
			$info = self::stored_media_info( (int) $attachment_id );
			return array( $hub_url, $info['width'], $info['height'], false );
		}
		return $downsize;
	}

	/**
	 * Make WordPress treat virtual media as valid image attachments.
	 *
	 * @since 0.9.0
	 * @param mixed  $value     The current metadata value.
	 * @param int    $object_id The metadata object ID.
	 * @param string $meta_key  The metadata key.
	 * @return mixed The virtual attachment metadata or original value.
	 */
	public static function virtual_media_meta_fallback( mixed $value, $object_id, $meta_key ): mixed {
		static $in_progress = false;
		if ( $in_progress || ! in_array( $meta_key, array( '_wp_attached_file', '_wp_attachment_metadata' ), true ) ) {
			return $value;
		}

		$in_progress = true;
		try {
			$hub_url = get_post_meta( $object_id, '_mds_is_hub_media', true );
			if ( empty( $hub_url ) ) {
				return $value;
			}

			if ( '_wp_attached_file' === $meta_key ) {
				return array( basename( $hub_url ) );
			}

			$info = self::stored_media_info( (int) $object_id );
			return array(
				array(
					'width'  => $info['width'],
					'height' => $info['height'],
					'file'   => basename( $hub_url ),
					'sizes'  => array(),
				),
			);
		} finally {
			$in_progress = false;
		}
	}

	/**
	 * Get or create a virtual attachment on a spoke site.
	 *
	 * Looks up existing virtual attachments by stable `_mds_hub_media_id`
	 * (`{hub_blog_id}:{hub_attachment_id}`) first, falling back to `_mds_is_hub_media`
	 * URL matching for legacy records. Updates URL, MIME type, and dimensions when
	 * the hub media changes domain/scheme or metadata.
	 *
	 * @since 0.9.0
	 * @param string               $hub_image_url The hub attachment URL.
	 * @param int                  $hub_image_id  The hub attachment ID.
	 * @param array<string, mixed> $media_info    Optional hub media metadata (`width`, `height`, `mime_type`, `hub_blog_id`).
	 * @return int The local virtual attachment ID, or zero on failure.
	 */
	public static function get_or_create_virtual_attachment( $hub_image_url, $hub_image_id, array $media_info = array() ) {
		if ( empty( $hub_image_url ) ) {
			return 0;
		}

		$hub_image_id = absint( $hub_image_id );
		$hub_blog_id  = ! empty( $media_info['hub_blog_id'] ) ? absint( $media_info['hub_blog_id'] ) : \MDS\ReleaseManager\Core\Sync::hub_id();
		$hub_media_id = $hub_image_id > 0 ? $hub_blog_id . ':' . $hub_image_id : '';
		$width        = ! empty( $media_info['width'] ) ? absint( $media_info['width'] ) : 800;
		$height       = ! empty( $media_info['height'] ) ? absint( $media_info['height'] ) : 800;
		$mime_type    = ! empty( $media_info['mime_type'] ) && is_string( $media_info['mime_type'] )
			? sanitize_mime_type( $media_info['mime_type'] )
			: 'image/jpeg';
		if ( '' === $mime_type ) {
			$mime_type = 'image/jpeg';
		}

		$attach_id = 0;

		if ( '' !== $hub_media_id ) {
			$by_key = get_posts(
				array(
					'post_type'              => 'any',
					'post_status'            => 'any',
					'meta_key'               => '_mds_hub_media_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Reuse virtual attachment by stable hub blog:attachment key.
					'meta_value'             => $hub_media_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'fields'                 => 'ids',
					'posts_per_page'         => 1,
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
				)
			);
			if ( ! empty( $by_key ) ) {
				$attach_id = (int) $by_key[0];
			}
		}

		if ( ! $attach_id ) {
			// Fall back to legacy URL lookup for previously synced virtual attachments.
			$by_url = get_posts(
				array(
					'post_type'              => 'any',
					'post_status'            => 'any',
					'meta_key'               => '_mds_is_hub_media', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Reuse the virtual attachment identified by its stored hub URL.
					'meta_value'             => $hub_image_url, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'fields'                 => 'ids',
					'posts_per_page'         => 1,
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
				)
			);
			if ( ! empty( $by_url ) ) {
				$attach_id = (int) $by_url[0];
			}
		}

		$dims_payload = array(
			'width'     => $width,
			'height'    => $height,
			'mime_type' => $mime_type,
		);
		$meta_payload = array(
			'width'  => $width,
			'height' => $height,
			'file'   => basename( $hub_image_url ),
			'sizes'  => array(),
		);

		if ( $attach_id ) {
			$post_updates = array();
			if ( 'attachment' !== get_post_type( $attach_id ) ) {
				$post_updates['post_type']   = 'attachment';
				$post_updates['post_status'] = 'inherit';
			}
			if ( get_post_mime_type( $attach_id ) !== $mime_type ) {
				$post_updates['post_mime_type'] = $mime_type;
			}
			if ( $post_updates ) {
				$post_updates['ID'] = $attach_id;
				wp_update_post( $post_updates );
			}

			if ( '' !== $hub_media_id && get_post_meta( $attach_id, '_mds_hub_media_id', true ) !== $hub_media_id ) {
				update_post_meta( $attach_id, '_mds_hub_media_id', $hub_media_id );
			}
			if ( get_post_meta( $attach_id, '_mds_is_hub_media', true ) !== $hub_image_url ) {
				update_post_meta( $attach_id, '_mds_is_hub_media', $hub_image_url );
			}
			$existing_dims = get_post_meta( $attach_id, '_mds_hub_media_dims', true );
			if ( $existing_dims !== $dims_payload ) {
				update_post_meta( $attach_id, '_mds_hub_media_dims', $dims_payload );
				update_post_meta( $attach_id, '_wp_attached_file', basename( $hub_image_url ) );
				update_post_meta( $attach_id, '_wp_attachment_metadata', $meta_payload );
			}
			return $attach_id;
		}

		$attachment = array(
			'post_type'      => 'attachment',
			'post_mime_type' => $mime_type,
			'post_title'     => 'Hub Media ' . $hub_image_id,
			'post_content'   => '',
			'post_status'    => 'inherit',
		);
		$attach_id  = wp_insert_post( $attachment );
		if ( ! is_wp_error( $attach_id ) && $attach_id ) {
			if ( '' !== $hub_media_id ) {
				update_post_meta( $attach_id, '_mds_hub_media_id', $hub_media_id );
			}
			update_post_meta( $attach_id, '_mds_is_hub_media', $hub_image_url );
			update_post_meta( $attach_id, '_mds_hub_media_dims', $dims_payload );
			update_post_meta( $attach_id, '_wp_attached_file', basename( $hub_image_url ) );
			update_post_meta( $attach_id, '_wp_attachment_metadata', $meta_payload );
			return (int) $attach_id;
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
			$info                  = self::stored_media_info( (int) $post->ID );
			$data                  = $response->get_data();
			$data['source_url']    = $hub_url;
			$data['media_details'] = array(
				'width'  => $info['width'],
				'height' => $info['height'],
				'file'   => basename( $hub_url ),
				'sizes'  => array(
					'full' => array(
						'file'       => basename( $hub_url ),
						'width'      => $info['width'],
						'height'     => $info['height'],
						'mime_type'  => $info['mime_type'],
						'source_url' => $hub_url,
					),
				),
			);
			$response->set_data( $data );
		}
		return $response;
	}
}

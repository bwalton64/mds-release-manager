<?php
/**
 * Free Core Release Artwork Gallery (`_mds_rm_artwork_gallery`) with WooCommerce-style
 * sidebar meta box and Multisite Spoke VirtualMedia replication.
 *
 * @package   MDS\ReleaseManager
 * @copyright 2026 Musty Dungeon Studios (Walton International Business Group LLC)
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Core;

use MDS\ReleaseManager\Api\VirtualMedia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages secondary Release gallery assets (Back Cover, Booklet, Disc/Vinyl, Tray, Promo).
 *
 * @since 2.2.0
 */
final class ArtworkGallery {

	/**
	 * Core post meta key for Release Artwork Gallery.
	 *
	 * @var string
	 */
	public const META_GALLERY = '_mds_rm_artwork_gallery';

	/**
	 * Legacy companion meta key checked on read for seamless migration.
	 *
	 * @var string
	 */
	public const LEGACY_META_GALLERY = '_mds_md_artwork_gallery';

	/**
	 * Valid asset types.
	 *
	 * @var string[]
	 */
	public const ASSET_TYPES = array( 'back', 'booklet', 'medium', 'tray', 'obi', 'promo', 'other' );

	/**
	 * Registers Multisite Spoke VirtualMedia replication hook.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'mds_rm_after_sync_release', array( self::class, 'replicate_on_spoke_sync' ), 20, 3 );
	}

	/**
	 * Reads the structured Artwork Gallery for a Release post.
	 *
	 * @param int $post_id Release post ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_gallery( int $post_id ): array {
		if ( $post_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return array();
		}
		$raw = get_post_meta( $post_id, self::META_GALLERY, true );
		if ( '' === $raw || false === $raw || ( is_array( $raw ) && empty( $raw ) ) ) {
			$raw = get_post_meta( $post_id, self::LEGACY_META_GALLERY, true );
		}
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		return is_array( $raw ) ? array_values( $raw ) : array();
	}

	/**
	 * Returns ordered attachment IDs for the Release Artwork Gallery (for WooCommerce / themes).
	 *
	 * @param int $post_id Release post ID.
	 * @return int[]
	 */
	public static function get_gallery_ids( int $post_id ): array {
		$ids = array();
		foreach ( self::get_gallery( $post_id ) as $item ) {
			$aid = (int) ( $item['attachment_id'] ?? 0 );
			if ( $aid > 0 && ! in_array( $aid, $ids, true ) ) {
				$ids[] = $aid;
			}
		}
		return $ids;
	}

	/**
	 * Saves the ordered Artwork Gallery and fires `mds_rm_artwork_gallery_saved`.
	 *
	 * @param int                              $post_id Release post ID.
	 * @param array<int, array<string, mixed>> $items   Gallery items.
	 * @return array<int, array<string, mixed>>
	 */
	public static function save_gallery( int $post_id, array $items ): array {
		$clean = array();
		$seen  = array();
		foreach ( $items as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$aid = (int) ( $row['attachment_id'] ?? 0 );
			if ( $aid <= 0 || isset( $seen[ $aid ] ) ) {
				continue;
			}
			$seen[ $aid ] = true;
			$type         = sanitize_key( (string) ( $row['asset_type'] ?? 'other' ) );
			if ( ! in_array( $type, self::ASSET_TYPES, true ) ) {
				$type = 'other';
			}
			$clean[] = array(
				'attachment_id' => $aid,
				'asset_type'    => $type,
				'provider'      => sanitize_key( (string) ( $row['provider'] ?? 'local' ) ),
				'source_url'    => esc_url_raw( (string) ( $row['source_url'] ?? '' ) ),
			);
		}

		if ( $post_id > 0 && function_exists( 'update_post_meta' ) ) {
			update_post_meta( $post_id, self::META_GALLERY, wp_slash( (string) wp_json_encode( $clean, JSON_INVALID_UTF8_SUBSTITUTE ) ) );
			$ids = array_map( static fn( array $r ): int => (int) $r['attachment_id'], $clean );
			do_action( 'mds_rm_artwork_gallery_saved', $post_id, $ids, $clean );
		}

		return $clean;
	}

	/**
	 * Replicates Hub gallery attachments onto a Multisite Spoke via VirtualMedia.
	 *
	 * @param int $spoke_post_id Spoke Release post ID.
	 * @param int $hub_post_id   Hub Release post ID.
	 * @param int $hub_blog_id   Hub blog ID.
	 * @return void
	 */
	public static function replicate_on_spoke_sync( int $spoke_post_id, int $hub_post_id, int $hub_blog_id ): void {
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			return;
		}
		switch_to_blog( $hub_blog_id );
		$hub_gallery = self::get_gallery( $hub_post_id );
		$resolved    = array();
		foreach ( $hub_gallery as $item ) {
			$hub_att_id = (int) ( $item['attachment_id'] ?? 0 );
			$url        = $hub_att_id > 0 ? (string) wp_get_attachment_url( $hub_att_id ) : '';
			if ( '' === $url ) {
				continue;
			}
			$meta       = wp_get_attachment_metadata( $hub_att_id );
			$resolved[] = array(
				'hub_att_id' => $hub_att_id,
				'url'        => $url,
				'width'      => is_array( $meta ) ? (int) ( $meta['width'] ?? 1000 ) : 1000,
				'height'     => is_array( $meta ) ? (int) ( $meta['height'] ?? 1000 ) : 1000,
				'mime_type'  => (string) get_post_mime_type( $hub_att_id ),
				'asset_type' => (string) ( $item['asset_type'] ?? 'other' ),
				'provider'   => (string) ( $item['provider'] ?? 'local' ),
			);
		}
		restore_current_blog();

		$spoke_gallery = array();
		foreach ( $resolved as $r ) {
			$virtual_id = VirtualMedia::get_or_create_virtual_attachment(
				$r['url'],
				$r['hub_att_id'],
				array(
					'width'       => $r['width'],
					'height'      => $r['height'],
					'mime_type'   => $r['mime_type'],
					'hub_blog_id' => $hub_blog_id,
				)
			);
			if ( $virtual_id > 0 ) {
				$spoke_gallery[] = array(
					'attachment_id' => $virtual_id,
					'asset_type'    => $r['asset_type'],
					'provider'      => $r['provider'],
					'source_url'    => $r['url'],
				);
			}
		}

		self::save_gallery( $spoke_post_id, $spoke_gallery );
	}
}

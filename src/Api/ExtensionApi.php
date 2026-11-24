<?php
/**
 * Public, versioned Extension API facade for MDS Release Manager companion plugins.
 *
 * Companion plugins (such as `mds-bandcamp-integration` and `mds-woocommerce-integration`)
 * should interact with MDS Release Manager exclusively through this facade and documented
 * `mds_rm_*` hooks rather than coupling to internal classes.
 *
 * @package MDS\ReleaseManager
 * @since   2.0.0
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Api;

use MDS\ReleaseManager\Blocks\ReleaseLinks;
use MDS\ReleaseManager\Blocks\ReleaseMeta;
use MDS\ReleaseManager\Core\AudioPlayers;
use MDS\ReleaseManager\Core\Cache;
use MDS\ReleaseManager\Core\ReleaseModel;
use MDS\ReleaseManager\Core\ReleaseSearch;
use MDS\ReleaseManager\Core\Sync;
use MDS\ReleaseManager\Frontend\Assets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strictly typed public contract for MDS Release Manager extensions.
 *
 * @since 2.0.0
 */
final class ExtensionApi {

	/**
	 * Public Extension API semantic version.
	 *
	 * @var string
	 */
	public const API_VERSION = '2.0.0';

	/**
	 * Returns the active Extension API version string.
	 *
	 * @since 2.0.0
	 *
	 * @return string Semantic version string.
	 */
	public static function api_version(): string {
		return self::API_VERSION;
	}

	/**
	 * Determines whether the current site is the catalog Hub (or a single-site install).
	 *
	 * @since 2.0.0
	 *
	 * @return bool True when catalog editing is allowed on this site.
	 */
	public static function is_hub(): bool {
		return Sync::is_hub();
	}

	/**
	 * Returns the Hub (master) blog ID for the current network.
	 *
	 * @since 2.0.0
	 *
	 * @return int Hub blog ID.
	 */
	public static function hub_id(): int {
		return Sync::hub_id();
	}

	/**
	 * Resolves a readable Release post from block attributes, block context, or post ID.
	 *
	 * @since 2.0.0
	 *
	 * @param array<string, mixed> $attributes      Block or shortcode attributes (`releaseId`, `upc`, `upcOverride`).
	 * @param \WP_Block|null       $block           Optional Gutenberg block instance.
	 * @param int|string           $context_post_id Optional current post ID.
	 * @return \WP_Post|null Resolved readable Release post, or null.
	 */
	public static function resolve_release( array $attributes = array(), ?\WP_Block $block = null, int|string $context_post_id = 0 ): ?\WP_Post {
		return ReleaseMeta::resolve_release( $attributes, $block, $context_post_id );
	}

	/**
	 * Resolves a readable Release post by explicit UPC/EAN or context post ID.
	 *
	 * @since 2.0.0
	 *
	 * @param string     $upc             Optional explicit UPC/EAN string.
	 * @param int|string $context_post_id Optional post ID (e.g. a WooCommerce product ID).
	 * @return \WP_Post|null Resolved readable Release post, or null.
	 */
	public static function resolve_post( string $upc = '', int|string $context_post_id = 0 ): ?\WP_Post {
		return ReleaseMeta::resolve_post( $upc, $context_post_id );
	}

	/**
	 * Finds a readable Release post by exact UPC/EAN (leading zeroes significant).
	 *
	 * @since 2.0.0
	 *
	 * @param string $upc Raw or normalized UPC/EAN string.
	 * @return \WP_Post|null Matching readable Release post, or null.
	 */
	public static function find_release_by_upc( string $upc ): ?\WP_Post {
		$normalized = ReleaseModel::normalize_upc( $upc );
		if ( '' === $normalized ) {
			return null;
		}

		return ReleaseMeta::resolve_post( $normalized, 0 );
	}

	/**
	 * Registers an extension post meta key on the `release` (or `artist`) CPT.
	 *
	 * @since 2.0.0
	 *
	 * @param string               $meta_key  Post meta key.
	 * @param array<string, mixed> $args      Optional `register_post_meta()` overrides.
	 * @param string               $post_type Target CPT (`release` or `artist`).
	 * @return bool True when registered.
	 */
	public static function register_extension_meta( string $meta_key, array $args = array(), string $post_type = 'release' ): bool {
		return ReleaseMeta::register_extension_meta( $meta_key, $args, $post_type );
	}

	/**
	 * Searches published Releases with SQL-backed pagination and optional extension meta filtering.
	 *
	 * @since 2.0.0
	 *
	 * @param array<string, mixed> $args {
	 *     Optional search arguments.
	 *
	 *     @type string $search                Search term (title, Artist name, or exact UPC/EAN).
	 *     @type int    $page                  Page number starting at 1.
	 *     @type int    $per_page              Items per page (1-100, default 20).
	 *     @type string $required_meta_key     Optional post meta key that must be non-empty.
	 *     @type string $required_meta_compare Optional meta compare operator (default `!=`).
	 *     @type string $required_meta_value   Optional meta value to compare against (default `''`).
	 * }
	 * @return array{items: array<int, array<string, mixed>>, pages: int, total: int} Paginated picker records.
	 */
	public static function search_releases( array $args = array() ): array {
		return ReleaseSearch::search_paginated( $args );
	}

	/**
	 * Searches published Artists with SQL-backed pagination.
	 *
	 * @since 2.0.0
	 *
	 * @param array<string, mixed> $args {
	 *     Optional search arguments.
	 *
	 *     @type string $search   Search term (Artist title).
	 *     @type int    $page     Page number starting at 1.
	 *     @type int    $per_page Items per page (1-100, default 20).
	 * }
	 * @return array{items: array<int, array<string, mixed>>, pages: int, total: int} Paginated Artist records.
	 */
	public static function search_artists( array $args = array() ): array {
		return ReleaseSearch::search_artists_paginated( $args );
	}

	/**
	 * Broadcasts a single Hub Release or Artist record to configured Spoke sites.
	 *
	 * @since 2.0.0
	 *
	 * @param int    $post_id   Hub post ID.
	 * @param string $post_type Post type (`release` or `artist`).
	 * @return bool|\WP_Error|null True on success, WP_Error on failure, or null when skipped.
	 */
	public static function broadcast_to_spokes( int $post_id, string $post_type ): bool|\WP_Error|null {
		return Sync::broadcast_to_spokes( $post_id, $post_type );
	}

	/**
	 * Broadcasts a batch of Hub Releases/Artists to Spoke sites in minimal blog switches.
	 *
	 * @since 2.0.0
	 *
	 * @param array<int, array{post_id: int, post_type: string}> $items Records to broadcast.
	 * @return array<string, bool|\WP_Error|null> Per-record results keyed by `"{post_type}:{id}"`.
	 */
	public static function broadcast_batch_to_spokes( array $items ): array {
		return Sync::broadcast_batch_to_spokes( $items );
	}

	/**
	 * Creates or resolves a virtual media attachment on the current Spoke site pointing to a Hub image.
	 *
	 * @since 2.0.0
	 *
	 * @param string               $hub_image_url Hub attachment URL.
	 * @param int                  $hub_image_id  Hub attachment post ID.
	 * @param array<string, mixed> $media_info    Optional width, height, mime_type, hub_blog_id.
	 * @return int Virtual attachment ID, or 0 on failure.
	 */
	public static function get_or_create_virtual_attachment( string $hub_image_url, int $hub_image_id, array $media_info = array() ): int {
		return (int) VirtualMedia::get_or_create_virtual_attachment( $hub_image_url, $hub_image_id, $media_info );
	}

	/**
	 * Requests a coalesced (or immediate) cache purge through Core's Cache Manager.
	 *
	 * @since 2.0.0
	 *
	 * @param string               $context Context label (e.g. `woocommerce_link`, `bandcamp_cache_clear`).
	 * @param array<string, mixed> $args    Optional flags: `immediate` (bool), `force` (bool), `blog_id` (int).
	 * @return array{queued: bool, purged: string[], skipped: string[], blog_id: int} Purge result summary.
	 */
	public static function purge_cache( string $context = 'extension', array $args = array() ): array {
		return Cache::purge( $context, $args );
	}

	/**
	 * Enqueues Font Awesome according to Core's configured Font Awesome integration mode.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public static function enqueue_font_awesome(): void {
		Assets::enqueue_font_awesome();
	}

	/**
	 * Renders the Release Meta component for a given context post ID.
	 *
	 * @since 2.0.0
	 *
	 * @param array<string, mixed> $attributes      Optional block attributes.
	 * @param int                  $context_post_id Current post ID (Release or extension context like Product).
	 * @return string Rendered HTML.
	 */
	public static function render_release_meta( array $attributes = array(), int $context_post_id = 0 ): string {
		return (string) ReleaseMeta::render( $attributes, $context_post_id );
	}

	/**
	 * Renders the Release Links component for a given context post ID.
	 *
	 * @since 2.0.0
	 *
	 * @param array<string, mixed> $attributes      Optional block attributes.
	 * @param int                  $context_post_id Current post ID (Release or extension context like Product).
	 * @return string Rendered HTML.
	 */
	public static function render_release_links( array $attributes = array(), int $context_post_id = 0 ): string {
		return ReleaseLinks::render( $attributes, $context_post_id );
	}

	/**
	 * Returns validated site-wide settings for a registered audio player slug.
	 *
	 * @since 2.0.0
	 *
	 * @param string $slug Registered audio player slug.
	 * @return array<string, mixed> Player settings.
	 */
	public static function get_audio_player_settings( string $slug ): array {
		return AudioPlayers::get_player_settings( $slug );
	}

	/**
	 * Returns the dynamic block context attributes for a resolved Release post.
	 *
	 * Extensions filter `mds_rm_dynamic_block_context_attrs` so third-party hosts
	 * (such as WooCommerce product pages) can inject any registered MDS block without
	 * hardcoding another extension's post meta keys.
	 *
	 * @since 2.0.0
	 *
	 * @param string   $block_name Registered block name.
	 * @param \WP_Post $release    Resolved Release post.
	 * @return array<string, mixed> Derived block attributes.
	 */
	public static function resolve_dynamic_block_attrs( string $block_name, \WP_Post $release ): array {
		$attrs = array(
			'releaseId' => (int) $release->ID,
		);

		/**
		 * Filters block attributes derived from a resolved Release post when rendering
		 * an MDS ecosystem block inside an external context (such as a WooCommerce product).
		 *
		 * @since 2.0.0
		 *
		 * @param array<string, mixed> $attrs      Block attributes (pre-populated with `releaseId`).
		 * @param string               $block_name Registered block name (e.g. `mds-bandcamp/bandcamp-album`).
		 * @param \WP_Post             $release    Resolved Release post.
		 */
		$filtered = apply_filters( 'mds_rm_dynamic_block_context_attrs', $attrs, $block_name, $release );
		if ( ! is_array( $filtered ) ) {
			return $attrs;
		}

		$filtered['releaseId'] = (int) $release->ID;
		return $filtered;
	}
}

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
use MDS\ReleaseManager\Core\Audio\AudioApi;
use MDS\ReleaseManager\Core\BrandIcon;
use MDS\ReleaseManager\Core\Cache;
use MDS\ReleaseManager\Core\GatewayBridge;
use MDS\ReleaseManager\Core\GitHubUpdater;
use MDS\ReleaseManager\Core\ReleaseModel;
use MDS\ReleaseManager\Core\ReleaseSearch;
use MDS\ReleaseManager\Core\Security\SecretVault;
use MDS\ReleaseManager\Core\Sync;
use MDS\ReleaseManager\Frontend\Assets;
use MDS\ReleaseManager\Frontend\ThemeScheme;

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
	 * Returns the detected active theme color scheme tokens (Global Styles / `theme.json`).
	 *
	 * Companion plugins can use these WCAG-computed tokens (`themeName`, `mode`, `isDark`,
	 * `background`, `text`, `surface`, `border`, `muted`, `accent`, `accentText`, `stripe`)
	 * to auto-adapt their blocks, widgets, and admin previews to light or dark themes
	 * (including Twenty Twenty-Five Default, Evening, Midnight, and other variations).
	 *
	 * @since 2.0.0
	 *
	 * @return array<string, mixed> Detected theme scheme tokens.
	 */
	public static function get_theme_scheme(): array {
		return ThemeScheme::detect();
	}

	/**
	 * Determines whether the active WordPress theme uses a dark background color scheme.
	 *
	 * @since 2.0.0
	 *
	 * @return bool True when the detected theme background luminance is dark.
	 */
	public static function is_dark_theme(): bool {
		return ! empty( ThemeScheme::detect()['isDark'] );
	}

	/**
	 * Returns the `:root` CSS custom properties declaration for the active theme scheme.
	 *
	 * @since 2.0.0
	 *
	 * @return string CSS rule declaring `--mds-theme-*` custom properties.
	 */
	public static function get_theme_css_variables(): string {
		return ThemeScheme::css_variables();
	}

	/**
	 * Registers a companion plugin for native WordPress `Update URI` updates from GitHub Releases.
	 *
	 * Supports private GitHub repositories via a `wp-config.php` Personal Access Token
	 * (`MDS_RM_GITHUB_TOKEN` or a per-plugin constant) and public repositories with zero token.
	 *
	 * @since 2.0.0
	 *
	 * @param array<string, string> $config Updater config (`basename`, `slug`, `repo`, `version`, optional `name`, `token_constant`).
	 * @return bool True when registered.
	 */
	public static function register_github_updater( array $config ): bool {
		return GitHubUpdater::register( $config );
	}

	/**
	 * Registers a companion plugin with Core's neutral Cloud Gateway Bridge.
	 *
	 * Strictly dormant unless at least one companion plugin registers.
	 *
	 * @since 2.3.0
	 *
	 * @param array<string, string> $config Companion config (`slug`, `basename`, `version`, optional `name`, `api_url`).
	 * @return bool True when registered.
	 */
	public static function register_gateway_plugin( array $config ): bool {
		return GatewayBridge::register_plugin( $config );
	}

	/**
	 * Executes an authenticated RPC call via the suite-wide MDS Cloud Gateway connection.
	 *
	 * @since 2.3.0
	 *
	 * @param string               $service Gateway proxy service slug (e.g. `ai-enrich`, `metadata-lookup`).
	 * @param array<string, mixed> $payload Request parameters.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function gateway_call( string $service, array $payload = array() ) {
		return GatewayBridge::call( $service, $payload );
	}

	/**
	 * Returns safe, masked Cloud Gateway connection status.
	 *
	 * @since 2.3.0
	 *
	 * @return array<string, mixed>
	 */
	public static function get_gateway_status(): array {
		return GatewayBridge::get_status();
	}

	/**
	 * Checks whether the active Cloud Gateway license satisfies a minimum tier.
	 *
	 * @since 2.3.0
	 *
	 * @param string $min_tier Minimum tier (`free`, `pro`, `label`, `agency`).
	 * @return bool
	 */
	public static function has_gateway_tier( string $min_tier ): bool {
		return GatewayBridge::has_tier( $min_tier );
	}

	/**
	 * Resolves a plaintext secret from the centralized Hub SecretVault cascade.
	 *
	 * @since 2.2.0
	 *
	 * @param string               $service Canonical service namespace (e.g. `spotify`, `github`).
	 * @param string               $field   Field key (e.g. `client_id`, `client_secret`, `token`).
	 * @param array<string, mixed> $options Optional `env` and `const` override name lists.
	 * @return string Plaintext secret, or empty string if unset.
	 */
	public static function get_secret( string $service, string $field, array $options = array() ): string {
		return SecretVault::get( $service, $field, $options );
	}

	/**
	 * Resolves a secret along with source, cipher, and key metadata.
	 *
	 * @since 2.2.0
	 *
	 * @param string               $service Canonical service namespace.
	 * @param string               $field   Field key.
	 * @param array<string, mixed> $options Optional `env` and `const` override name lists.
	 * @return array{value: string, source: string, cipher: string, key_id: string, needs_rotation: bool}
	 */
	public static function get_secret_with_meta( string $service, string $field, array $options = array() ): array {
		return SecretVault::get_with_meta( $service, $field, $options );
	}

	/**
	 * Encrypts and stores a secret in the centralized Hub SecretVault.
	 *
	 * @since 2.2.0
	 *
	 * @param string $service   Canonical service namespace.
	 * @param string $field     Field key.
	 * @param string $plaintext Secret value to store (empty string deletes the entry).
	 * @return array{saved: bool, cipher: string, key_id: string, security_level: string}
	 */
	public static function set_secret( string $service, string $field, string $plaintext ): array {
		return SecretVault::set( $service, $field, $plaintext );
	}

	/**
	 * Deletes a secret from the centralized Hub SecretVault.
	 *
	 * @since 2.2.0
	 *
	 * @param string $service Canonical service namespace.
	 * @param string $field   Field key.
	 * @return bool True on success.
	 */
	public static function delete_secret( string $service, string $field ): bool {
		return SecretVault::delete( $service, $field );
	}

	/**
	 * Returns safe, masked status metadata for a secret (never exposes plaintext).
	 *
	 * @since 2.2.0
	 *
	 * @param string               $service Canonical service namespace.
	 * @param string               $field   Field key.
	 * @param array<string, mixed> $options Optional `env`, `const`, and `is_secret` flags.
	 * @return array{configured: bool, source: string, masked: string, cipher: string, key_id: string, is_override: bool}
	 */
	public static function get_secret_status( string $service, string $field, array $options = array() ): array {
		return SecretVault::get_status( $service, $field, $options );
	}

	/**
	 * Returns the current cryptographic security posture of the centralized Hub SecretVault.
	 *
	 * @since 2.2.0
	 *
	 * @return array<string, mixed> Security posture descriptor.
	 */
	public static function get_secret_vault_posture(): array {
		return SecretVault::get_security_posture();
	}

	/**
	 * Returns inline SVG markup for the official Musty Dungeon Studios (MDS) logo.
	 *
	 * @since 2.2.0
	 *
	 * @param array<string, string> $attrs Optional SVG attributes (`class`, `width`, `height`, `fill`, `aria-hidden`, `aria-label`).
	 * @return string Inline `<svg>` markup.
	 */
	public static function get_brand_icon_svg( array $attrs = array() ): string {
		return BrandIcon::svg( $attrs );
	}

	/**
	 * Returns a base64 SVG data URI of the official MDS logo for WP Admin `menu_icon`.
	 *
	 * @since 2.2.0
	 *
	 * @param string $fill SVG fill color (default `black` for WP Admin color-scheme tinting).
	 * @return string Base64 data URI string.
	 */
	public static function get_brand_icon_data_uri( string $fill = 'black' ): string {
		return BrandIcon::data_uri( $fill );
	}

	/**
	 * Returns the public URL to the canonical MDS SVG logo (`assets/icon.svg`).
	 *
	 * @since 2.2.0
	 *
	 * @return string Public URL.
	 */
	public static function get_brand_icon_url(): string {
		return BrandIcon::url();
	}

	/**
	 * Returns WordPress `plugins_api` / updater `icons` URLs (`svg`, `1x`, `2x`, `default`).
	 *
	 * @since 2.2.0
	 *
	 * @param string $slug Optional plugin slug to check for plugin-local `assets/` icons first.
	 * @return array<string, string> Icon URLs keyed by size.
	 */
	public static function get_brand_updater_icons( string $slug = '' ): array {
		return BrandIcon::updater_icons( $slug );
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
	 * Renders the mobile-first Unified Release Player for a given context post ID.
	 *
	 * @since 1.2.0
	 *
	 * @param array<string, mixed> $attributes      Optional player attributes.
	 * @param int                  $context_post_id Current post ID.
	 * @return string Rendered HTML.
	 */
	public static function render_release_player( array $attributes = array(), int $context_post_id = 0 ): string {
		return \MDS\ReleaseManager\Blocks\ReleasePlayer::render( $attributes, null, $context_post_id );
	}

	/**
	 * Resolves a normalized audio preview manifest for a release via the unified Audio API.
	 *
	 * @since 1.2.0
	 *
	 * @param int                  $post_id Release post ID.
	 * @param array<string, mixed> $args    Optional resolution args (`provider`, `fresh`, `allow_fallback`).
	 * @return array<string, mixed>|null Normalized AudioManifest, or null if unavailable.
	 */
	public static function resolve_audio( int $post_id, array $args = array() ): ?array {
		return AudioApi::resolve( $post_id, $args );
	}

	/**
	 * Performs a fast local check (no HTTP calls) for whether a release has playable audio.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $post_id  Release post ID.
	 * @param string $provider Optional explicit provider slug.
	 * @return bool True when at least one enabled provider supports the release.
	 */
	public static function has_audio( int $post_id, string $provider = '' ): bool {
		return AudioApi::has_audio( $post_id, $provider );
	}

	/**
	 * Returns serializable descriptors for all registered audio preview providers.
	 *
	 * @since 1.2.0
	 *
	 * @return array<int, array{slug: string, label: string, priority: int, enabled: bool}> Provider descriptors.
	 */
	public static function get_audio_providers(): array {
		return AudioApi::get_provider_descriptors();
	}

	/**
	 * Invalidates cached audio manifests for a release across all providers.
	 *
	 * @since 1.2.0
	 *
	 * @param int $post_id Release post ID.
	 * @return void
	 */
	public static function invalidate_audio_cache( int $post_id ): void {
		AudioApi::invalidate_release( $post_id );
	}

	/**
	 * Resets in-memory audio provider caches after provider settings change.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public static function reset_audio_cache(): void {
		AudioApi::reset_cache();
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

	/**
	 * Reads the canonical tracklist for a Release post (with legacy fallback).
	 *
	 * @since 2.2.0
	 *
	 * @param int $post_id Release post ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_tracklist( int $post_id ): array {
		return \MDS\ReleaseManager\Core\TracklistStore::get_tracklist( $post_id );
	}

	/**
	 * Saves the canonical tracklist for a Release post.
	 *
	 * @since 2.2.0
	 *
	 * @param int   $post_id Release post ID.
	 * @param mixed $raw     Tracklist array or JSON string.
	 * @return array<int, array<string, mixed>>
	 */
	public static function save_tracklist( int $post_id, $raw ): array {
		return \MDS\ReleaseManager\Core\TracklistStore::save_tracklist( $post_id, $raw );
	}

	/**
	 * Reads the Release Artwork Gallery entries (with legacy fallback).
	 *
	 * @since 2.2.0
	 *
	 * @param int $post_id Release post ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_artwork_gallery( int $post_id ): array {
		return \MDS\ReleaseManager\Core\ArtworkGallery::get_gallery( $post_id );
	}

	/**
	 * Returns ordered attachment IDs for the Release Artwork Gallery (for WooCommerce / themes).
	 *
	 * @since 2.2.0
	 *
	 * @param int $post_id Release post ID.
	 * @return int[]
	 */
	public static function get_artwork_gallery_ids( int $post_id ): array {
		return \MDS\ReleaseManager\Core\ArtworkGallery::get_gallery_ids( $post_id );
	}

	/**
	 * Saves the ordered Release Artwork Gallery.
	 *
	 * @since 2.2.0
	 *
	 * @param int                              $post_id Release post ID.
	 * @param array<int, array<string, mixed>> $items   Ordered gallery items.
	 * @return array<int, array<string, mixed>>
	 */
	public static function save_artwork_gallery( int $post_id, array $items ): array {
		return \MDS\ReleaseManager\Core\ArtworkGallery::save_gallery( $post_id, $items );
	}

	/**
	 * Reads structured song lyrics for a Release post (with legacy fallback).
	 *
	 * @since 2.2.0
	 *
	 * @param int $post_id Release post ID.
	 * @return array{schema_version:int,tracks:array<string,array<string,mixed>>}
	 */
	public static function get_lyrics( int $post_id ): array {
		return \MDS\ReleaseManager\Core\LyricsStore::get( $post_id );
	}

	/**
	 * Saves structured song lyrics for a Release post.
	 *
	 * @since 2.2.0
	 *
	 * @param int   $post_id Release post ID.
	 * @param mixed $raw     Lyrics array or JSON string.
	 * @return array{schema_version:int,tracks:array<string,array<string,mixed>>}
	 */
	public static function save_lyrics( int $post_id, $raw ): array {
		return \MDS\ReleaseManager\Core\LyricsStore::save( $post_id, $raw );
	}
}

<?php
/**
 * Official Musty Dungeon Studios (MDS) brand icon helper.
 *
 * Provides a single source of truth for rendering `assets/icon.svg` across
 * WordPress admin menus, Gutenberg blocks, React panels, page builders, and
 * plugin updater modals without duplicating raw SVG markup across plugins.
 *
 * @package   MDS\ReleaseManager
 * @copyright 2026 Musty Dungeon Studios (Walton International Business Group LLC)
 * @license   GPL-3.0-or-later
 * @since     2.2.0
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralized helper for the official MDS SVG/PNG brand marks.
 *
 * @since 2.2.0
 */
final class BrandIcon {

	/**
	 * Cached raw SVG contents keyed by absolute path.
	 *
	 * @var array<string, string>
	 */
	private static array $raw_cache = array();

	/**
	 * Returns the absolute filesystem path to the canonical `assets/icon.svg`.
	 *
	 * @since 2.2.0
	 *
	 * @return string Absolute path to `assets/icon.svg`.
	 */
	public static function svg_path(): string {
		$root = defined( 'MDS_RM_PATH' ) ? MDS_RM_PATH : dirname( __DIR__, 2 ) . '/';
		return $root . 'assets/icon.svg';
	}

	/**
	 * Returns the public URL to the canonical `assets/icon.svg`.
	 *
	 * @since 2.2.0
	 *
	 * @return string Public URL to `assets/icon.svg`.
	 */
	public static function url(): string {
		$base = defined( 'MDS_RM_URL' ) ? MDS_RM_URL : '';
		return '' !== $base ? $base . 'assets/icon.svg' : '';
	}

	/**
	 * Returns an inline `<svg>` string of the official MDS logo.
	 *
	 * Uses `currentColor` by default so light/dark themes and WP Admin color
	 * schemes tint the icon automatically, and `aria-hidden="true"` for Section 508
	 * compliance when paired with visible labels.
	 *
	 * @since 2.2.0
	 *
	 * @param array<string, string> $attrs Optional attributes (`class`, `width`, `height`, `fill`, `aria-hidden`, `aria-label`).
	 * @return string Inline `<svg>` markup, or empty string if the SVG asset is unreadable.
	 */
	public static function svg( array $attrs = array() ): string {
		$raw = self::read_raw_svg();
		if ( '' === $raw ) {
			return '';
		}

		$fill        = isset( $attrs['fill'] ) && '' !== $attrs['fill'] ? $attrs['fill'] : 'currentColor';
		$class       = isset( $attrs['class'] ) ? trim( $attrs['class'] ) : 'mds-brand-icon-svg';
		$width       = isset( $attrs['width'] ) && '' !== $attrs['width'] ? $attrs['width'] : '20';
		$height      = isset( $attrs['height'] ) && '' !== $attrs['height'] ? $attrs['height'] : '20';
		$aria_hidden = $attrs['aria-hidden'] ?? 'true';
		$aria_label  = isset( $attrs['aria-label'] ) ? trim( $attrs['aria-label'] ) : '';

		// Strip XML prolog and normalize fill/stroke for clean theming.
		$svg = (string) preg_replace( '/<\?xml[^>]*\?>\s*/i', '', $raw );
		$svg = str_replace(
			array( 'fill="#000000"', 'stroke="#000000"' ),
			array( 'fill="' . esc_attr( $fill ) . '"', 'stroke="' . esc_attr( $fill ) . '"' ),
			$svg
		);

		$attr_parts = array(
			'xmlns="http://www.w3.org/2000/svg"',
			'viewBox="0 0 1997 2000"',
			'width="' . esc_attr( $width ) . '"',
			'height="' . esc_attr( $height ) . '"',
			'focusable="false"',
		);
		if ( '' !== $class ) {
			$attr_parts[] = 'class="' . esc_attr( $class ) . '"';
		}
		if ( '' !== $aria_label ) {
			$attr_parts[] = 'role="img"';
			$attr_parts[] = 'aria-label="' . esc_attr( $aria_label ) . '"';
		} elseif ( 'false' !== $aria_hidden ) {
			$attr_parts[] = 'aria-hidden="true"';
		}

		$svg = (string) preg_replace(
			'/<svg\b[^>]*>/i',
			'<svg ' . implode( ' ', $attr_parts ) . '>',
			$svg,
			1
		);

		return trim( $svg );
	}

	/**
	 * Returns a base64-encoded `data:image/svg+xml;base64,...` URI suitable for
	 * WordPress `register_post_type()` / `add_menu_page()` `menu_icon`.
	 *
	 * @since 2.2.0
	 *
	 * @param string $fill SVG fill color (default `black`, which WordPress `svg-painter.js` tints per admin color scheme).
	 * @return string Data URI string, or empty string if the SVG asset is unreadable.
	 */
	public static function data_uri( string $fill = 'black' ): string {
		$svg = self::svg(
			array(
				'fill'   => $fill,
				'width'  => '20',
				'height' => '20',
				'class'  => '',
			)
		);

		if ( '' === $svg ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required by WordPress menu_icon SVG data URI API.
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * Returns WordPress `plugins_api` / `update_plugins` `icons` metadata for an MDS plugin.
	 *
	 * Checks the target plugin's own `assets/` directory first, then falls back to Core's `assets/`.
	 *
	 * @since 2.2.0
	 *
	 * @param string $slug Optional plugin directory slug (e.g. `mds-commerce`).
	 * @return array<string, string> Associative array of icon URLs (`svg`, `1x`, `2x`, `default`).
	 */
	public static function updater_icons( string $slug = '' ): array {
		$slug     = sanitize_key( $slug );
		$root     = defined( 'MDS_RM_PATH' ) ? MDS_RM_PATH : dirname( __DIR__, 2 ) . '/';
		$url_root = defined( 'MDS_RM_URL' ) ? MDS_RM_URL : '';
		$base_dir = $root . 'assets/';
		$base_url = '' !== $url_root ? $url_root . 'assets/' : '';

		if ( '' !== $slug && defined( 'WP_PLUGIN_DIR' ) && function_exists( 'plugins_url' ) ) {
			$candidate_dir = trailingslashit( WP_PLUGIN_DIR ) . $slug . '/assets/';
			if ( file_exists( $candidate_dir . 'icon.svg' ) || file_exists( $candidate_dir . 'icon-256x256.png' ) ) {
				$base_dir = $candidate_dir;
				$base_url = trailingslashit( plugins_url( $slug . '/assets' ) );
			}
		}

		$icons = array();
		if ( file_exists( $base_dir . 'icon.svg' ) ) {
			$icons['svg'] = $base_url . 'icon.svg';
		}
		if ( file_exists( $base_dir . 'icon-128x128.png' ) ) {
			$icons['1x'] = $base_url . 'icon-128x128.png';
		}
		if ( file_exists( $base_dir . 'icon-256x256.png' ) ) {
			$icons['2x'] = $base_url . 'icon-256x256.png';
		}

		$default = $icons['svg'] ?? ( $icons['2x'] ?? ( $icons['1x'] ?? self::url() ) );
		if ( '' !== $default ) {
			$icons['default'] = $default;
		}

		return $icons;
	}

	/**
	 * Reads and caches the raw SVG file contents.
	 *
	 * @return string Raw SVG XML string, or empty string if unreadable.
	 */
	private static function read_raw_svg(): string {
		$path = self::svg_path();
		if ( isset( self::$raw_cache[ $path ] ) ) {
			return self::$raw_cache[ $path ];
		}

		if ( ! file_exists( $path ) ) {
			self::$raw_cache[ $path ] = '';
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local static plugin SVG asset.
		$contents                 = (string) file_get_contents( $path );
		self::$raw_cache[ $path ] = $contents;
		return $contents;
	}
}

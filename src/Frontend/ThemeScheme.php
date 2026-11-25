<?php
/**
 * Detects the active WordPress theme color scheme via Global Styles (`theme.json`).
 *
 * Automatically derives WCAG-compliant light or dark surface, border, muted-text,
 * and accent tokens for Twenty Twenty-Five variations (Default, Evening, Midnight,
 * Afternoon, Sunrise, etc.) as well as any other block or classic theme.
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves theme-adaptive color tokens and designer presets from WordPress Global Styles.
 *
 * @since 1.2.0
 */
final class ThemeScheme {

	/**
	 * Detects the active theme's color scheme and derives adaptive block tokens.
	 *
	 * @return array<string, mixed> Scheme metadata and resolved hex tokens.
	 */
	public static function detect(): array {
		static $cached = null;
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$theme_name = '';
		if ( function_exists( 'wp_get_theme' ) ) {
			$theme = wp_get_theme();
			if ( is_object( $theme ) && method_exists( $theme, 'get' ) ) {
				$theme_name = (string) $theme->get( 'Name' );
			}
		}

		$palette_map = self::palette_map();
		$styles      = function_exists( 'wp_get_global_styles' ) ? wp_get_global_styles() : array();
		$styles      = is_array( $styles ) ? $styles : array();

		$raw_bg     = $styles['color']['background'] ?? '';
		$raw_text   = $styles['color']['text'] ?? '';
		$raw_accent = $styles['elements']['button']['color']['background']
			?? ( $styles['elements']['link']['color']['text'] ?? '' );

		$bg = self::resolve_hex( $raw_bg, $palette_map );
		if ( '' === $bg && function_exists( 'get_background_color' ) ) {
			$classic_bg = trim( (string) get_background_color() );
			if ( '' !== $classic_bg ) {
				$bg = self::normalize_hex( '#' . ltrim( $classic_bg, '#' ) );
			}
		}
		if ( '' === $bg ) {
			$bg = self::resolve_hex( $palette_map['base'] ?? '', $palette_map );
		}
		if ( '' === $bg ) {
			$bg = '#ffffff';
		}

		$is_dark = self::luminance( $bg ) < 0.25;

		$text = self::resolve_hex( $raw_text, $palette_map );
		if ( '' === $text ) {
			$text = self::resolve_hex( $palette_map['contrast'] ?? '', $palette_map );
		}
		if ( '' === $text ) {
			$text = $is_dark ? '#f3f4f6' : '#1d2327';
		}

		$accent = self::resolve_hex( $raw_accent, $palette_map );
		if ( '' === $accent ) {
			foreach ( array( 'accent-1', 'accent-3', 'accent', 'primary', 'secondary' ) as $slug ) {
				if ( isset( $palette_map[ $slug ] ) ) {
					$candidate = self::normalize_hex( $palette_map[ $slug ] );
					if ( '' !== $candidate && self::contrast_ratio( $candidate, $bg ) >= 2.2 ) {
						$accent = $candidate;
						break;
					}
				}
			}
		}
		if ( '' === $accent ) {
			$accent = $is_dark ? '#7dd3fc' : '#2271b1';
		}

		$accent_text = self::contrast_text( $accent );

		$surface     = self::mix( $bg, $text, $is_dark ? 0.08 : 0.04 );
		$border      = self::mix( $bg, $text, $is_dark ? 0.20 : 0.16 );
		$muted       = self::mix( $bg, $text, $is_dark ? 0.70 : 0.65 );
		$meta_bg     = self::mix( $bg, $text, $is_dark ? 0.10 : 0.06 );
		$meta_border = self::mix( $bg, $text, $is_dark ? 0.18 : 0.14 );
		$stripe      = self::mix( $bg, $text, $is_dark ? 0.06 : 0.04 );

		$scheme = array(
			'themeName'  => '' !== $theme_name ? $theme_name : __( 'Active Theme', 'mds-release-manager' ),
			'mode'       => $is_dark ? 'dark' : 'light',
			'isDark'     => $is_dark,
			'background' => $bg,
			'text'       => $text,
			'surface'    => $surface,
			'border'     => $border,
			'muted'      => $muted,
			'accent'     => $accent,
			'accentText' => $accent_text,
			'metaBg'     => $meta_bg,
			'metaText'   => $text,
			'metaBorder' => $meta_border,
			'stripe'     => $stripe,
		);

		/**
		 * Filters the detected theme color scheme tokens used for adaptive block defaults.
		 *
		 * @since 1.2.0
		 * @param array<string, mixed> $scheme Detected scheme tokens.
		 */
		$filtered = apply_filters( 'mds_rm_theme_scheme', $scheme );
		$cached   = is_array( $filtered ) ? $filtered : $scheme;

		return $cached;
	}

	/**
	 * Builds the `:root` CSS custom properties block injected on frontend and settings screens.
	 *
	 * @return string Valid CSS rule declaring `--mds-theme-*` custom properties.
	 */
	public static function css_variables(): string {
		$s = self::detect();
		return sprintf(
			':root{--mds-theme-bg:%1$s;--mds-theme-text:%2$s;--mds-theme-surface:%3$s;--mds-theme-border:%4$s;--mds-theme-muted:%5$s;--mds-theme-accent:%6$s;--mds-theme-accent-text:%7$s;--mds-theme-meta-bg:%8$s;--mds-theme-meta-text:%9$s;--mds-theme-meta-border:%10$s;--mds-theme-stripe:%11$s;}',
			esc_attr( (string) $s['background'] ),
			esc_attr( (string) $s['text'] ),
			esc_attr( (string) $s['surface'] ),
			esc_attr( (string) $s['border'] ),
			esc_attr( (string) $s['muted'] ),
			esc_attr( (string) $s['accent'] ),
			esc_attr( (string) $s['accentText'] ),
			esc_attr( (string) $s['metaBg'] ),
			esc_attr( (string) $s['metaText'] ),
			esc_attr( (string) $s['metaBorder'] ),
			esc_attr( (string) $s['stripe'] )
		);
	}

	/**
	 * Returns designer-ready quick presets for the Block Defaults settings screen.
	 *
	 * Includes a dynamic "Auto-Match Active Theme" option (clears overrides so blocks
	 * track theme/variation switches automatically) plus curated TT5 Default, TT5 Evening
	 * (Dark), and High Contrast presets.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function presets(): array {
		return array(
			array(
				'id'          => 'auto',
				'label'       => __( 'Auto-Match Theme', 'mds-release-manager' ),
				'description' => __( 'Clears explicit color overrides so blocks automatically adapt whenever you switch themes or Twenty Twenty-Five color variations.', 'mds-release-manager' ),
				'blocks'      => array(
					'release-meta'       => array( 'metaStyles' => null ),
					'release-links'      => array( 'linkStyles' => null ),
					'release-player'     => array(
						'backgroundColor' => null,
						'textColor'       => null,
						'mutedColor'      => null,
						'accentColor'     => null,
						'playIconColor'   => null,
						'borderColor'     => null,
						'shadow'          => null,
					),
					'artist-discography' => array(
						'style'       => null,
						'tableStyles' => null,
					),
				),
			),
			array(
				'id'          => 'tt5-light',
				'label'       => __( 'Twenty Twenty-Five — Light', 'mds-release-manager' ),
				'description' => __( 'Clean editorial surfaces tuned for the default light Twenty Twenty-Five palette.', 'mds-release-manager' ),
				'blocks'      => array(
					'release-meta'       => array(
						'metaStyles' => array(
							'background'   => '#f4f4f0',
							'color'        => '#111111',
							'icon_color'   => '#555550',
							'border_style' => 'solid',
							'border_width' => '1px',
							'border_color' => '#dcdcd4',
							'radius'       => '6px',
						),
					),
					'release-links'      => array(
						'linkStyles' => array(
							'button_color'        => '#ffffff',
							'button_radius'       => '50%',
							'button_border_style' => 'none',
						),
					),
					'release-player'     => array(
						'backgroundColor' => '#f8f8f5',
						'textColor'       => '#111111',
						'mutedColor'      => '#575752',
						'accentColor'     => '#111111',
						'playIconColor'   => '#ffffff',
						'borderColor'     => '#dcdcd4',
						'borderRadius'    => 10,
						'borderWidth'     => 1,
						'shadow'          => 'subtle',
					),
					'artist-discography' => array(
						'tableStyles' => array(
							'striped'           => true,
							'header-background' => '#f4f4f0',
							'header-color'      => '#111111',
							'body-color'        => '#111111',
							'stripe-background' => '#fafaf7',
							'cell-border-style' => 'solid',
							'cell-border-width' => '1px',
							'cell-border-color' => '#e5e5de',
							'link-color'        => '#111111',
						),
					),
				),
			),
			array(
				'id'          => 'tt5-evening',
				'label'       => __( 'Twenty Twenty-Five — Evening (Dark)', 'mds-release-manager' ),
				'description' => __( 'Deep charcoal surfaces, crisp subtle borders, and luminous accents tailored for the Twenty Twenty-Five Evening variation and dark themes.', 'mds-release-manager' ),
				'blocks'      => array(
					'release-meta'       => array(
						'metaStyles' => array(
							'background'   => '#1e1f24',
							'color'        => '#f3f4f6',
							'icon_color'   => '#a1a1aa',
							'border_style' => 'solid',
							'border_width' => '1px',
							'border_color' => '#32343d',
							'radius'       => '6px',
						),
					),
					'release-links'      => array(
						'linkStyles' => array(
							'button_color'        => '#ffffff',
							'button_radius'       => '50%',
							'button_border_style' => 'solid',
							'button_border_width' => '1px',
							'button_border_color' => '#3f424e',
						),
					),
					'release-player'     => array(
						'backgroundColor' => '#1c1d22',
						'textColor'       => '#f3f4f6',
						'mutedColor'      => '#a1a1aa',
						'accentColor'     => '#e5e7eb',
						'playIconColor'   => '#111827',
						'borderColor'     => '#32343d',
						'borderRadius'    => 10,
						'borderWidth'     => 1,
						'shadow'          => 'elevated',
					),
					'artist-discography' => array(
						'tableStyles' => array(
							'striped'           => true,
							'header-background' => '#1c1d22',
							'header-color'      => '#f3f4f6',
							'body-color'        => '#e5e7eb',
							'stripe-background' => '#24262d',
							'cell-border-style' => 'solid',
							'cell-border-width' => '1px',
							'cell-border-color' => '#32343d',
							'link-color'        => '#f9fafb',
						),
					),
				),
			),
		);
	}

	/**
	 * Maps active theme/custom/default palette slugs to hex color strings.
	 *
	 * @return array<string, string>
	 */
	private static function palette_map(): array {
		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return array();
		}
		$palettes = wp_get_global_settings( array( 'color', 'palette' ) );
		if ( ! is_array( $palettes ) ) {
			return array();
		}
		$map = array();
		foreach ( array( 'default', 'theme', 'custom' ) as $origin ) {
			if ( ! isset( $palettes[ $origin ] ) || ! is_array( $palettes[ $origin ] ) ) {
				continue;
			}
			foreach ( $palettes[ $origin ] as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['slug'] ) || empty( $entry['color'] ) ) {
					continue;
				}
				$hex = self::normalize_hex( (string) $entry['color'] );
				if ( '' !== $hex ) {
					$map[ (string) $entry['slug'] ] = $hex;
				}
			}
		}
		return $map;
	}

	/**
	 * Resolves a WordPress Global Styles color reference or literal hex into a 6-digit `#rrggbb` string.
	 *
	 * @param mixed                 $raw         Raw style value.
	 * @param array<string, string> $palette_map Slug-to-hex lookup table.
	 * @return string Normalized `#rrggbb` hex color or empty string.
	 */
	private static function resolve_hex( $raw, array $palette_map ): string {
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return '';
		}
		$value = trim( $raw );
		if ( preg_match( '/^var:preset\|color\|([a-z0-9_-]+)$/i', $value, $m ) ) {
			return $palette_map[ strtolower( $m[1] ) ] ?? ( $palette_map[ $m[1] ] ?? '' );
		}
		if ( preg_match( '/^var\(--wp--preset--color--([a-z0-9_-]+)\)$/i', $value, $m ) ) {
			return $palette_map[ strtolower( $m[1] ) ] ?? ( $palette_map[ $m[1] ] ?? '' );
		}
		return self::normalize_hex( $value );
	}

	/**
	 * Normalizes a 3-digit or 6-digit hex color string into lowercase `#rrggbb`.
	 *
	 * @param string $color Candidate color string.
	 * @return string Normalized hex color or empty string.
	 */
	public static function normalize_hex( string $color ): string {
		$color = trim( $color );
		if ( preg_match( '/^#([a-f0-9]{3})$/i', $color, $m ) ) {
			$h = strtolower( $m[1] );
			return '#' . $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
		}
		if ( preg_match( '/^#([a-f0-9]{6})$/i', $color, $m ) ) {
			return '#' . strtolower( $m[1] );
		}
		return '';
	}

	/**
	 * Computes WCAG 2.1 relative luminance (0.0 = black, 1.0 = white).
	 *
	 * @param string $hex Normalized `#rrggbb` color.
	 * @return float Relative luminance.
	 */
	public static function luminance( string $hex ): float {
		$hex = self::normalize_hex( $hex );
		if ( '' === $hex ) {
			return 1.0;
		}
		$channels = array(
			hexdec( substr( $hex, 1, 2 ) ) / 255,
			hexdec( substr( $hex, 3, 2 ) ) / 255,
			hexdec( substr( $hex, 5, 2 ) ) / 255,
		);
		$linear   = array();
		foreach ( $channels as $c ) {
			$linear[] = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		}
		return ( 0.2126 * $linear[0] ) + ( 0.7152 * $linear[1] ) + ( 0.0722 * $linear[2] );
	}

	/**
	 * Computes WCAG contrast ratio between two hex colors (1.0 to 21.0).
	 *
	 * @param string $hex_a First hex color.
	 * @param string $hex_b Second hex color.
	 * @return float Contrast ratio.
	 */
	public static function contrast_ratio( string $hex_a, string $hex_b ): float {
		$l1 = self::luminance( $hex_a );
		$l2 = self::luminance( $hex_b );
		return ( max( $l1, $l2 ) + 0.05 ) / ( min( $l1, $l2 ) + 0.05 );
	}

	/**
	 * Picks `#ffffff` or `#111827` for maximum contrast against a given background hex color.
	 *
	 * @param string $bg_hex Background hex color.
	 * @return string `#ffffff` or `#111827`.
	 */
	public static function contrast_text( string $bg_hex ): string {
		$white_ratio = self::contrast_ratio( $bg_hex, '#ffffff' );
		$dark_ratio  = self::contrast_ratio( $bg_hex, '#111827' );
		return $white_ratio >= $dark_ratio ? '#ffffff' : '#111827';
	}

	/**
	 * Linearly blends two `#rrggbb` colors (`$weight` of `$mix_hex` into `$base_hex`).
	 *
	 * @param string $base_hex Base hex color.
	 * @param string $mix_hex  Target hex color to blend in.
	 * @param float  $weight   Blend ratio between 0.0 and 1.0.
	 * @return string Blended `#rrggbb` color.
	 */
	public static function mix( string $base_hex, string $mix_hex, float $weight ): string {
		$base = self::normalize_hex( $base_hex );
		$mix  = self::normalize_hex( $mix_hex );
		if ( '' === $base || '' === $mix ) {
			return '' !== $base ? $base : '#ffffff';
		}
		$w = max( 0.0, min( 1.0, $weight ) );
		$r = (int) round( ( hexdec( substr( $base, 1, 2 ) ) * ( 1.0 - $w ) ) + ( hexdec( substr( $mix, 1, 2 ) ) * $w ) );
		$g = (int) round( ( hexdec( substr( $base, 3, 2 ) ) * ( 1.0 - $w ) ) + ( hexdec( substr( $mix, 3, 2 ) ) * $w ) );
		$b = (int) round( ( hexdec( substr( $base, 5, 2 ) ) * ( 1.0 - $w ) ) + ( hexdec( substr( $mix, 5, 2 ) ) * $w ) );
		return sprintf( '#%02x%02x%02x', $r, $g, $b );
	}
}

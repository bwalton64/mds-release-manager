<?php
/**
 * Site-wide Default Templates settings for Release and Artist fallback pages.
 *
 * Replaces the legacy Customizer panel with a theme-independent option
 * (`mds_rm_template_defaults`) while preserving automatic read-fallback to
 * previously saved `theme_mod` values.
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages validation, migration fallback, and CSS variable output for
 * Release and Artist fallback templates.
 *
 * @since 1.1.0
 */
final class TemplateDefaults {

	/**
	 * Option name storing site-wide default template settings.
	 *
	 * @var string
	 */
	public const OPTION_NAME = 'mds_rm_template_defaults';

	/**
	 * Returns the canonical default values for both templates.
	 *
	 * @return array{release: array<string, mixed>, artist: array<string, mixed>}
	 */
	public static function defaults(): array {
		return array(
			'release' => array(
				'layout'              => 'split',
				'split_ratio'         => '40_60',
				'image_position'      => 'left',
				'sticky_image'        => false,
				'mobile_order'        => 'image_first',
				'max_width'           => 1200,
				'padding_vertical'    => 0,
				'padding_horizontal'  => 0,
				'column_gap'          => 40,
				'component_gap'       => 25,
				'container_bg'        => '',
				'container_border'    => '',
				'container_radius'    => 0,
				'show_image'          => true,
				'image_aspect'        => 'auto',
				'image_radius'        => 8,
				'image_shadow'        => 'default',
				'image_border_color'  => '',
				'component_order'     => array( 'meta', 'player', 'body', 'links' ),
				'show_meta'           => true,
				'show_player'         => true,
				'show_links'          => true,
				'content_font_family' => '',
				'content_font_size'   => 16,
				'content_line_height' => 160,
				'content_color'       => '',
				'content_align'       => 'left',
			),
			'artist'  => array(
				'layout'               => 'stacked',
				'portrait_align'       => 'center',
				'section_order'        => 'discography_first',
				'width'                => 900,
				'gap'                  => 25,
				'padding_vertical'     => 40,
				'padding_horizontal'   => 20,
				'container_bg'         => '',
				'container_border'     => '',
				'container_radius'     => 0,
				'show_portrait'        => true,
				'image_size'           => 300,
				'image_aspect'         => '1/1',
				'image_radius'         => 50,
				'image_shadow'         => 'default',
				'image_border_color'   => '',
				'body_font_family'     => '',
				'body_font_size'       => 0,
				'body_line_height'     => 160,
				'body_color'           => '',
				'body_align'           => 'left',
				'show_discography'     => true,
				'discography_heading'  => '',
				'show_contact'         => true,
				'contact_heading'      => 'Contact Information',
				'contact_padding'      => 20,
				'contact_radius'       => 8,
				'contact_background'   => '',
				'contact_color'        => '',
				'contact_border_color' => '',
				'contact_border_width' => 0,
			),
		);
	}

	/**
	 * Returns merged settings for both templates, falling back to legacy
	 * `theme_mod` values when the option has not yet been saved.
	 *
	 * @return array{release: array<string, mixed>, artist: array<string, mixed>}
	 */
	public static function get_all(): array {
		$defaults = self::defaults();
		$stored   = get_option( self::OPTION_NAME, null );

		if ( is_array( $stored ) ) {
			$release                    = array_merge( $defaults['release'], is_array( $stored['release'] ?? null ) ? $stored['release'] : array() );
			$release['component_order'] = self::normalize_release_component_order( $release['component_order'] ?? null );
			return array(
				'release' => $release,
				'artist'  => array_merge( $defaults['artist'], is_array( $stored['artist'] ?? null ) ? $stored['artist'] : array() ),
			);
		}

		// Automatic read-fallback from legacy Customizer theme_mod keys.
		$legacy                     = self::read_legacy_theme_mods();
		$release                    = array_merge( $defaults['release'], $legacy['release'] );
		$release['component_order'] = self::normalize_release_component_order( $release['component_order'] ?? null );
		return array(
			'release' => $release,
			'artist'  => array_merge( $defaults['artist'], $legacy['artist'] ),
		);
	}

	/**
	 * Normalizes release component order from either the canonical array or legacy enum strings.
	 *
	 * Ensures all four canonical slots (`meta`, `player`, `body`, `links`) are present exactly once.
	 *
	 * @since 2.0.2
	 * @param mixed $value Saved component order (array or legacy string).
	 * @return string[] Canonical ordered list of slot slugs.
	 */
	public static function normalize_release_component_order( mixed $value ): array {
		$canonical = array( 'meta', 'player', 'body', 'links' );

		if ( is_string( $value ) ) {
			$legacy_map = array(
				'meta_body_links' => array( 'meta', 'player', 'body', 'links' ),
				'meta_links_body' => array( 'meta', 'player', 'links', 'body' ),
				'links_meta_body' => array( 'links', 'meta', 'player', 'body' ),
				'body_meta_links' => array( 'body', 'meta', 'player', 'links' ),
			);
			return $legacy_map[ $value ] ?? $canonical;
		}

		if ( ! is_array( $value ) ) {
			return $canonical;
		}

		$ordered = array();
		foreach ( $value as $item ) {
			if ( is_string( $item ) && in_array( $item, $canonical, true ) && ! in_array( $item, $ordered, true ) ) {
				$ordered[] = $item;
			}
		}

		foreach ( $canonical as $slot ) {
			if ( ! in_array( $slot, $ordered, true ) ) {
				$ordered[] = $slot;
			}
		}

		return $ordered;
	}

	/**
	 * Returns merged settings for a single template ('release' or 'artist').
	 *
	 * @param string $template Template key.
	 * @return array<string, mixed>
	 */
	public static function get( string $template ): array {
		$all = self::get_all();
		return $all[ $template ] ?? array();
	}

	/**
	 * Reads legacy Customizer theme_mod values that were explicitly saved.
	 *
	 * @return array{release: array<string, mixed>, artist: array<string, mixed>}
	 */
	private static function read_legacy_theme_mods(): array {
		if ( ! function_exists( 'get_theme_mod' ) ) {
			return array(
				'release' => array(),
				'artist'  => array(),
			);
		}

		$release_map = array(
			'layout'              => array( 'mds_rm_layout_style', 'enum', array( 'split', 'stacked' ) ),
			'max_width'           => array( 'mds_rm_release_fallback_max_width', 'int', 480, 2000 ),
			'column_gap'          => array( 'mds_rm_release_fallback_column_gap', 'int', 0, 120 ),
			'component_gap'       => array( 'mds_rm_release_fallback_component_gap', 'int', 0, 100 ),
			'image_radius'        => array( 'mds_rm_release_fallback_image_radius', 'int', 0, 100 ),
			'image_shadow'        => array( 'mds_rm_release_fallback_image_shadow', 'enum', array( 'none', 'subtle', 'default', 'elevated' ) ),
			'content_font_size'   => array( 'mds_rm_release_fallback_content_font_size', 'int', 12, 32 ),
			'content_line_height' => array( 'mds_rm_release_fallback_content_line_height', 'int', 80, 300 ),
			'content_color'       => array( 'mds_rm_release_fallback_content_color', 'color' ),
		);

		$artist_map = array(
			'width'              => array( 'mds_rm_artist_fallback_width', 'int', 320, 2000 ),
			'image_size'         => array( 'mds_rm_artist_fallback_image_size', 'int', 80, 800 ),
			'gap'                => array( 'mds_rm_artist_fallback_gap', 'int', 0, 100 ),
			'padding_vertical'   => array( 'mds_rm_artist_fallback_padding_vertical', 'int', 0, 120 ),
			'padding_horizontal' => array( 'mds_rm_artist_fallback_padding_horizontal', 'int', 0, 120 ),
			'image_radius'       => array( 'mds_rm_artist_fallback_image_radius', 'int', 0, 50 ),
			'image_shadow'       => array( 'mds_rm_artist_fallback_image_shadow', 'enum', array( 'none', 'subtle', 'default', 'elevated' ) ),
			'body_line_height'   => array( 'mds_rm_artist_fallback_body_line_height', 'int', 80, 300 ),
			'contact_padding'    => array( 'mds_rm_artist_fallback_contact_padding', 'int', 0, 100 ),
			'contact_radius'     => array( 'mds_rm_artist_fallback_contact_radius', 'int', 0, 100 ),
			'contact_background' => array( 'mds_rm_artist_fallback_contact_background', 'color' ),
			'contact_color'      => array( 'mds_rm_artist_fallback_contact_color', 'color' ),
		);

		$read_section = static function ( array $map ): array {
			$out = array();
			foreach ( $map as $key => $spec ) {
				$raw = get_theme_mod( $spec[0], null );
				if ( null === $raw ) {
					continue;
				}
				if ( 'int' === $spec[1] && is_scalar( $raw ) ) {
					$out[ $key ] = min( $spec[3], max( $spec[2], absint( $raw ) ) );
				} elseif ( 'enum' === $spec[1] && is_string( $raw ) && in_array( $raw, $spec[2], true ) ) {
					$out[ $key ] = $raw;
				} elseif ( 'color' === $spec[1] && is_string( $raw ) ) {
					$color = self::sanitize_color( $raw );
					if ( null !== $color ) {
						$out[ $key ] = $color;
					}
				}
			}
			return $out;
		};

		return array(
			'release' => $read_section( $release_map ),
			'artist'  => $read_section( $artist_map ),
		);
	}

	/**
	 * Validates and sanitizes submitted template defaults.
	 *
	 * @param mixed $raw Decoded JSON payload.
	 * @return array{release: array<string, mixed>, artist: array<string, mixed>}|\WP_Error
	 */
	public static function validate( mixed $raw ): array|\WP_Error {
		if ( ! is_array( $raw ) ) {
			return new \WP_Error(
				'mds_rm_invalid_template_defaults',
				__( 'The default template settings are invalid.', 'mds-release-manager' ),
				array( 'status' => 400 )
			);
		}

		$defaults = self::defaults();

		$release_spec = array(
			'layout'              => array( 'enum', array( 'split', 'stacked' ) ),
			'split_ratio'         => array( 'enum', array( '40_60', '50_50', '35_65' ) ),
			'image_position'      => array( 'enum', array( 'left', 'right' ) ),
			'sticky_image'        => array( 'bool' ),
			'mobile_order'        => array( 'enum', array( 'image_first', 'content_first' ) ),
			'max_width'           => array( 'int', 480, 2000 ),
			'padding_vertical'    => array( 'int', 0, 120 ),
			'padding_horizontal'  => array( 'int', 0, 120 ),
			'column_gap'          => array( 'int', 0, 120 ),
			'component_gap'       => array( 'int', 0, 100 ),
			'container_bg'        => array( 'color' ),
			'container_border'    => array( 'color' ),
			'container_radius'    => array( 'int', 0, 48 ),
			'show_image'          => array( 'bool' ),
			'image_aspect'        => array( 'enum', array( 'auto', '1/1', '4/5', '16/9' ) ),
			'image_radius'        => array( 'int', 0, 100 ),
			'image_shadow'        => array( 'enum', array( 'none', 'subtle', 'default', 'elevated' ) ),
			'image_border_color'  => array( 'color' ),
			'component_order'     => array( 'slot_order', array( 'meta', 'player', 'body', 'links' ) ),
			'show_meta'           => array( 'bool' ),
			'show_player'         => array( 'bool' ),
			'show_links'          => array( 'bool' ),
			'content_font_family' => array( 'font_family' ),
			'content_font_size'   => array( 'int', 12, 32 ),
			'content_line_height' => array( 'int', 80, 300 ),
			'content_color'       => array( 'color' ),
			'content_align'       => array( 'enum', array( 'left', 'center', 'right' ) ),
		);

		$artist_spec = array(
			'layout'               => array( 'enum', array( 'stacked', 'split' ) ),
			'portrait_align'       => array( 'enum', array( 'center', 'left', 'right' ) ),
			'section_order'        => array( 'enum', array( 'discography_first', 'contact_first' ) ),
			'width'                => array( 'int', 320, 2000 ),
			'gap'                  => array( 'int', 0, 100 ),
			'padding_vertical'     => array( 'int', 0, 120 ),
			'padding_horizontal'   => array( 'int', 0, 120 ),
			'container_bg'         => array( 'color' ),
			'container_border'     => array( 'color' ),
			'container_radius'     => array( 'int', 0, 48 ),
			'show_portrait'        => array( 'bool' ),
			'image_size'           => array( 'int', 80, 800 ),
			'image_aspect'         => array( 'enum', array( '1/1', '3/4', '4/3', 'auto' ) ),
			'image_radius'         => array( 'int', 0, 50 ),
			'image_shadow'         => array( 'enum', array( 'none', 'subtle', 'default', 'elevated' ) ),
			'image_border_color'   => array( 'color' ),
			'body_font_family'     => array( 'font_family' ),
			'body_font_size'       => array( 'int_or_zero', 12, 32 ),
			'body_line_height'     => array( 'int', 80, 300 ),
			'body_color'           => array( 'color' ),
			'body_align'           => array( 'enum', array( 'left', 'center' ) ),
			'show_discography'     => array( 'bool' ),
			'discography_heading'  => array( 'text', 120 ),
			'show_contact'         => array( 'bool' ),
			'contact_heading'      => array( 'text', 120 ),
			'contact_padding'      => array( 'int', 0, 100 ),
			'contact_radius'       => array( 'int', 0, 100 ),
			'contact_background'   => array( 'color' ),
			'contact_color'        => array( 'color' ),
			'contact_border_color' => array( 'color' ),
			'contact_border_width' => array( 'int', 0, 8 ),
		);

		$validated_release = self::validate_section( $raw['release'] ?? array(), $defaults['release'], $release_spec );
		if ( is_wp_error( $validated_release ) ) {
			return $validated_release;
		}

		$validated_artist = self::validate_section( $raw['artist'] ?? array(), $defaults['artist'], $artist_spec );
		if ( is_wp_error( $validated_artist ) ) {
			return $validated_artist;
		}

		return array(
			'release' => $validated_release,
			'artist'  => $validated_artist,
		);
	}

	/**
	 * Validates a single template section against its schema.
	 *
	 * @param mixed                $input    Submitted section array.
	 * @param array<string, mixed> $defaults Section defaults.
	 * @param array<string, array> $schema   Field schemas.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function validate_section( mixed $input, array $defaults, array $schema ): array|\WP_Error {
		if ( ! is_array( $input ) ) {
			return new \WP_Error(
				'mds_rm_invalid_template_defaults',
				__( 'The default template settings are invalid.', 'mds-release-manager' ),
				array( 'status' => 400 )
			);
		}

		$result = $defaults;
		foreach ( $schema as $key => $rule ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$val  = $input[ $key ];
			$type = $rule[0];

			switch ( $type ) {
				case 'enum':
					if ( ! is_string( $val ) || ! in_array( $val, $rule[1], true ) ) {
						return self::invalid_error();
					}
					$result[ $key ] = $val;
					break;

				case 'bool':
					if ( ! is_bool( $val ) ) {
						return self::invalid_error();
					}
					$result[ $key ] = $val;
					break;

				case 'int':
					if ( ! is_numeric( $val ) ) {
						return self::invalid_error();
					}
					$result[ $key ] = min( $rule[2], max( $rule[1], (int) round( (float) $val ) ) );
					break;

				case 'int_or_zero':
					if ( ! is_numeric( $val ) ) {
						return self::invalid_error();
					}
					$int_val = (int) round( (float) $val );
					if ( 0 === $int_val ) {
						$result[ $key ] = 0;
					} else {
						$result[ $key ] = min( $rule[2], max( $rule[1], $int_val ) );
					}
					break;

				case 'color':
					$color = self::sanitize_color( $val );
					if ( null === $color ) {
						return self::invalid_error();
					}
					$result[ $key ] = $color;
					break;

				case 'font_family':
					if ( '' === $val ) {
						$result[ $key ] = '';
						break;
					}
					if ( ! is_string( $val ) || ! preg_match( '/^var\(--wp--preset--font-family--[a-z0-9-]+\)$/', $val ) ) {
						return self::invalid_error();
					}
					$result[ $key ] = $val;
					break;

				case 'text':
					if ( ! is_string( $val ) ) {
						return self::invalid_error();
					}
					$clean          = sanitize_text_field( $val );
					$result[ $key ] = function_exists( 'mb_substr' ) ? mb_substr( $clean, 0, $rule[1] ) : substr( $clean, 0, $rule[1] );
					break;

				case 'slot_order':
					if ( is_string( $val ) ) {
						$legacy_keys = array( 'meta_body_links', 'meta_links_body', 'links_meta_body', 'body_meta_links' );
						if ( ! in_array( $val, $legacy_keys, true ) ) {
							return self::invalid_error();
						}
						$result[ $key ] = self::normalize_release_component_order( $val );
						break;
					}
					if ( ! is_array( $val ) || empty( $val ) ) {
						return self::invalid_error();
					}
					foreach ( $val as $slot ) {
						if ( ! is_string( $slot ) || ! in_array( $slot, $rule[1], true ) ) {
							return self::invalid_error();
						}
					}
					$result[ $key ] = self::normalize_release_component_order( $val );
					break;
			}
		}

		return $result;
	}

	/**
	 * Builds a standard validation WP_Error.
	 *
	 * @return \WP_Error
	 */
	private static function invalid_error(): \WP_Error {
		return new \WP_Error(
			'mds_rm_invalid_template_defaults',
			__( 'One or more default template settings are invalid.', 'mds-release-manager' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Sanitizes a CSS color value (hex, rgb/rgba, transparent, inherit, or theme preset).
	 *
	 * @param mixed $value Submitted color.
	 * @return string|null Valid color, empty string to inherit, or null if invalid.
	 */
	public static function sanitize_color( mixed $value ): ?string {
		if ( '' === $value ) {
			return '';
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		$color = \MDS\ReleaseManager\Blocks\ReleaseMeta::valid_style( $value, 'color' );
		return '' !== $color ? $color : null;
	}

	/**
	 * Maps a shadow preset key to its CSS box-shadow declaration.
	 *
	 * @param string $preset   Shadow preset ('none', 'subtle', 'default', 'elevated').
	 * @param string $template Template context ('release' or 'artist').
	 * @return string CSS box-shadow value.
	 */
	public static function shadow_css( string $preset, string $template = 'release' ): string {
		return match ( $preset ) {
			'none'     => 'none',
			'subtle'   => '0 2px 6px rgba(0, 0, 0, .08)',
			'elevated' => '0 12px 28px rgba(0, 0, 0, .18)',
			default    => 'artist' === $template ? '0 4px 12px rgba(0, 0, 0, .1)' : '0 4px 12px rgba(0, 0, 0, .12)',
		};
	}

	/**
	 * Maps a Release split column ratio preset to CSS grid-template-columns.
	 *
	 * @param string $ratio    Ratio key ('40_60', '50_50', '35_65').
	 * @param string $position Image side ('left' or 'right').
	 * @return string CSS grid-template-columns value.
	 */
	public static function split_columns_css( string $ratio, string $position = 'left' ): string {
		$pair = match ( $ratio ) {
			'50_50' => array( 'minmax(0, 1fr)', 'minmax(0, 1fr)' ),
			'35_65' => array( 'minmax(0, 1fr)', 'minmax(0, 1.85fr)' ),
			default => array( 'minmax(0, 1fr)', 'minmax(0, 1.4fr)' ),
		};

		if ( 'right' === $position ) {
			return $pair[1] . ' ' . $pair[0];
		}

		return $pair[0] . ' ' . $pair[1];
	}

	/**
	 * Builds safe inline CSS custom properties for the Release fallback partial.
	 *
	 * @return string CSS custom-property declarations.
	 */
	public static function get_release_style_attribute(): string {
		$s      = self::get( 'release' );
		$styles = array(
			'--mds-fallback-max-width:' . absint( $s['max_width'] ) . 'px',
			'--mds-fallback-column-gap:' . absint( $s['column_gap'] ) . 'px',
			'--mds-fallback-component-gap:' . absint( $s['component_gap'] ) . 'px',
			'--mds-fallback-image-radius:' . absint( $s['image_radius'] ) . 'px',
			'--mds-fallback-content-font-size:' . absint( $s['content_font_size'] ) . 'px',
			'--mds-fallback-content-line-height:' . ( absint( $s['content_line_height'] ) / 100 ),
			'--mds-fallback-split-columns:' . self::split_columns_css( (string) $s['split_ratio'], (string) $s['image_position'] ),
		);

		if ( absint( $s['padding_vertical'] ) > 0 ) {
			$styles[] = '--mds-fallback-padding-vertical:' . absint( $s['padding_vertical'] ) . 'px';
		}
		if ( absint( $s['padding_horizontal'] ) > 0 ) {
			$styles[] = '--mds-fallback-padding-horizontal:' . absint( $s['padding_horizontal'] ) . 'px';
		}
		if ( '' !== (string) $s['container_bg'] ) {
			$styles[] = '--mds-fallback-container-bg:' . $s['container_bg'];
		}
		if ( '' !== (string) $s['container_border'] ) {
			$styles[] = '--mds-fallback-container-border:1px solid ' . $s['container_border'];
		}
		if ( absint( $s['container_radius'] ) > 0 ) {
			$styles[] = '--mds-fallback-container-radius:' . absint( $s['container_radius'] ) . 'px';
		}
		if ( 'default' !== (string) $s['image_shadow'] ) {
			$styles[] = '--mds-fallback-image-shadow:' . self::shadow_css( (string) $s['image_shadow'], 'release' );
		}
		if ( 'auto' !== (string) $s['image_aspect'] ) {
			$styles[] = '--mds-fallback-image-aspect:' . $s['image_aspect'];
		}
		if ( '' !== (string) $s['image_border_color'] ) {
			$styles[] = '--mds-fallback-image-border:1px solid ' . $s['image_border_color'];
		}
		if ( '' !== (string) $s['content_font_family'] ) {
			$styles[] = '--mds-fallback-content-font-family:' . $s['content_font_family'];
		}
		if ( '' !== (string) $s['content_color'] ) {
			$styles[] = '--mds-fallback-content-color:' . $s['content_color'];
		}
		if ( 'left' !== (string) $s['content_align'] ) {
			$styles[] = '--mds-fallback-content-align:' . $s['content_align'];
		}

		return implode( ';', $styles );
	}

	/**
	 * Builds safe inline CSS custom properties for the Artist fallback partial.
	 *
	 * @return string CSS custom-property declarations.
	 */
	public static function get_artist_style_attribute(): string {
		$s      = self::get( 'artist' );
		$styles = array(
			'--mds-artist-width:' . absint( $s['width'] ) . 'px',
			'--mds-artist-image-size:' . absint( $s['image_size'] ) . 'px',
			'--mds-artist-gap:' . absint( $s['gap'] ) . 'px',
			'--mds-artist-contact-padding:' . absint( $s['contact_padding'] ) . 'px',
			'--mds-artist-padding-vertical:' . absint( $s['padding_vertical'] ) . 'px',
			'--mds-artist-padding-horizontal:' . absint( $s['padding_horizontal'] ) . 'px',
			'--mds-artist-image-radius:' . absint( $s['image_radius'] ) . '%',
			'--mds-artist-contact-radius:' . absint( $s['contact_radius'] ) . 'px',
			'--mds-artist-body-line-height:' . ( absint( $s['body_line_height'] ) / 100 ),
		);

		if ( 'center' !== (string) $s['portrait_align'] ) {
			$styles[] = '--mds-artist-portrait-align:' . $s['portrait_align'];
		}
		if ( '' !== (string) $s['container_bg'] ) {
			$styles[] = '--mds-artist-container-bg:' . $s['container_bg'];
		}
		if ( '' !== (string) $s['container_border'] ) {
			$styles[] = '--mds-artist-container-border:1px solid ' . $s['container_border'];
		}
		if ( absint( $s['container_radius'] ) > 0 ) {
			$styles[] = '--mds-artist-container-radius:' . absint( $s['container_radius'] ) . 'px';
		}
		if ( '1/1' !== (string) $s['image_aspect'] ) {
			$styles[] = '--mds-artist-image-aspect:' . $s['image_aspect'];
		}
		if ( 'default' !== (string) $s['image_shadow'] ) {
			$styles[] = '--mds-artist-image-shadow:' . self::shadow_css( (string) $s['image_shadow'], 'artist' );
		}
		if ( '' !== (string) $s['image_border_color'] ) {
			$styles[] = '--mds-artist-image-border:2px solid ' . $s['image_border_color'];
		}
		if ( '' !== (string) $s['body_font_family'] ) {
			$styles[] = '--mds-artist-body-font-family:' . $s['body_font_family'];
		}
		if ( absint( $s['body_font_size'] ) > 0 ) {
			$styles[] = '--mds-artist-body-font-size:' . absint( $s['body_font_size'] ) . 'px';
		}
		if ( '' !== (string) $s['body_color'] ) {
			$styles[] = '--mds-artist-body-color:' . $s['body_color'];
		}
		if ( 'left' !== (string) $s['body_align'] ) {
			$styles[] = '--mds-artist-body-align:' . $s['body_align'];
		}
		if ( '' !== (string) $s['contact_background'] ) {
			$styles[] = '--mds-artist-contact-background:' . $s['contact_background'];
		}
		if ( '' !== (string) $s['contact_color'] ) {
			$styles[] = '--mds-artist-contact-color:' . $s['contact_color'];
		}
		if ( '' !== (string) $s['contact_border_color'] && absint( $s['contact_border_width'] ) > 0 ) {
			$styles[] = '--mds-artist-contact-border:' . absint( $s['contact_border_width'] ) . 'px solid ' . $s['contact_border_color'];
		}

		return implode( ';', $styles );
	}
}

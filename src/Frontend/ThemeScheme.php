<?php
/**
 * Intelligent multi-source theme and page-builder color scheme detection.
 *
 * Automatically derives WCAG-compliant light or dark surface, border, muted-text,
 * and accent tokens from Elementor Active Kits, Bricks Theme Styles, WordPress
 * Global Styles (`theme.json`), popular theme customizers (Kadence, Astra,
 * GeneratePress), and Core Customizer — and automatically resets designer color
 * overrides whenever the active theme or color scheme changes.
 *
 * @package   MDS\ReleaseManager
 * @copyright 2026 Musty Dungeon Studios (Walton International Business Group LLC)
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves theme-adaptive color tokens and manages automatic baseline resets.
 *
 * @since 1.2.0
 */
final class ThemeScheme {

	/**
	 * Option key storing the last detected theme/scheme fingerprint.
	 *
	 * @var string
	 */
	public const FINGERPRINT_OPTION = 'mds_rm_active_scheme_fingerprint';

	/**
	 * Transient key storing a one-time admin notice after an automatic baseline reset.
	 *
	 * @var string
	 */
	public const RESET_NOTICE_TRANSIENT = 'mds_rm_scheme_reset_notice';

	/**
	 * In-memory cache of the detected scheme for the current request.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $cached = null;

	/**
	 * Registers theme/builder change hooks for automatic baseline synchronization.
	 *
	 * @since 2.2.0
	 */
	public static function register_hooks(): void {
		add_action( 'switch_theme', array( self::class, 'handle_scheme_change' ) );
		add_action( 'save_post_wp_global_styles', array( self::class, 'handle_scheme_change' ) );
		add_action( 'customize_save_after', array( self::class, 'handle_scheme_change' ) );
		add_action( 'update_option_bricks_theme_styles', array( self::class, 'handle_scheme_change' ) );
		add_action( 'elementor/document/after_save', array( self::class, 'handle_elementor_save' ), 10, 1 );
		add_action( 'admin_init', array( self::class, 'maybe_sync_on_admin_init' ) );
		add_action( 'admin_notices', array( self::class, 'render_reset_notice' ) );
	}

	/**
	 * Clears the in-memory detected scheme cache.
	 *
	 * @since 2.2.0
	 */
	public static function reset_cache(): void {
		self::$cached = null;
	}

	/**
	 * Handles Elementor document saves and triggers scheme sync only when the Active Kit changes.
	 *
	 * @since 2.2.0
	 *
	 * @param mixed $document Saved Elementor Document instance.
	 */
	public static function handle_elementor_save( mixed $document ): void {
		if ( ! is_object( $document ) || ! method_exists( $document, 'get_main_id' ) ) {
			return;
		}
		$active_kit = (int) get_option( 'elementor_active_kit', 0 );
		if ( $active_kit > 0 && (int) $document->get_main_id() === $active_kit ) {
			self::handle_scheme_change();
		}
	}

	/**
	 * Re-evaluates the active scheme after a theme or builder style change.
	 *
	 * @since 2.2.0
	 */
	public static function handle_scheme_change(): void {
		self::reset_cache();
		self::maybe_sync_scheme_baseline();
	}

	/**
	 * Lazily checks for scheme drift on MDS admin screens (catches DB/theme swaps outside hooks).
	 *
	 * @since 2.2.0
	 */
	public static function maybe_sync_on_admin_init(): void {
		if ( ! is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		self::maybe_sync_scheme_baseline();
	}

	/**
	 * Displays a one-time notice when a theme/scheme switch automatically reset color overrides.
	 *
	 * @since 2.2.0
	 */
	public static function render_reset_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$notice = get_transient( self::RESET_NOTICE_TRANSIENT );
		if ( ! is_string( $notice ) || '' === $notice ) {
			return;
		}
		delete_transient( self::RESET_NOTICE_TRANSIENT );
		printf(
			'<div class="notice notice-info is-dismissible"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'MDS Release Manager:', 'mds-release-manager' ),
			esc_html( $notice )
		);
	}

	/**
	 * Detects the active theme or page-builder color scheme and derives adaptive tokens.
	 *
	 * Priority waterfall:
	 * 1. Extensible `mds_rm_theme_scheme_sources` filter
	 * 2. Elementor Active Kit (`elementor_active_kit` -> `_elementor_page_settings`)
	 * 3. Bricks Builder Theme Styles (`bricks_theme_styles`)
	 * 4. WordPress Global Styles (`theme.json` & Site Editor variations)
	 * 5. Popular Classic/Hybrid theme palettes (Kadence, Astra, GeneratePress) & Core Customizer
	 *
	 * @return array<string, mixed> Scheme metadata and resolved hex tokens.
	 */
	public static function detect(): array {
		if ( is_array( self::$cached ) ) {
			return self::$cached;
		}

		$theme_name = '';
		$stylesheet = '';
		if ( function_exists( 'wp_get_theme' ) ) {
			$theme = wp_get_theme();
			if ( is_object( $theme ) && method_exists( $theme, 'get' ) ) {
				$theme_name = (string) $theme->get( 'Name' );
			}
			if ( is_object( $theme ) && method_exists( $theme, 'get_stylesheet' ) ) {
				$stylesheet = (string) $theme->get_stylesheet();
			}
		}

		$sources = array();

		/**
		 * Filters custom theme/builder scheme source candidates checked before built-in detectors.
		 *
		 * Each candidate array may provide: `source`, `sourceLabel`, `bg`, `text`, `accent`.
		 *
		 * @since 2.2.0
		 * @param array<int, array<string, string>> $sources Custom source candidates.
		 */
		if ( function_exists( 'apply_filters' ) ) {
			$custom_sources = apply_filters( 'mds_rm_theme_scheme_sources', array() );
			if ( is_array( $custom_sources ) ) {
				$sources = $custom_sources;
			}
		}

		$elementor = self::detect_elementor_kit();
		if ( null !== $elementor ) {
			$sources[] = $elementor;
		}

		$bricks = self::detect_bricks_theme_styles();
		if ( null !== $bricks ) {
			$sources[] = $bricks;
		}

		$global_styles = self::detect_global_styles();
		if ( null !== $global_styles ) {
			$sources[] = $global_styles;
		}

		$classic = self::detect_classic_theme_mods();
		if ( null !== $classic ) {
			$sources[] = $classic;
		}

		$bg           = '';
		$text         = '';
		$accent       = '';
		$source       = 'default';
		$source_label = __( 'Theme Default', 'mds-release-manager' );

		foreach ( $sources as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}
			$c_bg     = self::normalize_hex( (string) ( $candidate['bg'] ?? '' ) );
			$c_text   = self::normalize_hex( (string) ( $candidate['text'] ?? '' ) );
			$c_accent = self::normalize_hex( (string) ( $candidate['accent'] ?? '' ) );

			if ( '' === $bg && '' !== $c_bg ) {
				$bg           = $c_bg;
				$source       = (string) ( $candidate['source'] ?? $source );
				$source_label = (string) ( $candidate['sourceLabel'] ?? $source_label );
			}
			if ( '' === $text && '' !== $c_text ) {
				$text = $c_text;
				if ( 'default' === $source && ! empty( $candidate['source'] ) ) {
					$source       = (string) $candidate['source'];
					$source_label = (string) ( $candidate['sourceLabel'] ?? $source_label );
				}
			}
			if ( '' === $accent && '' !== $c_accent ) {
				$accent = $c_accent;
				if ( 'default' === $source && ! empty( $candidate['source'] ) ) {
					$source       = (string) $candidate['source'];
					$source_label = (string) ( $candidate['sourceLabel'] ?? $source_label );
				}
			}
			if ( '' !== $bg && '' !== $text && '' !== $accent ) {
				break;
			}
		}

		if ( '' === $bg ) {
			$bg = '#ffffff';
		}

		$is_dark = self::luminance( $bg ) < 0.25;

		if ( '' === $text ) {
			$text = $is_dark ? '#f3f4f6' : '#1d2327';
		}

		if ( '' === $accent || self::contrast_ratio( $accent, $bg ) < 1.8 ) {
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
			'themeName'   => '' !== $theme_name ? $theme_name : __( 'Active Theme', 'mds-release-manager' ),
			'stylesheet'  => $stylesheet,
			'source'      => $source,
			'sourceLabel' => $source_label,
			'mode'        => $is_dark ? 'dark' : 'light',
			'isDark'      => $is_dark,
			'background'  => $bg,
			'text'        => $text,
			'surface'     => $surface,
			'border'      => $border,
			'muted'       => $muted,
			'accent'      => $accent,
			'accentText'  => $accent_text,
			'metaBg'      => $meta_bg,
			'metaText'    => $text,
			'metaBorder'  => $meta_border,
			'stripe'      => $stripe,
		);

		/**
		 * Filters the detected theme color scheme tokens used for adaptive block & template defaults.
		 *
		 * @since 1.2.0
		 * @param array<string, mixed> $scheme Detected scheme tokens.
		 */
		$filtered     = function_exists( 'apply_filters' ) ? apply_filters( 'mds_rm_theme_scheme', $scheme ) : $scheme;
		self::$cached = is_array( $filtered ) ? $filtered : $scheme;

		return self::$cached;
	}

	/**
	 * Computes a deterministic fingerprint of the active theme and resolved color baseline.
	 *
	 * @since 2.2.0
	 *
	 * @param array<string, mixed>|null $scheme Optional pre-detected scheme array.
	 * @return string MD5 hash representing the current theme + scheme baseline.
	 */
	public static function fingerprint( ?array $scheme = null ): string {
		$s       = is_array( $scheme ) ? $scheme : self::detect();
		$payload = array(
			'stylesheet' => (string) ( $s['stylesheet'] ?? '' ),
			'source'     => (string) ( $s['source'] ?? '' ),
			'mode'       => (string) ( $s['mode'] ?? '' ),
			'background' => (string) ( $s['background'] ?? '' ),
			'text'       => (string) ( $s['text'] ?? '' ),
			'accent'     => (string) ( $s['accent'] ?? '' ),
		);
		$encoded = function_exists( 'wp_json_encode' ) ? (string) wp_json_encode( $payload ) : (string) json_encode( $payload ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Fallback for standalone CLI tests.
		return md5( $encoded );
	}

	/**
	 * Synchronizes the stored scheme fingerprint and resets color overrides when the scheme changes.
	 *
	 * @since 2.2.0
	 *
	 * @return bool True when a theme/scheme change was detected and color overrides were reset.
	 */
	public static function maybe_sync_scheme_baseline(): bool {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return false;
		}

		$scheme  = self::detect();
		$current = self::fingerprint( $scheme );
		$stored  = (string) get_option( self::FINGERPRINT_OPTION, '' );

		if ( '' === $stored ) {
			update_option( self::FINGERPRINT_OPTION, $current, false );
			return false;
		}

		if ( hash_equals( $stored, $current ) ) {
			return false;
		}

		self::reset_color_overrides();
		update_option( self::FINGERPRINT_OPTION, $current, false );

		if ( function_exists( 'set_transient' ) ) {
			$mode_label = ! empty( $scheme['isDark'] )
				? __( 'Dark', 'mds-release-manager' )
				: __( 'Light', 'mds-release-manager' );
			$message    = sprintf(
				/* translators: 1: Theme name, 2: Detection source label, 3: Light or Dark mode label. */
				__( 'Detected a theme or color scheme change (%1$s via %2$s — %3$s). Designer color overrides in Default Templates and Block Defaults were reset to match the new baseline.', 'mds-release-manager' ),
				(string) ( $scheme['themeName'] ?? '' ),
				(string) ( $scheme['sourceLabel'] ?? '' ),
				$mode_label
			);
			set_transient( self::RESET_NOTICE_TRANSIENT, $message, 5 * MINUTE_IN_SECONDS );
		}

		return true;
	}

	/**
	 * Clears explicit color & surface overrides across Block Defaults and Default Templates,
	 * preserving all structural layout, column, visibility, and component-order settings.
	 *
	 * @since 2.2.0
	 */
	public static function reset_color_overrides(): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}

		// 1. Clear color overrides in Default Templates (`mds_rm_template_defaults`).
		$tpl = get_option( TemplateDefaults::OPTION_NAME, null );
		if ( is_array( $tpl ) ) {
			$clear_keys = array(
				'release' => array( 'container_bg', 'container_border', 'image_border_color', 'content_color' ),
				'artist'  => array( 'container_bg', 'container_border', 'image_border_color', 'body_color', 'contact_background', 'contact_color', 'contact_border_color' ),
				'archive' => array( 'container_bg', 'card_bg', 'card_border_color' ),
			);
			foreach ( $clear_keys as $section => $keys ) {
				if ( ! isset( $tpl[ $section ] ) || ! is_array( $tpl[ $section ] ) ) {
					continue;
				}
				foreach ( $keys as $key ) {
					if ( array_key_exists( $key, $tpl[ $section ] ) ) {
						$tpl[ $section ][ $key ] = '';
					}
				}
			}
			update_option( TemplateDefaults::OPTION_NAME, $tpl );
		}

		// 2. Clear color overrides in Block Defaults (`mds_rm_block_defaults`).
		$blocks = get_option( 'mds_rm_block_defaults', null );
		if ( is_array( $blocks ) ) {
			$meta_color_keys   = array( 'background', 'color', 'icon_color', 'border_color' );
			$links_color_keys  = array( 'background', 'color', 'border_color', 'button_background', 'button_color', 'button_border_color' );
			$player_color_keys = array( 'backgroundColor', 'textColor', 'mutedColor', 'accentColor', 'playIconColor', 'borderColor' );
			$table_color_keys  = array( 'header-background', 'header-color', 'body-background', 'body-color', 'stripe-background', 'cell-border-color', 'link-color' );

			if ( isset( $blocks['release-meta']['metaStyles'] ) && is_array( $blocks['release-meta']['metaStyles'] ) ) {
				foreach ( $meta_color_keys as $k ) {
					unset( $blocks['release-meta']['metaStyles'][ $k ] );
				}
			}
			if ( isset( $blocks['release-links']['linkStyles'] ) && is_array( $blocks['release-links']['linkStyles'] ) ) {
				foreach ( $links_color_keys as $k ) {
					unset( $blocks['release-links']['linkStyles'][ $k ] );
				}
			}
			if ( isset( $blocks['release-player'] ) && is_array( $blocks['release-player'] ) ) {
				foreach ( $player_color_keys as $k ) {
					unset( $blocks['release-player'][ $k ] );
				}
			}
			if ( isset( $blocks['artist-discography']['tableStyles'] ) && is_array( $blocks['artist-discography']['tableStyles'] ) ) {
				foreach ( $table_color_keys as $k ) {
					unset( $blocks['artist-discography']['tableStyles'][ $k ] );
				}
			}
			if ( isset( $blocks['artist-discography']['style']['color'] ) ) {
				unset( $blocks['artist-discography']['style']['color'] );
			}

			update_option( 'mds_rm_block_defaults', $blocks );
		}
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
	 * Returns the baseline color-reset specification for the Block Defaults settings screen.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function presets(): array {
		return array(
			array(
				'id'          => 'auto',
				'label'       => __( 'Reset Color Overrides to Baseline', 'mds-release-manager' ),
				'description' => __( 'Clears explicit color overrides so all blocks automatically inherit the detected theme or page-builder color scheme.', 'mds-release-manager' ),
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
					),
					'artist-discography' => array(
						'style'       => null,
						'tableStyles' => null,
					),
				),
			),
		);
	}

	/**
	 * Returns the baseline color-reset specification for the Default Templates settings screen.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function template_presets(): array {
		return array(
			array(
				'id'          => 'auto',
				'label'       => __( 'Reset Color Overrides to Baseline', 'mds-release-manager' ),
				'description' => __( 'Clears explicit color overrides so Release, Artist, and Archive pages automatically inherit the detected theme or page-builder scheme.', 'mds-release-manager' ),
				'templates'   => array(
					'release' => array(
						'container_bg'       => '',
						'container_border'   => '',
						'image_border_color' => '',
						'content_color'      => '',
					),
					'artist'  => array(
						'container_bg'         => '',
						'container_border'     => '',
						'image_border_color'   => '',
						'body_color'           => '',
						'contact_background'   => '',
						'contact_color'        => '',
						'contact_border_color' => '',
					),
					'archive' => array(
						'container_bg'      => '',
						'card_bg'           => '',
						'card_border_color' => '',
					),
				),
			),
		);
	}

	/**
	 * Detects colors from an active Elementor Kit (`elementor_active_kit`).
	 *
	 * @return array<string, string>|null Candidate source array or null if Elementor Kit is absent.
	 */
	private static function detect_elementor_kit(): ?array {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'get_post_meta' ) ) {
			return null;
		}
		if ( ! defined( 'ELEMENTOR_VERSION' ) && ! class_exists( '\Elementor\Plugin' ) ) {
			return null;
		}

		$kit_id = (int) get_option( 'elementor_active_kit', 0 );
		if ( $kit_id <= 0 ) {
			return null;
		}

		$settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
		if ( ! is_array( $settings ) || empty( $settings ) ) {
			return null;
		}

		$bg = '';
		if ( ! empty( $settings['body_background_color'] ) && is_string( $settings['body_background_color'] ) ) {
			$bg = self::normalize_hex( $settings['body_background_color'] );
		}

		$sys_map = array();
		if ( ! empty( $settings['system_colors'] ) && is_array( $settings['system_colors'] ) ) {
			foreach ( $settings['system_colors'] as $entry ) {
				if ( is_array( $entry ) && ! empty( $entry['_id'] ) && ! empty( $entry['color'] ) ) {
					$hex = self::normalize_hex( (string) $entry['color'] );
					if ( '' !== $hex ) {
						$sys_map[ (string) $entry['_id'] ] = $hex;
					}
				}
			}
		}

		$text   = $sys_map['text'] ?? '';
		$accent = $sys_map['accent'] ?? ( $sys_map['primary'] ?? '' );

		if ( '' === $bg && '' === $text && '' === $accent ) {
			return null;
		}

		return array(
			'source'      => 'elementor',
			'sourceLabel' => __( 'Elementor Kit', 'mds-release-manager' ),
			'bg'          => $bg,
			'text'        => $text,
			'accent'      => $accent,
		);
	}

	/**
	 * Detects colors from Bricks Builder global Theme Styles (`bricks_theme_styles`).
	 *
	 * @return array<string, string>|null Candidate source array or null if Bricks is inactive.
	 */
	private static function detect_bricks_theme_styles(): ?array {
		if ( ! function_exists( 'get_option' ) ) {
			return null;
		}
		if ( ! defined( 'BRICKS_VERSION' ) && ! class_exists( '\Bricks\Elements' ) ) {
			return null;
		}

		$styles = get_option( 'bricks_theme_styles', array() );
		if ( ! is_array( $styles ) || empty( $styles ) ) {
			return null;
		}

		$chosen = null;
		foreach ( $styles as $style_data ) {
			if ( ! is_array( $style_data ) || empty( $style_data['settings'] ) || ! is_array( $style_data['settings'] ) ) {
				continue;
			}
			$conditions = $style_data['settings']['conditions']['conditions'] ?? array();
			if ( empty( $conditions ) ) {
				$chosen = $style_data['settings'];
				break;
			}
			if ( null === $chosen ) {
				$chosen = $style_data['settings'];
			}
		}

		if ( ! is_array( $chosen ) ) {
			return null;
		}

		$bg     = self::extract_bricks_color( $chosen['general']['siteBackground']['color'] ?? ( $chosen['colors']['background'] ?? '' ) );
		$text   = self::extract_bricks_color( $chosen['typography']['body']['color'] ?? ( $chosen['colors']['text'] ?? '' ) );
		$accent = self::extract_bricks_color( $chosen['colors']['primary'] ?? ( $chosen['colors']['accent'] ?? '' ) );

		if ( '' === $bg && '' === $text && '' === $accent ) {
			return null;
		}

		return array(
			'source'      => 'bricks',
			'sourceLabel' => __( 'Bricks Theme Styles', 'mds-release-manager' ),
			'bg'          => $bg,
			'text'        => $text,
			'accent'      => $accent,
		);
	}

	/**
	 * Extracts a hex color from a Bricks color setting (string or `['hex' => '#...']`).
	 *
	 * @param mixed $raw Raw Bricks color value.
	 * @return string Normalized `#rrggbb` hex string or empty string.
	 */
	private static function extract_bricks_color( mixed $raw ): string {
		if ( is_array( $raw ) ) {
			$raw = $raw['hex'] ?? ( $raw['raw'] ?? '' );
		}
		return is_string( $raw ) ? self::normalize_hex( $raw ) : '';
	}

	/**
	 * Detects colors from WordPress Global Styles (`theme.json` & Site Editor variations).
	 *
	 * @return array<string, string>|null Candidate source array or null if unavailable.
	 */
	private static function detect_global_styles(): ?array {
		$palette_map = self::palette_map();
		$styles      = function_exists( 'wp_get_global_styles' ) ? wp_get_global_styles() : array();
		$styles      = is_array( $styles ) ? $styles : array();

		$raw_bg     = $styles['color']['background'] ?? '';
		$raw_text   = $styles['color']['text'] ?? '';
		$raw_accent = $styles['elements']['button']['color']['background']
			?? ( $styles['elements']['link']['color']['text'] ?? '' );

		$bg = self::resolve_hex( $raw_bg, $palette_map );
		if ( '' === $bg ) {
			$bg = self::resolve_hex( $palette_map['base'] ?? '', $palette_map );
		}

		$text = self::resolve_hex( $raw_text, $palette_map );
		if ( '' === $text ) {
			$text = self::resolve_hex( $palette_map['contrast'] ?? '', $palette_map );
		}

		$accent = self::resolve_hex( $raw_accent, $palette_map );
		if ( '' === $accent && '' !== $bg ) {
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

		if ( '' === $bg && '' === $text && '' === $accent ) {
			return null;
		}

		return array(
			'source'      => 'global_styles',
			'sourceLabel' => __( 'WordPress Global Styles', 'mds-release-manager' ),
			'bg'          => $bg,
			'text'        => $text,
			'accent'      => $accent,
		);
	}

	/**
	 * Detects colors from popular Classic/Hybrid themes (Kadence, Astra, GeneratePress) and Core Customizer.
	 *
	 * @return array<string, string>|null Candidate source array or null if unavailable.
	 */
	private static function detect_classic_theme_mods(): ?array {
		if ( function_exists( 'get_option' ) ) {
			// Kadence global palette (JSON).
			$kadence_raw = get_option( 'kadence_global_palette', '' );
			if ( is_string( $kadence_raw ) && '' !== $kadence_raw ) {
				$kadence = json_decode( $kadence_raw, true );
				if ( is_array( $kadence ) && isset( $kadence['active'] ) && isset( $kadence[ $kadence['active'] ] ) && is_array( $kadence[ $kadence['active'] ] ) ) {
					$pal = $kadence[ $kadence['active'] ];
					$bg  = self::normalize_hex( (string) ( $pal[8]['color'] ?? '' ) );
					$txt = self::normalize_hex( (string) ( $pal[2]['color'] ?? '' ) );
					$acc = self::normalize_hex( (string) ( $pal[0]['color'] ?? '' ) );
					if ( '' !== $bg || '' !== $txt || '' !== $acc ) {
						return array(
							'source'      => 'kadence',
							'sourceLabel' => __( 'Kadence Global Palette', 'mds-release-manager' ),
							'bg'          => $bg,
							'text'        => $txt,
							'accent'      => $acc,
						);
					}
				}
			}

			// Astra theme settings.
			$astra = get_option( 'astra-settings', array() );
			if ( is_array( $astra ) && ! empty( $astra ) ) {
				$bg  = self::normalize_hex( (string) ( $astra['site-layout-outside-bg-obj-responsive']['desktop']['background-color'] ?? '' ) );
				$txt = self::normalize_hex( (string) ( $astra['text-color'] ?? '' ) );
				$acc = self::normalize_hex( (string) ( $astra['theme-color'] ?? '' ) );
				if ( '' !== $bg || '' !== $txt || '' !== $acc ) {
					return array(
						'source'      => 'astra',
						'sourceLabel' => __( 'Astra Theme Settings', 'mds-release-manager' ),
						'bg'          => $bg,
						'text'        => $txt,
						'accent'      => $acc,
					);
				}
			}

			// GeneratePress theme settings.
			$gp = get_option( 'generate_settings', array() );
			if ( is_array( $gp ) && ! empty( $gp ) ) {
				$bg  = self::normalize_hex( (string) ( $gp['background_color'] ?? '' ) );
				$txt = self::normalize_hex( (string) ( $gp['text_color'] ?? '' ) );
				$acc = self::normalize_hex( (string) ( $gp['link_color'] ?? '' ) );
				if ( '' !== $bg || '' !== $txt || '' !== $acc ) {
					return array(
						'source'      => 'generatepress',
						'sourceLabel' => __( 'GeneratePress Settings', 'mds-release-manager' ),
						'bg'          => $bg,
						'text'        => $txt,
						'accent'      => $acc,
					);
				}
			}
		}

		// Core Customizer background color.
		if ( function_exists( 'get_background_color' ) ) {
			$classic_bg = trim( (string) get_background_color() );
			if ( '' !== $classic_bg ) {
				$hex = self::normalize_hex( '#' . ltrim( $classic_bg, '#' ) );
				if ( '' !== $hex ) {
					return array(
						'source'      => 'customizer',
						'sourceLabel' => __( 'WordPress Customizer', 'mds-release-manager' ),
						'bg'          => $hex,
						'text'        => '',
						'accent'      => '',
					);
				}
			}
		}

		return null;
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
	private static function resolve_hex( mixed $raw, array $palette_map ): string {
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

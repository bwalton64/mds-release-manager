<?php
/**
 * Registers the Release fallback Customizer settings.
 *
 * @package MDS_Release_Manager
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Release fallback Customizer settings.
 *
 * @since 0.9.0
 */
final class MDS_RM_Customizer {

	/**
	 * Registers the Customizer settings hook.
	 *
	 * @since 0.9.0
	 */
	public static function register_hooks(): void {
		add_action( 'customize_register', array( self::class, 'customize_register' ) );
		add_action( 'customize_controls_enqueue_scripts', array( self::class, 'enqueue_controls' ) );
	}

	/** Enqueues the native component controls in the Customizer. */
	public static function enqueue_controls(): void {
		$script = 'js/customizer-controls.js';
		wp_enqueue_style( 'wp-components' );
		wp_add_inline_style(
			'wp-components',
			'.customize-control .mds-rm-customizer-group-heading { margin: 12px 0; padding-top: 12px; border-top: 1px solid #c3c4c7; font-size: 14px; }'
		);
		wp_enqueue_script(
			'mds-rm-customizer-controls',
			MDS_RM_URL . $script,
			array( 'customize-controls', 'wp-components', 'wp-element', 'wp-i18n' ),
			filemtime( MDS_RM_PATH . $script ),
			true
		);
	}

	/**
	 * Adds Release fallback layout and component styles.
	 *
	 * @since 0.9.0
	 * @param WP_Customize_Manager $wp_customize The Customizer manager.
	 */
	public static function customize_register( $wp_customize ): void {
		require_once MDS_RM_PATH . 'includes/frontend/class-mds-rm-customizer-control.php';
		$wp_customize->add_panel(
			'mds_rm_panel',
			array(
				'priority'    => 130,
				'title'       => 'MDS Release Manager',
				'description' => __( 'Customize the automatic layouts for Release and Artist pages.', 'mds-release-manager' ),
			)
		);

		// Fallback Layout Settings.
		$wp_customize->add_section(
			'mds_rm_release_section',
			array(
				'title'    => 'Release Fallback Template',
				'description' => __( 'Style the page layout here. Set Release Meta and Release Links defaults under Releases → Settings.', 'mds-release-manager' ),
				'panel'    => 'mds_rm_panel',
				'priority' => 10,
			)
		);

		$wp_customize->add_setting(
			'mds_rm_layout_style',
			array(
				'default'           => 'split',
				'sanitize_callback' => array( self::class, 'sanitize_release_layout' ),
			)
		);
		$wp_customize->add_control( new MDS_RM_Customizer_Control(
			$wp_customize,
			'mds_rm_layout_style',
			array(
				'label'   => __( 'Layout style', 'mds-release-manager' ),
				'section' => 'mds_rm_release_section',
				'component_type' => 'radio',
				'choices' => array(
					'split'   => 'Split',
					'stacked' => 'Stacked',
				),
			)
		) );

		$number_controls = array(
			'mds_rm_release_fallback_max_width'         => array( 'Maximum content width (px)', 1200, 480, 2000, 40 ),
			'mds_rm_release_fallback_column_gap'        => array( 'Image and content gap (px)', 40, 0, 120, 4 ),
			'mds_rm_release_fallback_component_gap'     => array( 'Component spacing (px)', 25, 0, 100, 1 ),
			'mds_rm_release_fallback_image_radius'      => array( 'Featured image corner radius (px)', 8, 0, 100, 1 ),
			'mds_rm_release_fallback_content_font_size' => array( 'Content font size (px)', 16, 12, 32, 1 ),
			'mds_rm_release_fallback_content_line_height' => array( __( 'Content line height (%)', 'mds-release-manager' ), 160, 80, 300, 10 ),
		);

		foreach ( $number_controls as $setting => $control ) {
			$wp_customize->add_setting(
				$setting,
				array(
					'default'           => $control[1],
					'sanitize_callback' => array( self::class, 'sanitize_fallback_number' ),
				)
			);
			$wp_customize->add_control( new MDS_RM_Customizer_Control(
				$wp_customize,
				$setting,
				array(
					'label'       => $control[0],
					'section'     => 'mds_rm_release_section',
					'component_type' => 'range',
					'input_attrs' => array(
						'min'  => $control[2],
						'max'  => $control[3],
						'step' => $control[4],
					),
				)
			) );
		}

		self::add_shadow_control( $wp_customize, 'mds_rm_release_fallback_image_shadow', __( 'Featured image shadow', 'mds-release-manager' ), 'mds_rm_release_section' );

		self::add_color_control( $wp_customize, 'mds_rm_release_fallback_content_color', __( 'Content text color', 'mds-release-manager' ), 'mds_rm_release_section' );

		$wp_customize->add_section(
			'mds_rm_artist_section',
			array(
				'title'    => 'Artist Fallback Template',
				'description' => __( 'Style the page layout here. Set Artist Discography defaults under Releases → Settings.', 'mds-release-manager' ),
				'panel'    => 'mds_rm_panel',
				'priority' => 20,
			)
		);
		$artist_controls = array(
			'mds_rm_artist_fallback_width'           => array( 'Maximum content width (px)', 900, 320, 2000, 10 ),
			'mds_rm_artist_fallback_image_size'      => array( 'Portrait size (px)', 300, 80, 800, 10 ),
			'mds_rm_artist_fallback_gap'             => array( 'Section spacing (px)', 25, 0, 100, 1 ),
			'mds_rm_artist_fallback_contact_padding' => array( 'Contact padding (px)', 20, 0, 100, 1 ),
			'mds_rm_artist_fallback_padding_vertical' => array( __( 'Page vertical padding (px)', 'mds-release-manager' ), 40, 0, 120, 1 ),
			'mds_rm_artist_fallback_padding_horizontal' => array( __( 'Page horizontal padding (px)', 'mds-release-manager' ), 20, 0, 120, 1 ),
			'mds_rm_artist_fallback_image_radius'    => array( __( 'Portrait corner radius (%)', 'mds-release-manager' ), 50, 0, 50, 1 ),
			'mds_rm_artist_fallback_contact_radius'  => array( __( 'Contact corner radius (px)', 'mds-release-manager' ), 8, 0, 100, 1 ),
			'mds_rm_artist_fallback_body_line_height' => array( __( 'Biography line height (%)', 'mds-release-manager' ), 160, 80, 300, 10 ),
		);
		foreach ( $artist_controls as $setting => $control ) {
			$wp_customize->add_setting(
				$setting,
				array(
					'default'           => $control[1],
					'sanitize_callback' => array( self::class, 'sanitize_fallback_number' ),
				)
			);
			$wp_customize->add_control( new MDS_RM_Customizer_Control(
				$wp_customize,
				$setting,
				array(
					'label'       => $control[0],
					'section'     => 'mds_rm_artist_section',
					'component_type' => 'range',
					'input_attrs' => array(
						'min'  => $control[2],
						'max'  => $control[3],
						'step' => $control[4],
					),
				)
			) );
		}
		self::add_shadow_control( $wp_customize, 'mds_rm_artist_fallback_image_shadow', __( 'Portrait shadow', 'mds-release-manager' ), 'mds_rm_artist_section' );
		self::add_color_control( $wp_customize, 'mds_rm_artist_fallback_contact_background', __( 'Contact background', 'mds-release-manager' ), 'mds_rm_artist_section' );
		self::add_color_control( $wp_customize, 'mds_rm_artist_fallback_contact_color', __( 'Contact text color', 'mds-release-manager' ), 'mds_rm_artist_section' );

		self::order_groups(
			$wp_customize,
			array(
				array( __( 'Layout', 'mds-release-manager' ), array(
					'mds_rm_layout_style',
					'mds_rm_release_fallback_max_width',
					'mds_rm_release_fallback_column_gap',
					'mds_rm_release_fallback_component_gap',
				) ),
				array( __( 'Featured image', 'mds-release-manager' ), array(
					'mds_rm_release_fallback_image_radius',
					'mds_rm_release_fallback_image_shadow',
				) ),
				array( __( 'Content typography', 'mds-release-manager' ), array(
					'mds_rm_release_fallback_content_font_size',
					'mds_rm_release_fallback_content_line_height',
					'mds_rm_release_fallback_content_color',
				) ),
			)
		);
		self::order_groups(
			$wp_customize,
			array(
				array( __( 'Layout', 'mds-release-manager' ), array(
					'mds_rm_artist_fallback_width',
					'mds_rm_artist_fallback_gap',
					'mds_rm_artist_fallback_padding_vertical',
					'mds_rm_artist_fallback_padding_horizontal',
				) ),
				array( __( 'Portrait', 'mds-release-manager' ), array(
					'mds_rm_artist_fallback_image_size',
					'mds_rm_artist_fallback_image_radius',
					'mds_rm_artist_fallback_image_shadow',
				) ),
				array( __( 'Biography', 'mds-release-manager' ), array(
					'mds_rm_artist_fallback_body_line_height',
				) ),
				array( __( 'Contact information', 'mds-release-manager' ), array(
					'mds_rm_artist_fallback_contact_padding',
					'mds_rm_artist_fallback_contact_radius',
					'mds_rm_artist_fallback_contact_background',
					'mds_rm_artist_fallback_contact_color',
				) ),
			)
		);
	}

	private static function add_color_control( WP_Customize_Manager $wp_customize, string $setting, string $label, string $section ): void {
		$wp_customize->add_setting(
			$setting,
			array(
				'default'           => '',
				'sanitize_callback' => array( self::class, 'sanitize_fallback_color' ),
			)
		);
		$wp_customize->add_control(
			new MDS_RM_Customizer_Control(
				$wp_customize,
				$setting,
				array(
					'label'          => $label,
					'section'        => $section,
					'component_type' => 'color',
				)
			)
		);
	}

	/**
	 * Orders controls using Customizer priorities so visual and keyboard order agree.
	 *
	 * @param WP_Customize_Manager $wp_customize The Customizer manager.
	 * @param array                $groups       Ordered headings and their control IDs.
	 */
	private static function order_groups( WP_Customize_Manager $wp_customize, array $groups ): void {
		$priority = 10;
		foreach ( $groups as list( $heading, $ids ) ) {
			foreach ( $ids as $index => $id ) {
				$control                = $wp_customize->get_control( $id );
				$control->priority      = $priority;
				$control->group_heading = 0 === $index ? $heading : '';
				$priority              += 5;
			}
		}
	}

	/** Registers the existing image shadow as a reversible choice. */
	private static function add_shadow_control( $wp_customize, string $setting, string $label, string $section ): void {
		$wp_customize->add_setting(
			$setting,
			array(
				'default'           => 'default',
				'sanitize_callback' => array( self::class, 'sanitize_shadow' ),
			)
		);
		$wp_customize->add_control(
			new MDS_RM_Customizer_Control(
				$wp_customize,
				$setting,
				array(
					'label'          => $label,
					'section'        => $section,
					'component_type' => 'radio',
					'choices'        => array( 'default' => __( 'Default shadow', 'mds-release-manager' ), 'none' => __( 'No shadow', 'mds-release-manager' ) ),
				)
			)
		);
	}

	/** Validates the image shadow choice. */
	public static function sanitize_shadow( mixed $value ): ?string {
		return in_array( $value, array( 'default', 'none' ), true ) ? $value : null;
	}

	/**
	 * Adds declarations only for new settings that have actually been saved.
	 *
	 * @param array $styles Existing declarations.
	 * @param array $settings CSS property to setting, minimum, maximum, unit and divisor.
	 */
	private static function append_optional_numbers( array &$styles, array $settings ): void {
		foreach ( $settings as $property => $setting ) {
			$value = get_theme_mod( $setting[0], null );
			if ( null === $value || ! is_scalar( $value ) ) {
				continue;
			}
			$value    = min( $setting[2], max( $setting[1], absint( $value ) ) ) / $setting[4];
			$styles[] = $property . ':' . $value . $setting[3];
		}
	}

	/**
	 * Builds inline custom properties for the Artist fallback.
	 *
	 * @return string CSS custom-property declarations.
	 */
	public static function get_artist_fallback_style_attribute(): string {
		$settings = array(
			'--mds-artist-width'           => array( 'mds_rm_artist_fallback_width', 900, 320, 2000 ),
			'--mds-artist-image-size'      => array( 'mds_rm_artist_fallback_image_size', 300, 80, 800 ),
			'--mds-artist-gap'             => array( 'mds_rm_artist_fallback_gap', 25, 0, 100 ),
			'--mds-artist-contact-padding' => array( 'mds_rm_artist_fallback_contact_padding', 20, 0, 100 ),
		);
		$styles   = array();
		foreach ( $settings as $property => $setting ) {
			$value    = get_theme_mod( $setting[0], $setting[1] );
			$value    = is_scalar( $value ) ? absint( $value ) : $setting[1];
			$styles[] = $property . ':' . min( $setting[3], max( $setting[2], $value ) ) . 'px';
		}
		self::append_optional_numbers(
			$styles,
			array(
				'--mds-artist-padding-vertical'   => array( 'mds_rm_artist_fallback_padding_vertical', 0, 120, 'px', 1 ),
				'--mds-artist-padding-horizontal' => array( 'mds_rm_artist_fallback_padding_horizontal', 0, 120, 'px', 1 ),
				'--mds-artist-image-radius'       => array( 'mds_rm_artist_fallback_image_radius', 0, 50, '%', 1 ),
				'--mds-artist-contact-radius'     => array( 'mds_rm_artist_fallback_contact_radius', 0, 100, 'px', 1 ),
				'--mds-artist-body-line-height'   => array( 'mds_rm_artist_fallback_body_line_height', 80, 300, '', 100 ),
			)
		);
		if ( 'none' === get_theme_mod( 'mds_rm_artist_fallback_image_shadow', 'default' ) ) {
			$styles[] = '--mds-artist-image-shadow:none';
		}
		foreach ( array(
			'--mds-artist-contact-background' => 'mds_rm_artist_fallback_contact_background',
			'--mds-artist-contact-color'      => 'mds_rm_artist_fallback_contact_color',
		) as $property => $setting ) {
			$value = MDS_RM_Release_Meta::valid_style( get_theme_mod( $setting, '' ), 'color' );
			if ( $value ) {
				$styles[] = $property . ':' . $value;
			}
		}
		return implode( ';', $styles );
	}

	/**
	 * Sanitizes the split/stacked fallback choice.
	 *
	 * @param mixed $value The submitted layout.
	 * @return string The valid layout.
	 */
	public static function sanitize_release_layout( mixed $value ): string {
		return in_array( $value, array( 'split', 'stacked' ), true ) ? $value : 'split';
	}

	/**
	 * Sanitizes numeric Customizer values.
	 *
	 * @param mixed $value The submitted value.
	 * @return int The non-negative integer value.
	 */
	public static function sanitize_fallback_number( mixed $value ): int {
		return is_scalar( $value ) ? min( 2000, absint( $value ) ) : 0;
	}

	/**
	 * Sanitizes a fallback color, including alpha colors from the native picker.
	 *
	 * @param mixed $value Submitted color.
	 * @return string|null Safe color, an empty value to inherit, or null if invalid.
	 */
	public static function sanitize_fallback_color( mixed $value ): ?string {
		if ( '' === $value ) {
			return '';
		}
		$color = MDS_RM_Release_Meta::valid_style( $value, 'color' );
		return '' !== $color ? $color : null;
	}

	/**
	 * Builds safe inline CSS variables for the Release fallback partial.
	 *
	 * @return string CSS custom-property declarations.
	 */
	public static function get_release_fallback_style_attribute(): string {
		$number_settings = array(
			'--mds-fallback-max-width'          => array( 'mds_rm_release_fallback_max_width', 1200, 480, 2000 ),
			'--mds-fallback-column-gap'         => array( 'mds_rm_release_fallback_column_gap', 40, 0, 120 ),
			'--mds-fallback-component-gap'      => array( 'mds_rm_release_fallback_component_gap', 25, 0, 100 ),
			'--mds-fallback-image-radius'       => array( 'mds_rm_release_fallback_image_radius', 8, 0, 100 ),
			'--mds-fallback-content-font-size'  => array( 'mds_rm_release_fallback_content_font_size', 16, 12, 32 ),
		);
		$styles          = array();

		foreach ( $number_settings as $property => $setting ) {
			$stored_value = get_theme_mod( $setting[0], $setting[1] );
			$value        = is_scalar( $stored_value ) ? absint( $stored_value ) : $setting[1];
			$value        = min( $setting[3], max( $setting[2], $value ) );
			$styles[]     = $property . ':' . $value . 'px';
		}
		self::append_optional_numbers(
			$styles,
			array( '--mds-fallback-content-line-height' => array( 'mds_rm_release_fallback_content_line_height', 80, 300, '', 100 ) )
		);
		if ( 'none' === get_theme_mod( 'mds_rm_release_fallback_image_shadow', 'default' ) ) {
			$styles[] = '--mds-fallback-image-shadow:none';
		}

		$color = self::sanitize_fallback_color( get_theme_mod( 'mds_rm_release_fallback_content_color', '' ) );
		if ( $color ) {
			$styles[] = '--mds-fallback-content-color:' . $color;
		}

		return implode( ';', $styles );
	}
}

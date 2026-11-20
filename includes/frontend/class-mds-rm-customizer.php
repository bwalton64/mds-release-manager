<?php
declare(strict_types=1);

/**
 * Registers the Release fallback Customizer settings.
 *
 * @package MDS_Release_Manager
 */

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
	}

	/**
	 * Adds Release fallback layout and component styles.
	 *
	 * @since 0.9.0
	 * @param WP_Customize_Manager $wp_customize The Customizer manager.
	 */
	public static function customize_register( $wp_customize ): void {
		$wp_customize->add_panel(
			'mds_rm_panel',
			array(
				'priority'    => 130,
				'title'       => 'MDS Release Manager',
				'description' => 'Customize the Release fallback layout and component styles.',
			)
		);

		// Fallback Layout Settings.
		$wp_customize->add_section(
			'mds_rm_release_section',
			array(
				'title'    => 'Release Fallback Template',
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
		$wp_customize->add_control(
			'mds_rm_layout_style',
			array(
				'label'   => 'Release Fallback Template',
				'section' => 'mds_rm_release_section',
				'type'    => 'radio',
				'choices' => array(
					'split'   => 'Split',
					'stacked' => 'Stacked',
				),
			)
		);

		$number_controls = array(
			'mds_rm_release_fallback_max_width' => array( 'Maximum content width (px)', 1200, 480, 2000, 40 ),
			'mds_rm_release_fallback_column_gap' => array( 'Image and content gap (px)', 40, 0, 120, 4 ),
			'mds_rm_release_fallback_component_gap' => array( 'Component spacing (px)', 25, 0, 100, 1 ),
			'mds_rm_release_fallback_image_radius' => array( 'Featured image corner radius (px)', 8, 0, 100, 1 ),
			'mds_rm_release_fallback_content_font_size' => array( 'Content font size (px)', 16, 12, 32, 1 ),
			'mds_rm_release_fallback_meta_gap' => array( 'Release Meta item gap (px)', 15, 0, 80, 1 ),
			'mds_rm_release_fallback_meta_padding_vertical' => array( 'Release Meta vertical padding (px)', 12, 0, 60, 1 ),
			'mds_rm_release_fallback_meta_padding_horizontal' => array( 'Release Meta horizontal padding (px)', 18, 0, 80, 1 ),
			'mds_rm_release_fallback_links_gap' => array( 'Release Links component gap (px)', 15, 0, 80, 1 ),
			'mds_rm_release_fallback_button_size' => array( 'Release Links button size (px)', 44, 24, 96, 2 ),
			'mds_rm_release_fallback_button_radius' => array( 'Release Links button radius (px)', 50, 0, 50, 1 ),
			'mds_rm_release_fallback_icon_size' => array( 'Release Links icon size (px)', 22, 12, 64, 1 ),
		);

		foreach ( $number_controls as $setting => $control ) {
			$wp_customize->add_setting(
				$setting,
				array(
					'default'           => $control[1],
					'sanitize_callback' => array( self::class, 'sanitize_fallback_number' ),
				)
			);
			$wp_customize->add_control(
				$setting,
				array(
					'label'       => $control[0],
					'section'     => 'mds_rm_release_section',
					'type'        => 'number',
					'input_attrs' => array(
						'min'  => $control[2],
						'max'  => $control[3],
						'step' => $control[4],
					),
				)
			);
		}

		$color_controls = array(
			'mds_rm_release_fallback_meta_background' => 'Release Meta background',
			'mds_rm_release_fallback_meta_color' => 'Release Meta text color',
			'mds_rm_release_fallback_content_color' => 'Content text color',
			'mds_rm_release_fallback_links_background' => 'Release Links background',
			'mds_rm_release_fallback_button_background' => 'Release Links button background',
			'mds_rm_release_fallback_button_color' => 'Release Links button icon color',
		);

		foreach ( $color_controls as $setting => $label ) {
			$wp_customize->add_setting(
				$setting,
				array(
					'default'           => '',
					'sanitize_callback' => 'sanitize_hex_color',
				)
			);
			$wp_customize->add_control(
				new WP_Customize_Color_Control(
					$wp_customize,
					$setting,
					array(
						'label'   => $label,
						'section' => 'mds_rm_release_section',
					)
				)
			);
		}

		$wp_customize->add_section(
			'mds_rm_artist_section',
			array(
				'title' => 'Artist Fallback Template',
				'panel' => 'mds_rm_panel',
				'priority' => 20,
			)
		);
		$artist_controls = array(
			'mds_rm_artist_fallback_width' => array( 'Maximum content width (px)', 900, 320, 2000, 10 ),
			'mds_rm_artist_fallback_image_size' => array( 'Portrait size (px)', 300, 80, 800, 10 ),
			'mds_rm_artist_fallback_gap' => array( 'Section spacing (px)', 25, 0, 100, 1 ),
			'mds_rm_artist_fallback_contact_padding' => array( 'Contact padding (px)', 20, 0, 100, 1 ),
			'mds_rm_artist_fallback_table_font_size' => array( 'Discography font size (px)', 16, 12, 32, 1 ),
		);
		foreach ( $artist_controls as $setting => $control ) {
			$wp_customize->add_setting( $setting, array( 'default' => $control[1], 'sanitize_callback' => array( self::class, 'sanitize_fallback_number' ) ) );
			$wp_customize->add_control( $setting, array(
				'label' => $control[0],
				'section' => 'mds_rm_artist_section',
				'type' => 'number',
				'input_attrs' => array( 'min' => $control[2], 'max' => $control[3], 'step' => $control[4] ),
			) );
		}
		foreach ( array( 'mds_rm_artist_fallback_contact_background' => 'Contact background', 'mds_rm_artist_fallback_contact_color' => 'Contact text color', 'mds_rm_artist_fallback_table_background' => 'Discography background', 'mds_rm_artist_fallback_table_color' => 'Discography text color', 'mds_rm_artist_fallback_table_border' => 'Discography border color' ) as $setting => $label ) {
			$wp_customize->add_setting( $setting, array( 'default' => '', 'sanitize_callback' => 'sanitize_hex_color' ) );
			$wp_customize->add_control( new WP_Customize_Color_Control( $wp_customize, $setting, array( 'label' => $label, 'section' => 'mds_rm_artist_section' ) ) );
		}
	}

	public static function get_artist_fallback_style_attribute(): string {
		$settings = array(
			'--mds-artist-width' => array( 'mds_rm_artist_fallback_width', 900, 320, 2000 ),
			'--mds-artist-image-size' => array( 'mds_rm_artist_fallback_image_size', 300, 80, 800 ),
			'--mds-artist-gap' => array( 'mds_rm_artist_fallback_gap', 25, 0, 100 ),
			'--mds-artist-contact-padding' => array( 'mds_rm_artist_fallback_contact_padding', 20, 0, 100 ),
			'--mds-artist-table-font-size' => array( 'mds_rm_artist_fallback_table_font_size', 16, 12, 32 ),
		);
		$styles = array();
		foreach ( $settings as $property => $setting ) {
			$value = get_theme_mod( $setting[0], $setting[1] );
			$value = is_scalar( $value ) ? absint( $value ) : $setting[1];
			$styles[] = $property . ':' . min( $setting[3], max( $setting[2], $value ) ) . 'px';
		}
		foreach ( array( '--mds-artist-contact-background' => 'mds_rm_artist_fallback_contact_background', '--mds-artist-contact-color' => 'mds_rm_artist_fallback_contact_color', '--mds-artist-table-background' => 'mds_rm_artist_fallback_table_background', '--mds-artist-table-color' => 'mds_rm_artist_fallback_table_color', '--mds-artist-table-border' => 'mds_rm_artist_fallback_table_border' ) as $property => $setting ) {
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
	 * Builds safe inline CSS variables for the Release fallback partial.
	 *
	 * @return string CSS custom-property declarations.
	 */
	public static function get_release_fallback_style_attribute(): string {
		$number_settings = array(
			'--mds-fallback-max-width' => array( 'mds_rm_release_fallback_max_width', 1200, 480, 2000 ),
			'--mds-fallback-column-gap' => array( 'mds_rm_release_fallback_column_gap', 40, 0, 120 ),
			'--mds-fallback-component-gap' => array( 'mds_rm_release_fallback_component_gap', 25, 0, 100 ),
			'--mds-fallback-image-radius' => array( 'mds_rm_release_fallback_image_radius', 8, 0, 100 ),
			'--mds-fallback-content-font-size' => array( 'mds_rm_release_fallback_content_font_size', 16, 12, 32 ),
			'--mds-meta-gap-desktop' => array( 'mds_rm_release_fallback_meta_gap', 15, 0, 80 ),
			'--mds-links-gap-desktop' => array( 'mds_rm_release_fallback_links_gap', 15, 0, 80 ),
			'--mds-links-link-gap-desktop' => array( 'mds_rm_release_fallback_links_gap', 15, 0, 80 ),
			'--mds-links-button-size-desktop' => array( 'mds_rm_release_fallback_button_size', 44, 24, 96 ),
			'--mds-links-button-size-tablet' => array( 'mds_rm_release_fallback_button_size', 44, 24, 96 ),
			'--mds-links-button-size-mobile' => array( 'mds_rm_release_fallback_button_size', 44, 24, 96 ),
			'--mds-links-button-radius-desktop' => array( 'mds_rm_release_fallback_button_radius', 50, 0, 50 ),
			'--mds-links-button-radius-tablet' => array( 'mds_rm_release_fallback_button_radius', 50, 0, 50 ),
			'--mds-links-button-radius-mobile' => array( 'mds_rm_release_fallback_button_radius', 50, 0, 50 ),
			'--mds-links-icon-size-desktop' => array( 'mds_rm_release_fallback_icon_size', 22, 12, 64 ),
			'--mds-links-icon-size-tablet' => array( 'mds_rm_release_fallback_icon_size', 22, 12, 64 ),
			'--mds-links-icon-size-mobile' => array( 'mds_rm_release_fallback_icon_size', 22, 12, 64 ),
		);
		$styles          = array();

		foreach ( $number_settings as $property => $setting ) {
			$stored_value = get_theme_mod( $setting[0], $setting[1] );
			$value        = is_scalar( $stored_value ) ? absint( $stored_value ) : $setting[1];
			$value        = min( $setting[3], max( $setting[2], $value ) );
			$styles[]     = $property . ':' . $value . 'px';
		}

		$meta_padding_vertical = get_theme_mod( 'mds_rm_release_fallback_meta_padding_vertical', 12 );
		$meta_padding_vertical = is_scalar( $meta_padding_vertical ) ? min( 60, max( 0, absint( $meta_padding_vertical ) ) ) : 12;
		$meta_padding_horizontal = get_theme_mod( 'mds_rm_release_fallback_meta_padding_horizontal', 18 );
		$meta_padding_horizontal = is_scalar( $meta_padding_horizontal ) ? min( 80, max( 0, absint( $meta_padding_horizontal ) ) ) : 18;
		$styles[] = '--mds-meta-padding-desktop:' . $meta_padding_vertical . 'px ' . $meta_padding_horizontal . 'px';

		$color_settings = array(
			'--mds-meta-background-desktop' => 'mds_rm_release_fallback_meta_background',
			'--mds-meta-color-desktop' => 'mds_rm_release_fallback_meta_color',
			'--mds-fallback-content-color' => 'mds_rm_release_fallback_content_color',
			'--mds-links-background-desktop' => 'mds_rm_release_fallback_links_background',
			'--mds-links-button-background-desktop' => 'mds_rm_release_fallback_button_background',
			'--mds-links-button-color-desktop' => 'mds_rm_release_fallback_button_color',
		);

		foreach ( $color_settings as $property => $setting ) {
			$value = MDS_RM_Release_Meta::valid_style( get_theme_mod( $setting, '' ), 'color' );
			if ( $value ) {
				$styles[] = $property . ':' . $value;
			}
		}

		return implode( ';', $styles );
	}
}

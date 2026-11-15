<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'customize_register', 'mds_rm_customize_register' );
function mds_rm_customize_register( $wp_customize ) {
    $wp_customize->add_panel( 'mds_rm_panel', array(
        'priority'       => 130,
        'title'          => 'Releases',
        'description'    => 'Customize the default appearance of the Release Widget and fallback layouts.',
    ) );

    // Fallback Layout Settings
    $wp_customize->add_section( 'mds_rm_release_section', array(
        'title'    => 'Fallback Templates',
        'panel'    => 'mds_rm_panel',
        'priority' => 10,
    ) );
    
    $wp_customize->add_setting( 'mds_rm_layout_style', array( 'default' => 'split' ) );
    $wp_customize->add_control( 'mds_rm_layout_style', array(
        'label'   => 'Layout Style',
        'section' => 'mds_rm_release_section',
        'type'    => 'radio',
        'choices' => array(
            'split'  => 'Split (Image Left, Content Right)',
            'stacked'=> 'Stacked (Centered)',
        ),
    ) );
    
    // Release Widget Styling
    $wp_customize->add_section( 'mds_rm_widget_section', array(
        'title'    => 'Release Widget Styling',
        'panel'    => 'mds_rm_panel',
        'priority' => 15,
        'description' => 'Granular control over the Release Widget styling (Streaming Links and Buy Now button).'
    ) );
    
    $wp_customize->add_setting( 'mds_rm_icon_size', array( 'default' => '24' ) );
    $wp_customize->add_control( 'mds_rm_icon_size', array(
        'label'   => 'Icon Size (px)',
        'section' => 'mds_rm_widget_section',
        'type'    => 'number',
    ) );
    
    $wp_customize->add_setting( 'mds_rm_icon_padding', array( 'default' => '12' ) );
    $wp_customize->add_control( 'mds_rm_icon_padding', array(
        'label'   => 'Icon Padding (px)',
        'section' => 'mds_rm_widget_section',
        'type'    => 'number',
    ) );

    $wp_customize->add_setting( 'mds_rm_icon_radius', array( 'default' => '50' ) );
    $wp_customize->add_control( 'mds_rm_icon_radius', array(
        'label'   => 'Border Radius (%)',
        'section' => 'mds_rm_widget_section',
        'type'    => 'number',
        'description' => 'Use 50 for circles, 0 for squares.',
    ) );

    $wp_customize->add_setting( 'mds_rm_icon_gap', array( 'default' => '15' ) );
    $wp_customize->add_control( 'mds_rm_icon_gap', array(
        'label'   => 'Gap Between Items (px)',
        'section' => 'mds_rm_widget_section',
        'type'    => 'number',
    ) );
    
    $wp_customize->add_setting( 'mds_rm_provider_color', array( 'default' => '#ffffff' ) );
    $wp_customize->add_control( new WP_Customize_Color_Control( $wp_customize, 'mds_rm_provider_color', array(
        'label'   => 'Provider Icon Color',
        'section' => 'mds_rm_widget_section',
    ) ) );

    $wp_customize->add_setting( 'mds_rm_provider_bg', array( 'default' => '#ffffff' ) );
    $wp_customize->add_control( new WP_Customize_Color_Control( $wp_customize, 'mds_rm_provider_bg', array(
        'label'   => 'Provider Background Color',
        'description' => 'Leave blank to use the individual brand colors configured in Releases > Settings.',
        'section' => 'mds_rm_widget_section',
    ) ) );

    $wp_customize->add_setting( 'mds_rm_buy_color', array( 'default' => '#ffffff' ) );
    $wp_customize->add_control( new WP_Customize_Color_Control( $wp_customize, 'mds_rm_buy_color', array(
        'label'   => 'Buy Now Icon Color',
        'section' => 'mds_rm_widget_section',
    ) ) );

    $wp_customize->add_setting( 'mds_rm_buy_bg', array( 'default' => '#2271b1' ) );
    $wp_customize->add_control( new WP_Customize_Color_Control( $wp_customize, 'mds_rm_buy_bg', array(
        'label'   => 'Buy Now Background Color',
        'section' => 'mds_rm_widget_section',
    ) ) );

    // WooCommerce Settings
    $wp_customize->add_section( 'mds_rm_woo_section', array(
        'title'    => 'WooCommerce Integration',
        'panel'    => 'mds_rm_panel',
        'priority' => 20,
    ) );
    
    $wp_customize->add_setting( 'mds_rm_woo_injection', array( 'default' => 'woocommerce_single_product_summary:25' ) );
    $wp_customize->add_control( 'mds_rm_woo_injection', array(
        'label'   => 'Meta Injection Hook',
        'description' => 'Where should release metadata appear on Single Product pages?',
        'section' => 'mds_rm_woo_section',
        'type'    => 'select',
        'choices' => array(
            'woocommerce_single_product_summary:5'  => 'Above Title',
            'woocommerce_single_product_summary:15' => 'Below Price',
            'woocommerce_single_product_summary:25' => 'Below Excerpt (Default)',
            'woocommerce_single_product_summary:45' => 'Below Add to Cart',
            'woocommerce_after_single_product_summary:10' => 'Below Summary Container',
            'disabled' => 'Disabled',
        ),
    ) );
}

add_action( 'wp_head', 'mds_rm_customizer_css_output' );
function mds_rm_customizer_css_output() {
    $size = get_theme_mod( 'mds_rm_icon_size', '24' );
    $pad = get_theme_mod( 'mds_rm_icon_padding', '12' );
    $rad = get_theme_mod( 'mds_rm_icon_radius', '50' );
    $gap = get_theme_mod( 'mds_rm_icon_gap', '15' );
    
    $p_col = get_theme_mod( 'mds_rm_provider_color', '#ffffff' );
    $p_bg = get_theme_mod( 'mds_rm_provider_bg', '#ffffff' );
    $b_col = get_theme_mod( 'mds_rm_buy_color', '#ffffff' );
    $b_bg = get_theme_mod( 'mds_rm_buy_bg', '#2271b1' );

    $total_size = intval($size) + (intval($pad) * 2);

    $css = '<style id="mds-rm-customizer-styles">';
    
    // Links Widget Base
    $css .= ".mds-icon-circle { width: {$total_size}px; height: {$total_size}px; font-size: {$size}px; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; border-radius: {$rad}%; transition: opacity 0.2s; } ";
    $css .= ".mds-icon-circle:hover { opacity: 0.8; } ";
    $css .= ".mds-icons-wrapper { display: flex; flex-wrap: wrap; gap: {$gap}px; } ";
    
    // Provider Colors
    $css .= ".mds-icon-circle:not(.mds-buy-now) { color: " . esc_attr( $p_col ) . " !important; } ";
    $css .= ".mds-icon-circle:not(.mds-buy-now) { background-color: " . esc_attr( $p_bg ) . "; } ";

    // Buy Now Colors
    $css .= ".mds-icon-circle.mds-buy-now { color: " . esc_attr( $b_col ) . " !important; background-color: " . esc_attr( $b_bg ) . " !important; } ";

    $css .= '</style>';
    echo $css;
}

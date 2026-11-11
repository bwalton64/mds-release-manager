<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
    return;
}

class MDS_Release_Links_Widget extends \Elementor\Widget_Base {
    public function get_name() { return 'mds_release_links'; }
    public function get_title() { return 'MDS Release Links'; }
    public function get_icon() { return 'eicon-play'; }
    public function get_categories() { return [ 'general' ]; }
    
    protected function register_controls() {
        $this->start_controls_section('content_section', ['label' => 'Content', 'tab' => \Elementor\Controls_Manager::TAB_CONTENT]);
        $this->add_control('upc', [
            'label' => 'UPC/EAN Override',
            'type' => \Elementor\Controls_Manager::TEXT,
            'description' => 'Leave blank to dynamically use the current Release post.',
        ]);
        $this->end_controls_section();
    }
    
    protected function render() {
        $settings = $this->get_settings_for_display();
        $upc = ! empty( $settings['upc'] ) ? $settings['upc'] : get_post_meta( get_the_ID(), 'upc_ean', true );
        if ( $upc ) {
            echo do_shortcode('[release_links upc="' . esc_attr($upc) . '"]');
        }
    }
}

class MDS_Release_Meta_Widget extends \Elementor\Widget_Base {
    public function get_name() { return 'mds_release_meta_bar'; }
    public function get_title() { return 'MDS WooCommerce Meta Bar'; }
    public function get_icon() { return 'eicon-tags'; }
    public function get_categories() { return [ 'general' ]; }
    
    protected function register_controls() {
        $this->start_controls_section('content_section', ['label' => 'Content', 'tab' => \Elementor\Controls_Manager::TAB_CONTENT]);
        $this->add_control('upc', [
            'label' => 'UPC/EAN Override',
            'type' => \Elementor\Controls_Manager::TEXT,
            'description' => 'Leave blank to dynamically use the current Release post.',
        ]);
        $this->add_control('include_shortcode', [
            'label' => 'Include Links Widget in Bar',
            'type' => \Elementor\Controls_Manager::SWITCHER,
            'default' => 'yes',
        ]);
        $this->end_controls_section();
    }
    
    protected function render() {
        $settings = $this->get_settings_for_display();
        $upc = ! empty( $settings['upc'] ) ? $settings['upc'] : get_post_meta( get_the_ID(), 'upc_ean', true );
        $inc_shortcode = ( $settings['include_shortcode'] === 'yes' );
        if ( $upc ) {
            echo mds_render_meta_bar_html( $upc, $inc_shortcode );
        }
    }
}

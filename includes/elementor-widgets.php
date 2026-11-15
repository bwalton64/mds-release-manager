<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( '\Elementor\Widget_Base' ) ) return;

use Elementor\Controls_Manager;

class MDS_Release_Widget extends \Elementor\Widget_Base {
    public function get_name() { return 'mds_release_widget'; }
    public function get_title() { return 'Release Widget'; }
    public function get_icon() { return 'eicon-album'; }
    public function get_categories() { return [ 'general' ]; }
    
    protected function register_controls() {
        $this->start_controls_section('content_section', ['label' => 'Content', 'tab' => Controls_Manager::TAB_CONTENT]);
        $this->add_control('upc', [
            'label' => 'UPC/EAN Override',
            'type' => Controls_Manager::TEXT,
            'description' => 'Leave blank to dynamically use the current Release post.',
        ]);
        $this->add_control('styling_note', [
            'type' => Controls_Manager::RAW_HTML,
            'raw' => '<p style="font-size:12px; color:#666;">Note: Visual styling for this widget is controlled globally in <b>Appearance -> Customize -> Releases</b>.</p>',
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

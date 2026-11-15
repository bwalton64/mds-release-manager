<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// --- 5.5 Elementor Support ---
add_action( 'init', 'mds_add_elementor_support_artist', 99 );
function mds_add_elementor_support_artist() {
    add_post_type_support( 'artist', 'elementor' );
}

add_action( 'elementor/dynamic_tags/register', 'mds_rm_register_elementor_tags' );
function mds_rm_register_elementor_tags( $dynamic_tags_manager ) {
    if ( ! class_exists( '\Elementor\Core\DynamicTags\Data_Tag' ) ) {
        return;
    }

    class MDS_Release_Meta_Tag extends \Elementor\Core\DynamicTags\Data_Tag {
        public function get_name() { return 'mds_release_meta'; }
        public function get_title() { return 'Release Metadata'; }
        public function get_group() { return 'post'; }
        public function get_categories() {
            return array(
                \Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY,
                \Elementor\Modules\DynamicTags\Module::URL_CATEGORY,
                \Elementor\Modules\DynamicTags\Module::IMAGE_CATEGORY,
            );
        }
        protected function register_controls() {
            $this->add_control( 'mds_meta_key', array(
                'label'   => 'Select Field',
                'type'    => \Elementor\Controls_Manager::SELECT,
                'options' => array(
                    'upc_ean'              => 'UPC/EAN',
                    'release_year'         => 'Release Year (Derived)',
                    'record_label'         => 'Record Label',
                    'distributor'          => 'Distributor',
                    'release_format'       => 'Release Format',
                    'release_tags'         => 'Release Tags',
                    'buy_now_link'         => 'Buy Now Link',
                    'featured_image_url'   => 'Featured Image URL',
                    'bandcamp_shortcode'   => 'Bandcamp Shortcode',
                    'stream_Spotify'       => 'Spotify Link',
                    'stream_Apple Music'   => 'Apple Music Link',
                    'stream_Amazon Music'  => 'Amazon Music Link',
                    'stream_YouTube Music' => 'YouTube Music Link',
                    'stream_Soundcloud'    => 'Soundcloud Link',
                ),
            ) );
        }
        public function get_value( array $options = array() ) {
            $key = $this->get_settings( 'mds_meta_key' );
            if ( empty( $key ) ) return '';

            $post_id = get_the_ID();
            if ( ! $post_id ) return '';

            if ( strpos( $key, 'stream_' ) === 0 ) {
                $provider = str_replace( 'stream_', '', $key );
                $json = get_post_meta( $post_id, 'streaming_links', true );
                $links = json_decode( $json, true );
                return isset( $links[ $provider ] ) ? $links[ $provider ] : '';
            }

            if ( $key === 'release_format' ) {
                $format = get_post_meta( $post_id, 'release_format', true );
                if ( ! $format ) {
                    $terms = wp_get_post_terms( $post_id, 'release_format', array('fields' => 'names') );
                    if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) $format = $terms[0];
                }
                return $format;
            }

            if ( $key === 'release_tags' ) {
                $tags = get_post_meta( $post_id, 'release_tags', true );
                if ( ! $tags ) {
                    $terms = wp_get_post_terms( $post_id, 'release_tag', array('fields' => 'names') );
                    if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) $tags = implode(', ', $terms);
                }
                return $tags;
            }

            if ( $key === 'release_year' ) {
                $date = get_post_field( 'post_date', $post_id );
                return ( ! empty( $date ) && $date !== '0000-00-00 00:00:00' ) ? date( 'Y', strtotime( $date ) ) : '';
            }

            if ( $key === 'featured_image_url' ) {
                $img = get_post_meta( $post_id, 'featured_image_url', true );
                return array( 'id' => '', 'url' => $img ? $img : '' );
            }

            return get_post_meta( $post_id, $key, true ) ?: '';
        }
    }
    $dynamic_tags_manager->register( new MDS_Release_Meta_Tag() );
}

add_action( 'elementor/widgets/register', 'mds_rm_register_elementor_widgets' );
function mds_rm_register_elementor_widgets( $widgets_manager ) {
    require_once MDS_RM_PATH . 'includes/elementor-widgets.php';
    $widgets_manager->register( new \MDS_Release_Widget() );
}

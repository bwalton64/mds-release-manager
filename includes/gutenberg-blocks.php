<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'enqueue_block_editor_assets', 'mds_rm_enqueue_block_editor_assets' );
function mds_rm_enqueue_block_editor_assets() {
    wp_enqueue_script(
        'mds-rm-blocks-js',
        MDS_RM_URL . 'js/blocks.js',
        array( 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-compose', 'wp-hooks', 'wp-server-side-render', 'wp-block-editor' ),
        MDS_RELEASE_MANAGER_VERSION,
        true
    );
    
    wp_add_inline_style( 'wp-block-editor', '
        .mds-dynamic-block-active { position: relative; border: 1px dashed #2271b1; padding: 2px; }
        .mds-dynamic-block-active::after { 
            content: "Dynamic: MDS"; position: absolute; top: -10px; right: 0; 
            background: #2271b1; color: #fff; font-size: 10px; padding: 2px 6px; 
            border-radius: 3px; z-index: 10; pointer-events: none;
        }
    ' );
}

add_action( 'init', 'mds_rm_register_gutenberg_widget_blocks' );
function mds_rm_register_gutenberg_widget_blocks() {
    
    register_block_type( 'mds-rm/release-widget', array(
        'render_callback' => 'mds_rm_render_gutenberg_release_widget',
        'attributes'      => array( 'upcOverride' => array( 'type' => 'string', 'default' => '' ) )
    ) );

}


function mds_rm_render_gutenberg_release_widget( $attributes, $content, $block ) {
    $upc = ! empty( $attributes['upcOverride'] ) ? $attributes['upcOverride'] : '';
    if ( empty($upc) && isset($block->context['postId']) ) {
        $upc = get_post_meta( $block->context['postId'], 'upc_ean', true );
    }
    if ( empty($upc) ) $upc = get_post_meta( get_the_ID(), 'upc_ean', true );
    return $upc ? do_shortcode( '[release_links upc="' . esc_attr( $upc ) . '"]' ) : '';
}


add_filter( 'render_block', 'mds_rm_render_core_block_with_meta', 10, 2 );
function mds_rm_render_core_block_with_meta( $block_content, $block ) {
    if ( empty( $block['attrs']['mdsMetaField'] ) ) return $block_content;

    $meta_field = $block['attrs']['mdsMetaField'];
    $upc_override = $block['attrs']['mdsUpcOverride'] ?? '';

    $post_id = isset( $block['context']['postId'] ) ? $block['context']['postId'] : get_the_ID();

    if ( ! empty( $upc_override ) ) {
        $args = array( 'post_type' => 'release', 'meta_key' => 'upc_ean', 'meta_value' => preg_replace( '/[^0-9]/', '', $upc_override ), 'post_status'=> 'any', 'numberposts'=> 1 );
        $found = get_posts( $args );
        if ( ! empty( $found ) ) {
            $post_id = $found[0]->ID;
        } else {
            return ''; 
        }
    }
    if ( ! $post_id ) return '';

    // Fetch value
    $val = '';
    if ( strpos( $meta_field, 'stream_' ) === 0 ) {
        $provider = str_replace( 'stream_', '', $meta_field );
        $json = get_post_meta( $post_id, 'streaming_links', true );
        $links = json_decode( $json, true );
        $val = isset( $links[ $provider ] ) ? $links[ $provider ] : '';
    } elseif ( $meta_field === 'featured_image_url' || $meta_field === 'buy_now_link' ) {
        $val = get_post_meta( $post_id, $meta_field, true );
    } elseif ( $meta_field === 'release_format' ) {
        $val = get_post_meta( $post_id, 'release_format', true );
        if ( ! $val ) {
            $terms = wp_get_post_terms( $post_id, 'release_format', array('fields' => 'names') );
            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) $val = $terms[0];
        }
    } elseif ( $meta_field === 'release_tags' ) {
        $val = get_post_meta( $post_id, 'release_tags', true );
        if ( ! $val ) {
            $terms = wp_get_post_terms( $post_id, 'release_tag', array('fields' => 'names') );
            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) $val = implode(', ', $terms);
        }
    } elseif ( $meta_field === 'release_year' ) {
        $date = get_post_field( 'post_date', $post_id );
        $val = ( ! empty( $date ) && $date !== '0000-00-00 00:00:00' ) ? date( 'Y', strtotime( $date ) ) : '';
    } else {
        $val = get_post_meta( $post_id, $meta_field, true ) ?: '';
    }

    if ( empty( $val ) ) return '';

    $block_name = $block['blockName'];
    
    // Robust HTML injection using WP_HTML_Tag_Processor (WP 6.2+)
    if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
        $tags = new WP_HTML_Tag_Processor( $block_content );
        if ( $block_name === 'core/button' ) {
            if ( $tags->next_tag( 'a' ) ) {
                $tags->set_attribute( 'href', esc_url( $val ) );
                $block_content = $tags->get_updated_html();
            }
        } elseif ( $block_name === 'core/image' ) {
            if ( $tags->next_tag( 'img' ) ) {
                $tags->set_attribute( 'src', esc_url( $val ) );
                $tags->remove_attribute( 'srcset' );
                $block_content = $tags->get_updated_html();
            }
        } else {
            // For text blocks (paragraph, heading), regex replacement of inner content is still safest since Tag_Processor doesn't handle innerHTML well yet
            if ( $block_name === 'core/paragraph' ) {
                $block_content = preg_replace( '/(<p[^>]*>)(.*?)(<\/p>)/is', '$1' . esc_html( $val ) . '$3', $block_content );
            } elseif ( $block_name === 'core/heading' ) {
                $block_content = preg_replace( '/(<h[1-6][^>]*>)(.*?)(<\/h[1-6]>)/is', '$1' . esc_html( $val ) . '$3', $block_content );
            }
        }
    }

    return $block_content;
}

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// --- 9. Shortcode Render via Templates ---
add_shortcode( 'release_links', 'mds_render_release_links_shortcode' );
function mds_render_release_links_shortcode( $atts ) {
    $atts = shortcode_atts( array( 'upc' => '' ), $atts );
    if ( empty( $atts['upc'] ) ) return '';
    $upc = preg_replace( '/[^0-9]/', '', $atts['upc'] );

    $args = array(
        'post_type'  => 'release',
        'meta_key'   => 'upc_ean',
        'meta_value' => $upc,
        'post_status'=> 'any',
        'numberposts'=> 1
    );
    $found = get_posts( $args );
    if ( empty( $found ) ) return '';
    
    $post_id = $found[0]->ID;
    $streaming_links_json = get_post_meta( $post_id, 'streaming_links', true );
    $buy_now_link = get_post_meta( $post_id, 'buy_now_link', true );
    $bandcamp_shortcode = get_post_meta( $post_id, 'bandcamp_shortcode', true );
    $audio_preview_url = get_post_meta( $post_id, 'audio_preview_url', true );

    $links_array = json_decode( $streaming_links_json, true );
    if ( ! is_array( $links_array ) ) {
        $links_array = array();
    }

    $global_providers = mds_rm_get_default_providers();

    $icon_bg       = get_option( 'mds_rm_links_icon_bg', 'transparent' );
    $buy_now_icon  = get_option( 'mds_rm_buy_now_icon', 'fas fa-cart-shopping' );
    $buy_now_color = get_option( 'mds_rm_buy_now_color', '#ffffff' );
    $buy_now_bg    = get_option( 'mds_rm_buy_now_bg', '#000000' );

    $icons_html = '';
    foreach ( $links_array as $provider_name => $url ) {
        $icon = 'fas fa-link';
        $bg_color = '';
        
        foreach ( $global_providers as $gp ) {
            if ( strcasecmp( $gp['name'], $provider_name ) === 0 ) {
                $icon = ! empty( $gp['icon'] ) ? $gp['icon'] : 'fas fa-link';
                $bg_color = isset( $gp['color'] ) ? $gp['color'] : '';
                break;
            }
        }
        
        $style_attr = '';
        if ( ! empty( $bg_color ) ) {
            $style_attr = ' style="background-color: ' . esc_attr( $bg_color ) . ' !important;"';
        }
        
        $icons_html .= '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer" class="mds-icon-circle"' . $style_attr . ' title="' . esc_attr( $provider_name ) . '">';
        $icons_html .= '<i class="' . esc_attr( $icon ) . '"></i></a>';
    }

    $buy_now_html = '';
    if ( ! empty( $buy_now_link ) ) {
        $buy_now_html .= '<a href="' . esc_url( $buy_now_link ) . '" target="_blank" rel="noopener noreferrer" class="mds-icon-circle mds-buy-now" style="background-color: ' . esc_attr( $buy_now_bg ) . '; color: ' . esc_attr( $buy_now_color ) . ';" title="Buy Now">';
        $buy_now_html .= '<i class="' . esc_attr( $buy_now_icon ) . '"></i></a>';
    }

    $audio_players = get_option( 'mds_rm_audio_players', array() );
    $audio_player_html = '';
    $bandcamp_used = false;

    if ( in_array( 'blocks_for_bandcamp', (array) $audio_players, true ) && ! empty( $bandcamp_shortcode ) ) {
        $input = trim( $bandcamp_shortcode );
        if ( strpos( strtolower( $input ), '[bandcamp' ) === 0 ) {
            $final_shortcode = $input;
        } else {
            $template = get_option( 'mds_rm_bandcamp_template', '[bandcamp width=100% height=42 album={release_number} size=small bgcol=ffffff linkcol=0687f5 artwork=none]' );
            $album_id = preg_replace( '/[^0-9]/', '', $input );
            $final_shortcode = str_replace( '{release_number}', $album_id, $template );
            $final_shortcode = str_replace( '{album_number}', $album_id, $final_shortcode );
        }
        $audio_player_html .= do_shortcode( sanitize_text_field( $final_shortcode ) );
        $bandcamp_used = true;
    }

    if ( ! $bandcamp_used && in_array( 'native', (array) $audio_players, true ) && ! empty( $audio_preview_url ) ) {
        $audio_player_html .= '<audio controls src="' . esc_url( $audio_preview_url ) . '" style="width: 100%;"></audio>';
    }

    $tokens = array(
        'widget_bg'         => esc_attr( get_option( 'mds_rm_links_bg', 'transparent' ) ),
        'widget_color'      => esc_attr( get_option( 'mds_rm_links_color', '#333333' ) ),
        'widget_gap'        => esc_attr( get_option( 'mds_rm_links_gap', '15px' ) ),
        'widget_align'      => esc_attr( get_option( 'mds_rm_links_align', 'flex-start' ) ),
        'widget_padding'    => esc_attr( get_option( 'mds_rm_links_padding', '0px' ) ),
        'widget_radius'     => esc_attr( get_option( 'mds_rm_links_radius', '0px' ) ),
        'icons_html'        => $icons_html,
        'buy_now_html'      => $buy_now_html,
        'audio_player_html' => $audio_player_html,
    );

    return mds_render_template( 'templates/shortcode-widget.html', $tokens );
}

function mds_render_meta_bar_html( $upc, $include_shortcode = true ) {
    $args = array(
        'post_type'  => 'release',
        'meta_key'   => 'upc_ean',
        'meta_value' => $upc,
        'post_status'=> 'any',
        'numberposts'=> 1
    );
    $found = get_posts( $args );
    if ( empty( $found ) ) return '';
    
    $post_id = $found[0]->ID;
    $date = $found[0]->post_date;
    
    $record_label = get_post_meta( $post_id, 'record_label', true );
    $distributor = get_post_meta( $post_id, 'distributor', true );

    $year = ( ! empty( $date ) && $date !== '0000-00-00 00:00:00' ) ? date( 'Y', strtotime( $date ) ) : '';
    $year_html = $year ? '<span class="mds-meta-item" title="Release Year"><i class="fas fa-calendar"></i> ' . esc_html( $year ) . '</span>' : '';
    $label_html = ! empty( $record_label ) ? '<span class="mds-meta-item" title="Record Label"><i class="fas fa-compact-disc"></i> ' . esc_html( $record_label ) . '</span>' : '';
    $dist_html = ! empty( $distributor ) ? '<span class="mds-meta-item" title="Distributor"><i class="fas fa-tower-broadcast"></i> ' . esc_html( $distributor ) . '</span>' : '';
    $upc_html = ! empty( $upc ) ? '<span class="mds-meta-item" title="UPC/EAN"><i class="fas fa-barcode"></i> ' . esc_html( $upc ) . '</span>' : '';

    $tokens = array(
        'meta_align'       => esc_attr( get_option( 'mds_rm_meta_align', 'flex-start' ) ),
        'meta_bg'          => esc_attr( get_option( 'mds_rm_meta_bg', '#000000' ) ),
        'meta_color'       => esc_attr( get_option( 'mds_rm_meta_color', '#ffffff' ) ),
        'meta_gap'         => esc_attr( get_option( 'mds_rm_meta_gap', '15px' ) ),
        'meta_padding'     => esc_attr( get_option( 'mds_rm_meta_padding', '12px 18px' ) ),
        'meta_radius'      => esc_attr( get_option( 'mds_rm_meta_radius', '0px' ) ),
        'year_html'        => $year_html,
        'label_html'       => $label_html,
        'distributor_html' => $dist_html,
        'upc_html'         => $upc_html,
        'shortcode_widget' => $include_shortcode ? do_shortcode( '[release_links upc="' . esc_attr( $upc ) . '"]' ) : '',
    );

    return mds_render_template( 'templates/woo-meta-widget.html', $tokens );
}

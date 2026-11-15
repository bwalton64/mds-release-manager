<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Inject custom single template if the theme doesn't provide one
add_filter( 'single_template', 'mds_rm_single_template' );
function mds_rm_single_template( $single ) {
    global $post;
    if ( $post->post_type === 'release' ) {
        if ( file_exists( get_stylesheet_directory() . '/single-release.php' ) ) return $single;
        if ( file_exists( get_template_directory() . '/single-release.php' ) ) return $single;
        
        return MDS_RM_PATH . 'templates/single-release.php';
    }
    if ( $post->post_type === 'artist' ) {
        if ( file_exists( get_stylesheet_directory() . '/single-artist.php' ) ) return $single;
        if ( file_exists( get_template_directory() . '/single-artist.php' ) ) return $single;
        
        return MDS_RM_PATH . 'templates/single-artist.php';
    }
    return $single;
}

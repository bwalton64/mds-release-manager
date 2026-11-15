<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Filter Attachment URL to return Hub URL for virtual media
add_filter( 'wp_get_attachment_url', 'mds_rm_virtual_attachment_url', 10, 2 );
function mds_rm_virtual_attachment_url( $url, $post_id ) {
    $hub_url = get_post_meta( $post_id, '_mds_is_hub_media', true );
    if ( ! empty( $hub_url ) ) {
        return $hub_url;
    }
    return $url;
}

// Filter image_downsize to natively spoof the image for Gutenberg, Elementor, and WooCommerce
add_filter( 'image_downsize', 'mds_rm_virtual_image_downsize', 10, 3 );
function mds_rm_virtual_image_downsize( $downsize, $attachment_id, $size ) {
    $hub_url = get_post_meta( $attachment_id, '_mds_is_hub_media', true );
    if ( ! empty( $hub_url ) ) {
        return array( $hub_url, 800, 800, false );
    }
    return $downsize;
}

// Ensure Gutenberg and WP Core REST APIs see the virtual media as a perfectly valid image attachment
add_filter( 'get_post_metadata', 'mds_rm_virtual_media_meta_fallback', 10, 4 );
function mds_rm_virtual_media_meta_fallback( $value, $object_id, $meta_key, $single ) {
    if ( in_array( $meta_key, array( '_wp_attached_file', '_wp_attachment_metadata' ), true ) ) {
        
        remove_filter( 'get_post_metadata', 'mds_rm_virtual_media_meta_fallback', 10 );
        $hub_url = get_post_meta( $object_id, '_mds_is_hub_media', true );
        add_filter( 'get_post_metadata', 'mds_rm_virtual_media_meta_fallback', 10, 4 );

        if ( ! empty( $hub_url ) ) {
            if ( $meta_key === '_wp_attached_file' ) {
                // MUST return a numerically indexed array containing the value. WordPress handles the $single unwrap.
                return array( basename( $hub_url ) );
            }
            if ( $meta_key === '_wp_attachment_metadata' ) {
                $meta = array(
                    'width'  => 800,
                    'height' => 800,
                    'file'   => basename( $hub_url ),
                    'sizes'  => array(),
                );
                return array( $meta );
            }
        }
    }
    return $value;
}

// Helper to reliably get or create a virtual attachment on a spoke site
function mds_rm_get_or_create_virtual_attachment( $hub_image_url, $hub_image_id ) {
    if ( empty( $hub_image_url ) ) return 0;
    
    // Search for existing (using 'any' post_type to catch previously malformed ones)
    $args = array(
        'post_type'  => 'any',
        'post_status'=> 'any',
        'meta_key'   => '_mds_is_hub_media',
        'meta_value' => $hub_image_url,
        'fields'     => 'ids',
        'numberposts'=> 1
    );
    $found = get_posts( $args );
    
    if ( ! empty( $found ) ) {
        $attach_id = $found[0];
        
        // Retroactive fix for previously malformed virtual attachments
        if ( get_post_type( $attach_id ) !== 'attachment' ) {
            wp_update_post( array(
                'ID'        => $attach_id,
                'post_type' => 'attachment',
                'post_status' => 'inherit'
            ) );
        }
        
        if ( ! get_post_meta( $attach_id, '_wp_attached_file', true ) ) {
            update_post_meta( $attach_id, '_wp_attached_file', basename( $hub_image_url ) );
            update_post_meta( $attach_id, '_wp_attachment_metadata', array(
                'width'  => 800,
                'height' => 800,
                'file'   => basename( $hub_image_url ),
                'sizes'  => array(),
            ) );
        }
        return $attach_id;
    }

    $attachment = array(
        'post_type'      => 'attachment',
        'post_mime_type' => 'image/jpeg',
        'post_title'     => 'Hub Media ' . $hub_image_id,
        'post_content'   => '',
        'post_status'    => 'inherit'
    );
    $attach_id = wp_insert_post( $attachment );
    if ( ! is_wp_error( $attach_id ) ) {
        update_post_meta( $attach_id, '_mds_is_hub_media', $hub_image_url );
        update_post_meta( $attach_id, '_wp_attached_file', basename( $hub_image_url ) );
        update_post_meta( $attach_id, '_wp_attachment_metadata', array(
            'width'  => 800,
            'height' => 800,
            'file'   => basename( $hub_image_url ),
            'sizes'  => array(),
        ) );
        return $attach_id;
    }
    return 0;
}


// Force Gutenberg's REST API to accept the virtual media
add_filter( 'rest_prepare_attachment', 'mds_rm_force_rest_virtual_attachment', 10, 3 );
function mds_rm_force_rest_virtual_attachment( $response, $post, $request ) {
    $hub_url = get_post_meta( $post->ID, '_mds_is_hub_media', true );
    if ( ! empty( $hub_url ) ) {
        $data = $response->get_data();
        $data['source_url'] = $hub_url;
        $data['media_details'] = array(
            'width'  => 800,
            'height' => 800,
            'file'   => basename( $hub_url ),
            'sizes'  => array(
                'full' => array(
                    'file'       => basename( $hub_url ),
                    'width'      => 800,
                    'height'     => 800,
                    'mime_type'  => 'image/jpeg',
                    'source_url' => $hub_url,
                )
            ),
        );
        $response->set_data( $data );
    }
    return $response;
}

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// --- 1. Native Logging & Cache Purge ---
function mds_log( $message ) {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
        $prefix = '[MDS Release Manager] ';
        if ( is_array( $message ) || is_object( $message ) ) {
            error_log( $prefix . print_r( $message, true ) );
        } else {
            error_log( $prefix . $message );
        }
    }
}

function mds_rm_purge_cache() {
    if ( defined( 'LSCWP_V' ) || has_action( 'litespeed_purge_all' ) ) {
        do_action( 'litespeed_purge_all' );
    }
}

// --- 2. Database-Backed Template & Asset System ---
function mds_rm_get_editable_files() {
    return array(
        'templates/shortcode-widget.html',
        'templates/woo-meta-widget.html',
        'templates/artist-meta-box.html',
        'templates/release-meta-box.html',
        'templates/settings-page.html',
        'templates/network-settings-page.html',
        'templates/csv-page.html',
        'css/frontend.css',
        'css/admin-styles.css',
        'js/settings.js',
        'js/meta-boxes.js',
    );
}

function mds_rm_get_db_templates() {
    $templates = is_multisite()
        ? get_site_option( 'mds_rm_db_templates', array() )
        : get_option( 'mds_rm_db_templates', array() );
    return is_array( $templates ) ? $templates : array();
}

function mds_rm_update_db_templates( $templates ) {
    if ( is_multisite() ) {
        update_site_option( 'mds_rm_db_templates', $templates );
    } else {
        update_option( 'mds_rm_db_templates', $templates );
    }
}

function mds_render_template( $file_relative_path, $tokens = array() ) {
    $db_templates = mds_rm_get_db_templates();
    if ( isset( $db_templates[ $file_relative_path ] ) && $db_templates[ $file_relative_path ] !== '' ) {
        $content = $db_templates[ $file_relative_path ];
    } else {
        $file_path = MDS_RM_PATH . $file_relative_path;
        if ( ! file_exists( $file_path ) ) {
            return '';
        }
        $content = file_get_contents( $file_path );
    }

    if ( is_array( $tokens ) ) {
        foreach ( $tokens as $key => $val ) {
            $content = str_replace( '{' . $key . '}', (string) $val, $content );
        }
    }
    return $content;
}

function mds_rm_enqueue_editable_style( $handle, $rel_path, $deps = array() ) {
    $db_templates = mds_rm_get_db_templates();
    if ( isset( $db_templates[ $rel_path ] ) && trim( $db_templates[ $rel_path ] ) !== '' ) {
        wp_register_style( $handle, false, $deps, MDS_RELEASE_MANAGER_VERSION );
        wp_enqueue_style( $handle );
        wp_add_inline_style( $handle, $db_templates[ $rel_path ] );
    } else {
        wp_enqueue_style( $handle, MDS_RM_URL . $rel_path, $deps, MDS_RELEASE_MANAGER_VERSION );
    }
}

function mds_rm_enqueue_editable_script( $handle, $rel_path, $deps = array(), $in_footer = true ) {
    $db_templates = mds_rm_get_db_templates();
    if ( isset( $db_templates[ $rel_path ] ) && trim( $db_templates[ $rel_path ] ) !== '' ) {
        wp_register_script( $handle, false, $deps, MDS_RELEASE_MANAGER_VERSION, $in_footer );
        wp_enqueue_script( $handle );
        wp_add_inline_script( $handle, $db_templates[ $rel_path ] );
    } else {
        wp_enqueue_script( $handle, MDS_RM_URL . $rel_path, $deps, MDS_RELEASE_MANAGER_VERSION, $in_footer );
    }
}

// --- Hub & Spoke Synchronization Engine ---

/**
 * Ensures a hierarchical taxonomy term exists on the current blog.
 * Used during CSV import and broadcast sync.
 */

function mds_rm_ensure_term_exists( $term_name, $taxonomy ) {
    if ( empty( $term_name ) ) return null;
    $term = term_exists( $term_name, $taxonomy );
    if ( ! $term ) {
        $term = wp_insert_term( $term_name, $taxonomy );
    }
    if ( is_wp_error( $term ) ) return null;
    return is_array( $term ) ? (int) $term['term_id'] : (int) $term;
}

function mds_rm_broadcast_to_spokes( $post_id, $post_type ) {
    if ( ! is_multisite() ) return;
    
    // Prevent infinite loops if saving happens inside a switched context
    static $is_broadcasting = false;
    if ( $is_broadcasting ) return;
    
    $master_hub_id = (int) get_site_option( 'mds_rm_master_blog_id', get_main_site_id() );
    $current_blog_id = get_current_blog_id();
    
    // Only broadcast if we are saving on the Master Hub
    if ( $current_blog_id !== $master_hub_id ) return;
    
    $post = get_post( $post_id );
    if ( ! $post || ! in_array( $post_type, array( 'release', 'artist' ), true ) ) return;
    
    $spokes = array_map( 'intval', (array) get_site_option( 'mds_rm_woo_sites', array() ) );
    if ( empty( $spokes ) ) return;
    
    $uuid = get_post_meta( $post_id, $post_type === 'artist' ? '_mds_artist_uuid' : 'upc_ean', true );
    if ( empty( $uuid ) ) return;
    
    $meta = get_post_meta( $post_id );
    $taxonomies = get_object_taxonomies( $post_type );
    $terms_data = array();
    foreach ( $taxonomies as $tax ) {
        $terms = wp_get_post_terms( $post_id, $tax, array( 'fields' => 'names' ) );
        $terms_data[$tax] = ! is_wp_error( $terms ) ? $terms : array();
    }
    
    $thumbnail_id = get_post_thumbnail_id( $post_id );
    $hub_img_url = $thumbnail_id ? wp_get_attachment_url( $thumbnail_id ) : '';
    
    $is_broadcasting = true;
    
    foreach ( $spokes as $spoke_id ) {
        if ( $spoke_id === $master_hub_id ) continue;
        
        switch_to_blog( $spoke_id );
        
        // Find existing shadow post by UUID/UPC
        $shadow_post_id = 0;
        $meta_key = $post_type === 'artist' ? '_mds_artist_uuid' : 'upc_ean';
        $args = array(
            'post_type'  => $post_type,
            'meta_key'   => $meta_key,
            'meta_value' => $uuid,
            'post_status'=> 'any',
            'fields'     => 'ids',
            'numberposts'=> 1
        );
        $found = get_posts( $args );
        if ( ! empty( $found ) ) $shadow_post_id = $found[0];
        
        // Prepare core post data
        $post_data = array(
            'post_title'   => $post->post_title,
            'post_content' => $post->post_content,
            'post_status'  => $post->post_status,
            'post_type'    => $post_type,
            'post_name'    => $post->post_name,
            'post_date'    => $post->post_date,
            'post_date_gmt'=> $post->post_date_gmt,
        );
        
        if ( $shadow_post_id ) {
            $post_data['ID'] = $shadow_post_id;
            wp_update_post( $post_data );
        } else {
            $shadow_post_id = wp_insert_post( $post_data );
        }
        
        if ( $shadow_post_id && ! is_wp_error( $shadow_post_id ) ) {
            // Clean old meta
            $existing_meta = get_post_meta( $shadow_post_id );
            foreach ( $existing_meta as $ek => $ev ) {
                delete_post_meta( $shadow_post_id, $ek );
            }
            
            // Sync Meta
            foreach ( $meta as $key => $values ) {
                foreach( $values as $val ) {
                    add_post_meta( $shadow_post_id, $key, $val );
                }
            }
            
            // Sync Thumbnail via Virtual Attachment
            if ( $thumbnail_id ) {
                // pre-fetched outside loop
                $virt_id = mds_rm_get_or_create_virtual_attachment( $hub_img_url, $thumbnail_id );
                if ( $virt_id ) {
                    set_post_thumbnail( $shadow_post_id, $virt_id );
                } else {
                    delete_post_thumbnail( $shadow_post_id );
                }
            } else {
                delete_post_thumbnail( $shadow_post_id );
            }
            
            // Map WooCommerce Product ID locally for faster frontend querying
            $linked_products = get_post_meta( $post_id, '_mds_linked_products', true );
            if ( is_array( $linked_products ) && isset( $linked_products[ $spoke_id ] ) ) {
                update_post_meta( $shadow_post_id, '_mds_local_product_id', $linked_products[ $spoke_id ] );
            } else {
                delete_post_meta( $shadow_post_id, '_mds_local_product_id' );
            }

            
            // Sync Taxonomies securely
            foreach ( $terms_data as $tax => $names ) {
                $is_hierarchical = is_taxonomy_hierarchical( $tax );
                if ( $is_hierarchical ) {
                    $term_ids = array();
                    foreach ( $names as $name ) {
                        $tid = mds_rm_ensure_term_exists( $name, $tax );
                        if ( $tid ) $term_ids[] = $tid;
                    }
                    wp_set_post_terms( $shadow_post_id, $term_ids, $tax, false );
                } else {
                    wp_set_post_terms( $shadow_post_id, $names, $tax, false );
                }
            }
        }
        
        restore_current_blog();
    }
    
    $is_broadcasting = false;
}


function mds_rm_get_default_providers() {
    $providers = get_option( 'mds_rm_providers', false );
    
    // If empty or not set, provide the standard brand defaults
    if ( empty( $providers ) || ! is_array( $providers ) ) {
        $providers = array(
            array( 'name' => 'Spotify', 'icon' => 'fab fa-spotify', 'color' => '#1DB954' ),
            array( 'name' => 'Apple Music', 'icon' => 'fab fa-apple', 'color' => '#FA243C' ),
            array( 'name' => 'Amazon Music', 'icon' => 'fab fa-amazon', 'color' => '#00A8E1' ),
            array( 'name' => 'YouTube Music', 'icon' => 'fab fa-youtube', 'color' => '#FF0000' ),
            array( 'name' => 'Soundcloud', 'icon' => 'fab fa-soundcloud', 'color' => '#FF5500' ),
        );
        // Save it so it populates the settings page
        update_option( 'mds_rm_providers', $providers );
    } else {
        // Upgrade routine: If a user has the providers but their colors got wiped to blank or #000000, restore the brand color
        $brand_colors = array(
            'Spotify' => '#1DB954',
            'Apple Music' => '#FA243C',
            'Amazon Music' => '#00A8E1',
            'YouTube Music' => '#FF0000',
            'Soundcloud' => '#FF5500',
        );
        $updated = false;
        foreach ( $providers as &$p ) {
            if ( isset( $brand_colors[ $p['name'] ] ) && ( empty( $p['color'] ) || $p['color'] === '#000000' ) ) {
                $p['color'] = $brand_colors[ $p['name'] ];
                $updated = true;
            }
        }
        if ( $updated ) {
            update_option( 'mds_rm_providers', $providers );
        }
    }
    
    return $providers;
}

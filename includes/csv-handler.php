<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_init', 'mds_rm_handle_csv_actions' );

function mds_rm_handle_csv_actions() {
    if ( ! isset( $_POST['mds_action'] ) || ! current_user_can( 'manage_options' ) ) {
        return;
    }

    if ( $_POST['mds_action'] === 'export_csv' && wp_verify_nonce( $_POST['mds_csv_nonce'] ?? '', 'mds_csv_action' ) ) {
        $args = array(
            'post_type' => 'release',
            'numberposts' => -1,
            'post_status' => 'any'
        );
        $releases = get_posts($args);
        
        $results = array();
        foreach ($releases as $rel) {
            $artist_id = get_post_meta($rel->ID, 'artist_id', true);
            $artist_name = 'Unknown Artist';
            if ($artist_id) {
                $art_args = array( 'post_type' => 'artist', 'meta_key' => '_mds_artist_uuid', 'meta_value' => $artist_id, 'numberposts' => 1 );
                $arts = get_posts($art_args);
                if (!empty($arts)) $artist_name = $arts[0]->post_title;
            }
            $results[] = array(
                'UPC/EAN' => get_post_meta($rel->ID, 'upc_ean', true),
                'Release Title' => $rel->post_title,
                'Artist' => $artist_name,
                'Release Date' => $rel->post_date,
                'Release Format' => get_post_meta($rel->ID, 'release_format', true),
                'Record Label' => get_post_meta($rel->ID, 'record_label', true),
                'Distributor' => get_post_meta($rel->ID, 'distributor', true),
                'Buy Now Link' => get_post_meta($rel->ID, 'buy_now_link', true),
                'Bandcamp Shortcode' => get_post_meta($rel->ID, 'bandcamp_shortcode', true),
                'Tags' => get_post_meta($rel->ID, 'release_tags', true),
                'Featured Image URL' => get_post_meta($rel->ID, 'featured_image_url', true),
                'Audio Preview URL' => get_post_meta($rel->ID, 'audio_preview_url', true),
                'About' => $rel->post_content,
                'Streaming Links' => get_post_meta($rel->ID, 'streaming_links', true)
            );
        }
        
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="mds-releases-export.csv"' );
        $out = fopen( 'php://output', 'w' );
        if ( ! empty( $results ) ) {
            fputcsv( $out, array_keys( $results[0] ) );
            foreach ( $results as $row ) {
                fputcsv( $out, $row );
            }
        } else {
            fputcsv( $out, array( 'No data found in WordPress posts.' ) );
        }
        fclose( $out );
        exit;
    }

    if ( $_POST['mds_action'] === 'import_csv' && wp_verify_nonce( $_POST['mds_csv_nonce'] ?? '', 'mds_csv_action' ) ) {
        if ( ! empty( $_FILES['mds_csv_file']['tmp_name'] ) ) {
            $file = fopen( $_FILES['mds_csv_file']['tmp_name'], 'r' );
            if ( ! $file ) {
                return;
            }

            // Check for UTF-8 BOM and strip it to prevent header matching errors
            $bom = fread( $file, 3 );
            if ( $bom !== "\xEF\xBB\xBF" ) {
                rewind( $file );
            }

            $headers  = null;
            $imported = 0;
            $updated  = 0;

            global $wpdb;
            $rel_table = $wpdb->base_prefix . 'mds_release_manager';
            $art_table = $wpdb->base_prefix . 'mds_artist_manager';
            $current_blog_id = get_current_blog_id();

            // Build dynamic list of streaming provider names (configured + legacy defaults)
            $provider_names = array( 'Spotify', 'Apple Music', 'Amazon Music', 'YouTube Music', 'Soundcloud' );
            $configured_providers = get_option( 'mds_rm_providers', array() );
            if ( is_array( $configured_providers ) ) {
                foreach ( $configured_providers as $cp ) {
                    if ( ! empty( $cp['name'] ) && ! in_array( $cp['name'], $provider_names, true ) ) {
                        $provider_names[] = $cp['name'];
                    }
                }
            }

            while ( ( $row = fgetcsv( $file ) ) !== false ) {
                // Locate header row containing 'UPC/EAN'
                if ( ! $headers ) {
                    $trimmed_row = array_map( 'trim', $row );
                    if ( in_array( 'UPC/EAN', $trimmed_row, true ) ) {
                        $headers = $trimmed_row;
                    }
                    continue;
                }

                if ( count( $headers ) !== count( $row ) ) {
                    continue;
                }
                $data = array_combine( $headers, $row );

                $artist_name  = sanitize_text_field( $data['Artist'] ?? 'Unknown Artist' );
                $upc_raw      = sanitize_text_field( $data['UPC/EAN'] ?? '' );
                $upc          = preg_replace( '/[^0-9]/', '', $upc_raw );
                $title        = sanitize_text_field( $data['Release Title'] ?? 'Untitled' );
                $release_date = sanitize_text_field( $data['Release Date'] ?? '' );
                $format       = sanitize_text_field( $data['Release Format'] ?? '' );
                $label        = sanitize_text_field( $data['Record Label'] ?? '' );
                $distributor  = sanitize_text_field( $data['Distributor'] ?? '' );
                $buy_link     = esc_url_raw( $data['Buy Now Link'] ?? '' );
                $bandcamp     = sanitize_text_field( $data['Bandcamp Shortcode'] ?? '' );
                $tags_raw     = sanitize_text_field( $data['Tags'] ?? '' );
                $featured_img = isset( $data['Featured Image URL'] ) ? esc_url_raw( $data['Featured Image URL'] ) : null;
                $audio_url    = isset( $data['Audio Preview URL'] ) ? esc_url_raw( $data['Audio Preview URL'] ) : null;
                $about_raw    = isset( $data['About'] ) ? wp_kses_post( $data['About'] ) : null;

                // Skip empty trailing rows
                if ( empty( $title ) && empty( $upc ) ) {
                    continue;
                }

                // 1. Resolve Artist CPT natively
                $artist_post_id = 0;
                $artist_uuid = '';
                $a_args = array( 'post_type' => 'artist', 'title' => $artist_name, 'post_status' => 'any', 'numberposts' => 1 );
                $a_found = get_posts($a_args);
                if ( ! empty( $a_found ) ) {
                    $artist_post_id = $a_found[0]->ID;
                    $artist_uuid = get_post_meta($artist_post_id, '_mds_artist_uuid', true);
                } else {
                    $artist_post_id = wp_insert_post( array(
                        'post_title'  => $artist_name,
                        'post_name'   => sanitize_title( $artist_name ),
                        'post_type'   => 'artist',
                        'post_status' => 'publish',
                    ) );
                    $artist_uuid = wp_generate_uuid4();
                    update_post_meta( $artist_post_id, '_mds_artist_uuid', $artist_uuid );
                }

                static $broadcasted_artists = array();
                if ( ! empty( $artist_post_id ) && ! isset( $broadcasted_artists[ $artist_post_id ] ) ) {
                    mds_rm_broadcast_to_spokes( $artist_post_id, 'artist' );
                    $broadcasted_artists[ $artist_post_id ] = true;
                }

                // 2. Collect Streaming Provider Links
                $streaming_providers = array();
                $streaming_urls      = array();
                foreach ( $provider_names as $p_name ) {
                    $col_key = $p_name . ' Link';
                    if ( ! empty( $data[ $col_key ] ) ) {
                        $clean_url = esc_url_raw( trim( $data[ $col_key ] ) );
                        if ( ! empty( $clean_url ) ) {
                            $streaming_providers[] = $p_name;
                            $streaming_urls[]      = $clean_url;
                        }
                    }
                }
                $streaming_json = wp_json_encode( array_combine( $streaming_providers, $streaming_urls ) ?: array() );

                // 3. Upsert Release CPT natively by UPC
                $rel_post_id = 0;
                $is_update = false;
                if ( ! empty( $upc ) ) {
                    $r_args = array( 'post_type' => 'release', 'meta_key' => 'upc_ean', 'meta_value' => $upc, 'post_status' => 'any', 'numberposts' => 1 );
                    $r_found = get_posts($r_args);
                    if ( ! empty($r_found) ) {
                        $rel_post_id = $r_found[0]->ID;
                        $is_update = true;
                    }
                }

                $post_args = array(
                    'post_title'   => $title,
                    'post_content' => $about_raw ?? '',
                    'post_type'    => 'release',
                    'post_status'  => 'publish',
                );
                if ( $release_date ) {
                    $date = date( 'Y-m-d H:i:s', strtotime( $release_date ) );
                    $post_args['post_date']     = $date;
                    $post_args['post_date_gmt'] = get_gmt_from_date( $date );
                }

                if ( $is_update ) {
                    $post_args['ID'] = $rel_post_id;
                    wp_update_post( $post_args );
                } else {
                    $rel_post_id = wp_insert_post( $post_args );
                }

                // Preserve fields
                if ( $featured_img !== null ) update_post_meta( $rel_post_id, 'featured_image_url', $featured_img );
                if ( $audio_url !== null ) update_post_meta( $rel_post_id, 'audio_preview_url', $audio_url );

                // 4. Bind Meta & Taxonomies
                update_post_meta( $rel_post_id, 'upc_ean', $upc );
                update_post_meta( $rel_post_id, 'artist_id', $artist_uuid );
                update_post_meta( $rel_post_id, 'release_format', $format );
                update_post_meta( $rel_post_id, 'record_label', $label );
                update_post_meta( $rel_post_id, 'distributor', $distributor );
                update_post_meta( $rel_post_id, 'buy_now_link', $buy_link );
                update_post_meta( $rel_post_id, 'bandcamp_shortcode', $bandcamp );
                update_post_meta( $rel_post_id, 'streaming_links', wp_slash( $streaming_json ) );

                if ( $format ) {
                    $tid = mds_rm_ensure_term_exists( $format, 'release_format' );
                    if ( $tid ) wp_set_post_terms( $rel_post_id, array( $tid ), 'release_format', false );
                } else {
                    wp_set_post_terms( $rel_post_id, array(), 'release_format', false );
                }

                if ( $tags_raw ) {
                    $tag_array = array_filter( array_map( 'trim', explode( ',', $tags_raw ) ) );
                    wp_set_post_terms( $rel_post_id, $tag_array, 'release_tag', false );
                    update_post_meta( $rel_post_id, 'release_tags', implode(', ', $tag_array) );
                } else {
                    wp_set_post_terms( $rel_post_id, array(), 'release_tag', false );
                }

                mds_rm_broadcast_to_spokes( $rel_post_id, 'release' );

                if ( $is_update ) {
                    $updated++;
                } else {
                    $imported++;
                }
            }
            fclose( $file );
            mds_rm_purge_cache();
            add_settings_error(
                'mds_csv_msg',
                'mds_csv_msg',
                "CSV import complete: $imported created, $updated updated.",
                'success'
            );
        }
    }
}

function mds_rm_csv_page() {
    settings_errors( 'mds_csv_msg' );

    $tokens = array(
        'admin_url'   => admin_url( 'edit.php?post_type=release&page=mds-release-csv' ),
        'nonce_field' => wp_nonce_field( 'mds_csv_action', 'mds_csv_nonce', true, false ),
    );

    echo mds_render_template( 'templates/csv-page.html', $tokens );
}

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_init', 'mds_rm_handle_csv_actions' );

function mds_rm_handle_csv_actions() {
    if ( ! isset( $_POST['mds_action'] ) || ! current_user_can('manage_options') ) return;

    if ( $_POST['mds_action'] === 'export_csv' && wp_verify_nonce( $_POST['mds_csv_nonce'], 'mds_csv_action' ) ) {
        global $wpdb;
        $rel_table = $wpdb->base_prefix . 'mds_release_manager';
        $art_table = $wpdb->base_prefix . 'mds_artist_manager';
        
        $results = $wpdb->get_results( "
            SELECT r.*, a.artist_name 
            FROM $rel_table r 
            LEFT JOIN $art_table a ON r.artist_id = a.id
        ", ARRAY_A );

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="mds-releases-export.csv"');
        $out = fopen('php://output', 'w');
        
        if ( ! empty($results) ) {
            fputcsv($out, array_keys($results[0]));
            foreach ( $results as $row ) {
                fputcsv($out, $row);
            }
        } else {
            fputcsv($out, ['No data found in global database.']);
        }
        fclose($out);
        exit;
    }

    if ( $_POST['mds_action'] === 'import_csv' && wp_verify_nonce( $_POST['mds_csv_nonce'], 'mds_csv_action' ) ) {
        if ( ! empty($_FILES['mds_csv_file']['tmp_name']) ) {
            $file = fopen($_FILES['mds_csv_file']['tmp_name'], 'r');
            // Check for BOM and strip it to prevent header matching errors
            $bom = fread($file, 3);
            if ($bom != b"\xEF\xBB\xBF") {
                rewind($file);
            }
            
            $headers = null;
            $imported = 0;
            
            global $wpdb;
            $art_table = $wpdb->base_prefix . 'mds_artist_manager';

            while (($row = fgetcsv($file)) !== FALSE) {
                // If headers not set, look for the row that has 'UPC/EAN'
                if ( ! $headers ) {
                    if ( in_array('UPC/EAN', $row) ) {
                        $headers = $row;
                    }
                    continue;
                }
                
                if (count($headers) !== count($row)) continue;
                $data = array_combine($headers, $row);
                
                // Map fields according to latest structure requirement
                $artist_name = sanitize_text_field( $data['Artist'] ?? 'Unknown Artist' );
                $upc         = sanitize_text_field( $data['UPC/EAN'] ?? '' );
                $title       = sanitize_text_field( $data['Release Title'] ?? 'Untitled' );
                $release_date= sanitize_text_field( $data['Release Date'] ?? '' );
                $format      = sanitize_text_field( $data['Release Format'] ?? '' );
                $label       = sanitize_text_field( $data['Record Label'] ?? '' );
                $distributor = sanitize_text_field( $data['Distributor'] ?? '' );
                $buy_link    = esc_url_raw( $data['Buy Now Link'] ?? '' );
                $bandcamp    = sanitize_text_field( $data['Bandcamp Shortcode'] ?? '' );
                $tags_raw    = sanitize_text_field( $data['Tags'] ?? '' );

                // Skip if title and UPC are totally blank (empty spreadsheet trailing rows)
                if ( empty($title) && empty($upc) ) continue;

                // 1. Resolve Artist CPT & Global DB entry
                $artist_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $art_table WHERE artist_name = %s", $artist_name));
                if ( ! $artist_id ) {
                    $artist_post_id = wp_insert_post([
                        'post_title'  => $artist_name,
                        'post_name'   => sanitize_title($artist_name), 
                        'post_type'   => 'artist', 
                        'post_status' => 'publish'
                    ]);
                    $artist_id = wp_generate_uuid4();
                    update_post_meta($artist_post_id, '_mds_artist_uuid', $artist_id);
                    
                    // Force the REST API internal request to generate global artist table row
                    $req = new WP_REST_Request( 'POST', '/mds/v1/artists/post/' . $artist_post_id );
                    $req->set_param( 'blog_id', get_current_blog_id() );
                    rest_do_request( $req );
                }

                // 2. Format Streaming Arrays for the REST controller
                $streaming_providers = [];
                $streaming_urls = [];
                
                if ( !empty($data['Spotify Link']) ) {
                    $streaming_providers[] = 'Spotify';
                    $streaming_urls[] = esc_url_raw($data['Spotify Link']);
                }
                if ( !empty($data['Apple Music Link']) ) {
                    $streaming_providers[] = 'Apple Music';
                    $streaming_urls[] = esc_url_raw($data['Apple Music Link']);
                }
                if ( !empty($data['Amazon Music Link']) ) {
                    $streaming_providers[] = 'Amazon Music';
                    $streaming_urls[] = esc_url_raw($data['Amazon Music Link']);
                }
                if ( !empty($data['YouTube Music Link']) ) {
                    $streaming_providers[] = 'YouTube Music';
                    $streaming_urls[] = esc_url_raw($data['YouTube Music Link']);
                }
                if ( !empty($data['Soundcloud Link']) ) {
                    $streaming_providers[] = 'Soundcloud';
                    $streaming_urls[] = esc_url_raw($data['Soundcloud Link']);
                }
                
                // Build the legacy local CPT format too just to keep data identical
                $streaming_json = wp_json_encode(array_combine($streaming_providers, $streaming_urls) ?: []);

                // 3. Insert Release CPT
                $rel_post_id = wp_insert_post([
                    'post_title'  => $title, 
                    'post_type'   => 'release', 
                    'post_status' => 'publish'
                ]);
                
                if ( $release_date ) {
                    $date = date('Y-m-d H:i:s', strtotime($release_date));
                    wp_update_post([ 'ID' => $rel_post_id, 'post_date' => $date, 'post_date_gmt' => get_gmt_from_date( $date ) ]);
                }

                // 4. Bind Meta Data to the new CPT
                update_post_meta($rel_post_id, 'upc_ean', $upc);
                update_post_meta($rel_post_id, 'artist_id', $artist_id);
                update_post_meta($rel_post_id, 'release_format', $format);
                update_post_meta($rel_post_id, 'record_label', $label);
                update_post_meta($rel_post_id, 'distributor', $distributor);
                update_post_meta($rel_post_id, 'buy_now_link', $buy_link);
                update_post_meta($rel_post_id, 'bandcamp_shortcode', $bandcamp);
                
                // Requires wp_slash because wp_update_post/update_post_meta unslashes json arrays internally
                update_post_meta($rel_post_id, 'streaming_links', wp_slash($streaming_json));

                if ( $format ) {
                    wp_set_post_terms($rel_post_id, $format, 'release_format', false);
                }
                
                if ( $tags_raw ) {
                    $tag_array = array_map( 'trim', explode( ',', $tags_raw ) );
                    wp_set_post_terms($rel_post_id, $tag_array, 'release_tag', false);
                }

                // 5. Sync to Global Table via REST API internal mock request
                $req = new WP_REST_Request( 'POST', '/mds/v1/releases/post/' . $rel_post_id );
                
                $meta = get_post_meta( $rel_post_id );
                $params = array();
                foreach($meta as $k => $v) { $params[$k] = $v[0]; }
                
                // Append explicit params
                $params['release_tags'] = $tags_raw;
                $params['mds_streaming_provider'] = $streaming_providers;
                $params['mds_streaming_url'] = $streaming_urls;
                
                $req->set_body_params( $params );
                $req->set_param( 'blog_id', get_current_blog_id() );
                rest_do_request( $req );

                $imported++;
            }
            fclose($file);
            mds_rm_purge_cache();
            add_settings_error( 'mds_csv_msg', 'mds_csv_msg', "Successfully imported $imported records.", 'success' );
        }
    }
}

function mds_rm_csv_page() {
    settings_errors( 'mds_csv_msg' );
    
    $tokens = array(
        'admin_url' => admin_url('edit.php?post_type=release&page=mds-release-csv'),
        'nonce_field' => wp_nonce_field('mds_csv_action', 'mds_csv_nonce', true, false)
    );
    
    echo mds_render_template( 'templates/csv-page.html', $tokens );
}

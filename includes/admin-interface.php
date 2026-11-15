<?php
/**
 * Admin Interface Functions
 *
 * @package MDS_Release_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register Custom Columns
 */
add_filter( 'manage_release_posts_columns', 'mds_rm_add_shortcode_column' );
add_filter( 'manage_release_posts_columns', 'mds_rm_add_featured_image_column', 10 );
add_filter( 'manage_artist_posts_columns', 'mds_rm_add_featured_image_column', 10 );

/**
 * Add Shortcode Column
 *
 * @param array $columns Existing columns.
 * @return array
 */
function mds_rm_add_shortcode_column( $columns ) {
    $new_columns = array();
    foreach ( $columns as $key => $title ) {
        if ( 'title' === $key ) {
            $new_columns['mds_shortcode'] = 'Shortcode';
        }
        $new_columns[ $key ] = $title;
    }
    return $new_columns;
}

/**
 * Add Image Column
 *
 * @param array $columns Existing columns.
 * @return array
 */
function mds_rm_add_featured_image_column( $columns ) {
    $new_columns = array();
    foreach ( $columns as $key => $title ) {
        if ( 'title' === $key ) {
            $new_columns['mds_thumb'] = 'Image';
        }
        $new_columns[ $key ] = $title;
    }
    return $new_columns;
}

/**
 * Render Custom Columns
 */
add_action( 'manage_release_posts_custom_column', 'mds_rm_render_featured_image_column', 5, 2 );
add_action( 'manage_artist_posts_custom_column', 'mds_rm_render_featured_image_column', 5, 2 );
add_action( 'manage_release_posts_custom_column', 'mds_rm_render_shortcode_column', 10, 2 );

/**
 * Render Image Column
 *
 * @param string $column  Column name.
 * @param int    $post_id Post ID.
 */
function mds_rm_render_featured_image_column( $column, $post_id ) {
    if ( 'mds_thumb' === $column ) {
        echo get_the_post_thumbnail( $post_id, array( 50, 50 ) );
    }
}

/**
 * Render Shortcode Column
 *
 * @param string $column  Column name.
 * @param int    $post_id Post ID.
 */
function mds_rm_render_shortcode_column( $column, $post_id ) {
    if ( 'mds_shortcode' === $column ) {
        $upc = get_post_meta( $post_id, 'upc_ean', true );
        if ( ! empty( $upc ) ) {
            $shortcode_text = '[release_links upc="' . esc_attr( $upc ) . '"]';
            echo '<code class="mds-shortcode-copy">' . esc_html( $shortcode_text ) . '</code>';
        } else {
            echo '&mdash;';
        }
    }
}

/**
 * Inject List Table Assets
 */
add_action( 'admin_head', 'mds_rm_list_table_assets' );
function mds_rm_list_table_assets() {
    $screen = get_current_screen();
    if ( ! $screen || ! in_array( $screen->id, array( 'edit-release', 'edit-artist' ), true ) ) {
        return;
    }
    ?>
    <style>
        .column-mds_thumb { width: 60px !important; text-align: center; }
        .column-mds_thumb img { width: 50px; height: 50px; object-fit: cover; border-radius: 4px; border: 1px solid #ddd; }
        .mds-shortcode-copy { cursor: pointer; background: #e0f0fa; padding: 4px 8px; border-radius: 4px; border: 1px solid #007cba; color: #007cba; display: inline-block; font-size: 12px; transition: all 0.2s; }
        .mds-shortcode-copy:hover { background: #007cba; color: #fff; }
    </style>
    <script>
    jQuery(document).ready(function($) {
        $('.mds-shortcode-copy').on('click', function(e) {
            e.preventDefault();
            var shortcode = $(this).text();
            var $el = $(this);
            navigator.clipboard.writeText(shortcode).then(function() {
                var orig = $el.text();
                $el.text('Copied!');
                setTimeout(function() { $el.text(orig); }, 2000);
            });
        });
    });
    </script>
    <?php
}

/**
 * Register Meta Boxes
 */
add_action( 'add_meta_boxes', 'mds_add_meta_boxes', 10, 2 );
function mds_add_meta_boxes( $post_type, $post ) {
    add_meta_box( 'mds_release_settings', 'Release Settings', 'mds_render_release_settings_box', 'release', 'normal', 'high' );
    add_meta_box( 'mds_artist_settings', 'Artist Settings', 'mds_render_artist_settings_box', 'artist', 'normal', 'high' );
    
    /**
     * Hook: mds_rm_register_custom_meta_boxes
     * Allows feature plugins to register custom meta boxes to the CPTs.
     */
    do_action( 'mds_rm_register_custom_meta_boxes', $post );
}

/**
 * Render Artist Meta Box
 *
 * @param WP_Post $post The post object.
 */
function mds_render_artist_settings_box( $post ) {
    $contact = get_post_meta( $post->ID, 'artist_contact', true );

    ob_start();
    wp_editor( wp_kses_post( $contact ?: '' ), 'artist_contact', array(
        'media_buttons' => false,
        'textarea_rows' => 5,
    ) );
    $editor_html = ob_get_clean();

    $tokens = array(
        'nonce_field' => wp_nonce_field( 'mds_save_artist_data', 'mds_artist_meta_nonce', true, false ),
        'editor_html' => $editor_html,
    );
    echo mds_render_template( 'templates/artist-meta-box.html', $tokens ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Render Release Meta Box
 *
 * @param WP_Post $post The post object.
 */
function mds_render_release_settings_box( $post ) {
    $artist_id = get_post_meta( $post->ID, 'artist_id', true ) ?: '';
    
    $artists = get_posts( array(
        'post_type'   => 'artist',
        'numberposts' => -1,
        'post_status' => 'any',
        'orderby'     => 'title',
        'order'       => 'ASC',
    ) );

    $artist_options = '<option value="">-- Select Artist --</option>';
    foreach ( $artists as $artist ) {
        $a_uuid = get_post_meta( $artist->ID, '_mds_artist_uuid', true );
        if ( $a_uuid ) {
            $artist_options .= sprintf(
                '<option value="%s" %s>%s</option>',
                esc_attr( $a_uuid ),
                selected( $artist_id, $a_uuid, false ),
                esc_html( $artist->post_title )
            );
        }
    }
    if ( empty( $artists ) ) {
        $artist_options = '<option value="">No Artists Found. Please Create One.</option>';
    }

    $global_providers = get_option( 'mds_rm_providers', array() );
    $global_providers_opts = '<option value="">Select Provider</option>';
    if ( is_array( $global_providers ) ) {
        foreach ( $global_providers as $gp ) {
            if ( isset( $gp['name'] ) ) {
                $global_providers_opts .= sprintf(
                    '<option value="%s">%s</option>',
                    esc_attr( $gp['name'] ),
                    esc_html( $gp['name'] )
                );
            }
        }
    }

    $streaming_links_json = get_post_meta( $post->ID, 'streaming_links', true ) ?: '[]';
    $streaming_links = json_decode( $streaming_links_json, true );
    if ( ! is_array( $streaming_links ) ) {
        $streaming_links = array();
    }

    $streaming_rows_html = '';
    foreach ( $streaming_links as $provider => $url ) {
        $opts = '<option value="">Select Provider</option>';
        $matched = false;
        
        if ( is_array( $global_providers ) ) {
            foreach ( $global_providers as $gp ) {
                if ( isset( $gp['name'] ) ) {
                    $is_selected = selected( $provider, $gp['name'], false );
                    if ( $is_selected ) {
                        $matched = true;
                    }
                    $opts .= sprintf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr( $gp['name'] ),
                        $is_selected,
                        esc_html( $gp['name'] )
                    );
                }
            }
        }
        
        if ( ! $matched && ! empty( $provider ) ) {
            $opts .= sprintf(
                '<option value="%s" selected="selected">%s</option>',
                esc_attr( $provider ),
                esc_html( $provider )
            );
        }

        $streaming_rows_html .= '<div class="mds-streaming-row">';
        $streaming_rows_html .= '<select name="mds_streaming_provider[]">' . $opts . '</select>';
        $streaming_rows_html .= '<input type="url" name="mds_streaming_url[]" value="' . esc_url( $url ) . '" placeholder="https://..." />';
        $streaming_rows_html .= '<button type="button" class="button mds-remove-row">Remove</button>';
        $streaming_rows_html .= '</div>';
    }

    $tokens = array(
        'nonce_field'              => wp_nonce_field( 'mds_save_release_data', 'mds_release_meta_nonce', true, false ),
        'artists_options_html'     => $artist_options,
        'upc_ean'                  => esc_attr( get_post_meta( $post->ID, 'upc_ean', true ) ?: '' ),
        'record_label'             => esc_attr( get_post_meta( $post->ID, 'record_label', true ) ?: '' ),
        'distributor'              => esc_attr( get_post_meta( $post->ID, 'distributor', true ) ?: '' ),
        'streaming_rows_html'      => $streaming_rows_html,
        'global_providers_options' => $global_providers_opts,
        'buy_now_link'             => esc_url( get_post_meta( $post->ID, 'buy_now_link', true ) ?: '' ),
        'audio_preview_url'        => esc_url( get_post_meta( $post->ID, 'audio_preview_url', true ) ?: '' ),
    );

    echo mds_render_template( 'templates/release-meta-box.html', $tokens ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Save Artist Meta Data
 */
add_action( 'save_post_artist', 'mds_save_artist_meta_data' );
function mds_save_artist_meta_data( $post_id ) {
    if ( is_multisite() && get_current_blog_id() !== (int) get_site_option( 'mds_rm_master_blog_id', get_main_site_id() ) ) {
        return;
    }

    if ( ! isset( $_POST['mds_artist_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['mds_artist_meta_nonce'] ), 'mds_save_artist_data' ) ) {
        return;
    }

    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    $uuid = get_post_meta( $post_id, '_mds_artist_uuid', true );
    if ( empty( $uuid ) ) {
        $uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : wp_generate_password( 36, false );
        update_post_meta( $post_id, '_mds_artist_uuid', $uuid );
    }

    if ( isset( $_POST['artist_contact'] ) ) {
        update_post_meta( $post_id, 'artist_contact', wp_kses_post( wp_unslash( $_POST['artist_contact'] ) ) );
    }
    
    mds_rm_broadcast_to_spokes( $post_id, 'artist' );
    
    /**
     * Hook: mds_rm_save_custom_meta
     * Fires after core artist meta is saved. 
     */
    do_action( 'mds_rm_save_custom_meta', $post_id, $_POST );
    
    mds_rm_purge_cache();
}

/**
 * Save Release Meta Data
 */
add_action( 'save_post_release', 'mds_save_release_meta_data' );
function mds_save_release_meta_data( $post_id ) {
    if ( is_multisite() && get_current_blog_id() !== (int) get_site_option( 'mds_rm_master_blog_id', get_main_site_id() ) ) {
        return;
    }

    if ( ! isset( $_POST['mds_release_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['mds_release_meta_nonce'] ), 'mds_save_release_data' ) ) {
        return;
    }

    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    if ( isset( $_POST['upc_ean'] ) ) {
        $upc_clean = preg_replace( '/[^0-9]/', '', sanitize_text_field( wp_unslash( $_POST['upc_ean'] ) ) );
        update_post_meta( $post_id, 'upc_ean', $upc_clean );
    }

    if ( isset( $_POST['artist_id'] ) ) {
        update_post_meta( $post_id, 'artist_id', sanitize_text_field( wp_unslash( $_POST['artist_id'] ) ) );
    }

    if ( isset( $_POST['record_label'] ) ) {
        update_post_meta( $post_id, 'record_label', sanitize_text_field( wp_unslash( $_POST['record_label'] ) ) );
    }

    if ( isset( $_POST['distributor'] ) ) {
        update_post_meta( $post_id, 'distributor', sanitize_text_field( wp_unslash( $_POST['distributor'] ) ) );
    }

    if ( isset( $_POST['buy_now_link'] ) ) {
        update_post_meta( $post_id, 'buy_now_link', esc_url_raw( wp_unslash( $_POST['buy_now_link'] ) ) );
    }

    if ( isset( $_POST['audio_preview_url'] ) ) {
        update_post_meta( $post_id, 'audio_preview_url', esc_url_raw( wp_unslash( $_POST['audio_preview_url'] ) ) );
    }

    $providers = isset( $_POST['mds_streaming_provider'] ) ? (array) $_POST['mds_streaming_provider'] : array();
    $urls      = isset( $_POST['mds_streaming_url'] ) ? (array) $_POST['mds_streaming_url'] : array();
    $streaming_links_arr = array();
    
    $count = count( $providers );
    for ( $i = 0; $i < $count; $i++ ) {
        $p = sanitize_text_field( wp_unslash( $providers[ $i ] ?? '' ) );
        $u = esc_url_raw( wp_unslash( $urls[ $i ] ?? '' ) );
        if ( ! empty( $p ) && ! empty( $u ) ) {
            $streaming_links_arr[ $p ] = $u;
        }
    }
    update_post_meta( $post_id, 'streaming_links', wp_slash( wp_json_encode( $streaming_links_arr ) ) );

    mds_rm_broadcast_to_spokes( $post_id, 'release' );
    
    /**
     * Hook: mds_rm_save_custom_meta
     * Fires after core release meta is saved. 
     */
    do_action( 'mds_rm_save_custom_meta', $post_id, $_POST );
    
    mds_rm_purge_cache();
}

/**
 * Register Settings Page
 */
add_action( 'admin_menu', 'mds_rm_register_settings_page' );
function mds_rm_register_settings_page() {
    add_submenu_page(
        'edit.php?post_type=release',
        'Release Settings',
        'Settings',
        'manage_options',
        'mds-release-settings',
        'mds_rm_settings_page'
    );
}

/**
 * Render Settings Page
 */
function mds_rm_settings_page() {
    $global_providers = get_option( 'mds_rm_providers', array() );
    
    $providers_html = '';
    if ( is_array( $global_providers ) ) {
        foreach ( $global_providers as $gp ) {
            if ( isset( $gp['name'] ) && isset( $gp['icon'] ) && isset( $gp['color'] ) ) {
                $providers_html .= '<div class="mds-provider-row" style="margin-bottom: 10px;">';
                $providers_html .= sprintf(
                    '<input type="text" name="mds_rm_providers[name][]" value="%s" placeholder="Provider Name" style="margin-right:10px;" />',
                    esc_attr( $gp['name'] )
                );
                $providers_html .= '<div class="mds-icon-input-wrapper" style="display:inline-block; position:relative; margin-right:10px;">';
                $providers_html .= sprintf(
                    '<input type="text" name="mds_rm_providers[icon][]" value="%s" placeholder="fab fa-spotify" class="mds-icon-input" />',
                    esc_attr( $gp['icon'] )
                );
                $providers_html .= '</div>';
                $providers_html .= sprintf(
                    '<input type="text" name="mds_rm_providers[color][]" value="%s" class="mds-color-picker" />',
                    esc_attr( $gp['color'] )
                );
                $providers_html .= '<button type="button" class="button mds-remove-provider" style="margin-left:10px;">Remove</button>';
                $providers_html .= '</div>';
            }
        }
    }

    $audio_players = array(
        'html5' => 'HTML5 Player (.mp3)'
    );
    
    $active_players = get_option( 'mds_rm_audio_players', array( 'html5' ) );
    
    $audio_players_html = '';
    foreach ( $audio_players as $slug => $label ) {
        $checked = in_array( $slug, $active_players, true ) ? 'checked="checked"' : '';
        $audio_players_html .= '<label style="display:block; margin-bottom:5px;">';
        $audio_players_html .= sprintf(
            '<input type="checkbox" name="mds_rm_audio_players[]" value="%s" %s /> %s',
            esc_attr( $slug ),
            $checked,
            esc_html( $label )
        );
        $audio_players_html .= '</label>';
    }

    $fa_sources = array(
        'cdn'    => 'Load Free Font-Awesome 6 via CDN',
        'plugin' => 'Use existing plugin (Font Awesome Official)',
        'theme'  => 'Use theme provided Font-Awesome',
        'none'   => 'Do not load Font-Awesome'
    );
    $active_fa = get_option( 'mds_rm_fa_support', 'cdn' );
    $fa_support_html = '<select name="mds_rm_fa_support" style="min-width: 250px;">';
    foreach ( $fa_sources as $val => $label ) {
        $fa_support_html .= sprintf(
            '<option value="%s" %s>%s</option>',
            esc_attr( $val ),
            selected( $active_fa, $val, false ),
            esc_html( $label )
        );
    }
    $fa_support_html .= '</select>';

    /**
     * Hook: mds_rm_settings_audio_fields
     * Allows feature plugins to inject settings rows into the Audio Preview section.
     */
    $audio_player_custom_fields = apply_filters( 'mds_rm_settings_audio_fields', '' );

    $tokens = array(
        'providers_html'             => $providers_html,
        'global_redirect_checked'    => checked( get_option( 'mds_rm_global_redirect', '0' ), '1', false ),
        'fa_support_html'            => $fa_support_html,
        'audio_players_html'         => $audio_players_html,
        'audio_player_custom_fields' => $audio_player_custom_fields,
    );

    echo mds_render_template( 'templates/settings-page.html', $tokens ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

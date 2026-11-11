<?php
/**
 * Plugin Name: MDS Release Manager
 * Description: Manages the "Releases" and "Artist" CPTs, uses global custom tables as the source of truth, exposes CRUD REST APIs, integrates with WooCommerce, and provides frontend widgets via shortcodes and Elementor.
 * Version: 0.6.8
 * Author: Musty Dungeon Studios
 * Network: true
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MDS_RELEASE_MANAGER_VERSION', '0.6.8' );
define( 'MDS_RM_PATH', plugin_dir_path( __FILE__ ) );
define( 'MDS_RM_URL', plugin_dir_url( __FILE__ ) );

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

// --- 2. File Template System ---
function mds_render_template( $file_relative_path, $tokens = array() ) {
    $file_path = MDS_RM_PATH . $file_relative_path;
    if ( ! file_exists( $file_path ) ) {
        return '';
    }
    $content = file_get_contents( $file_path );
    foreach ( $tokens as $key => $val ) {
        $content = str_replace( '{' . $key . '}', (string) $val, $content );
    }
    return $content;
}

// --- 3. Enqueue Assets ---
add_action( 'wp_enqueue_scripts', 'mds_release_manager_enqueue_assets' );
function mds_release_manager_enqueue_assets() {
    $fa_support = get_option( 'mds_rm_fa_support', array( 'internal' ) );
    if ( in_array( 'internal', (array) $fa_support, true ) ) {
        wp_enqueue_style( 'mds-font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css', array(), '6.4.0' );
    }
    wp_enqueue_style( 'mds-release-links-css', MDS_RM_URL . 'css/frontend.css', array(), MDS_RELEASE_MANAGER_VERSION );
}

add_action( 'admin_enqueue_scripts', 'mds_rm_admin_assets' );
function mds_rm_admin_assets( $hook ) {
    $screen = get_current_screen();
    
    if ( $screen && ( $screen->post_type === 'release' || $screen->post_type === 'artist' ) ) {
        wp_enqueue_media();
        wp_enqueue_style( 'mds-admin-styles', MDS_RM_URL . 'css/admin-styles.css', array(), MDS_RELEASE_MANAGER_VERSION );
        wp_enqueue_script( 'mds-meta-boxes-js', MDS_RM_URL . 'js/meta-boxes.js', array('jquery'), MDS_RELEASE_MANAGER_VERSION, true );
    }
    
    if ( $screen && $screen->id === 'release_page_mds-release-settings' ) {
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );
        wp_enqueue_style( 'mds-fa', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css', array(), '6.4.0' );
        wp_enqueue_style( 'mds-iconpicker-css', 'https://cdnjs.cloudflare.com/ajax/libs/fontawesome-iconpicker/3.2.0/css/fontawesome-iconpicker.min.css', array(), '3.2.0' );
        wp_enqueue_script( 'mds-iconpicker-js', 'https://cdnjs.cloudflare.com/ajax/libs/fontawesome-iconpicker/3.2.0/js/fontawesome-iconpicker.min.js', array('jquery'), '3.2.0', true );
        
        wp_enqueue_style( 'mds-admin-styles', MDS_RM_URL . 'css/admin-styles.css', array(), MDS_RELEASE_MANAGER_VERSION );
        wp_enqueue_script( 'mds-settings-js', MDS_RM_URL . 'js/settings.js', array('jquery'), MDS_RELEASE_MANAGER_VERSION, true );
        
        if ( function_exists( 'wp_enqueue_code_editor' ) ) {
            $settings = wp_enqueue_code_editor( array( 'type' => 'text/html' ) );
            wp_add_inline_script( 'mds-settings-js', 'var mds_cm_settings = ' . wp_json_encode( $settings ) . ';' );
        }
        
        wp_localize_script( 'mds-settings-js', 'mds_rm_data', array(
            'rest_url'      => esc_url_raw( rest_url( 'mds/v1/settings' ) ),
            'templates_url' => esc_url_raw( rest_url( 'mds/v1/templates' ) ),
            'purge_url'     => esc_url_raw( rest_url( 'mds/v1/purge-cache' ) ),
            'migrate_url'   => esc_url_raw( rest_url( 'mds/v1/migrate' ) ),
            'nonce'         => wp_create_nonce( 'wp_rest' )
        ) );
    }
}

// --- 4. Multisite Global Database Table Schema ---
register_activation_hook( __FILE__, 'mds_release_manager_activate' );
require_once MDS_RM_PATH . 'includes/csv-handler.php';

function mds_release_manager_activate( $network_wide ) {
    mds_release_manager_create_global_tables();
    mds_register_cpts();
    flush_rewrite_rules();
}




function mds_release_manager_create_global_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    
    $table_releases = $wpdb->base_prefix . 'mds_release_manager';
    $sql_releases = "CREATE TABLE $table_releases (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        blog_id bigint(20) unsigned NOT NULL,
        post_id bigint(20) unsigned NOT NULL,
        artist_id char(36) NOT NULL,
        upc_ean varchar(100) NOT NULL,
        release_title varchar(255) NOT NULL,
        release_date date DEFAULT NULL,
        release_format varchar(50) NOT NULL,
        record_label varchar(255) NOT NULL,
        distributor varchar(255) NOT NULL,
        buy_now_link varchar(255) NOT NULL,
        bandcamp_shortcode text NOT NULL,
        audio_preview_url varchar(500) NOT NULL,
        tags text NOT NULL,
        featured_image_url varchar(500) NOT NULL,
        about longtext NOT NULL,
        streaming_links longtext NOT NULL,
        PRIMARY KEY  (id),
        KEY upc_ean (upc_ean),
        KEY post_blog (post_id,blog_id)
    ) $charset_collate;";
    dbDelta( $sql_releases );

    $table_artists = $wpdb->base_prefix . 'mds_artist_manager';
    $sql_artists = "CREATE TABLE $table_artists (
        id char(36) NOT NULL,
        blog_id bigint(20) unsigned NOT NULL,
        post_id bigint(20) unsigned NOT NULL,
        artist_name varchar(100) NOT NULL,
        artist_biography longtext NOT NULL,
        artist_contact longtext NOT NULL,
        artist_featured_image varchar(500) NOT NULL,
        PRIMARY KEY  (id),
        KEY artist_name (artist_name),
        KEY post_blog (post_id,blog_id)
    ) $charset_collate;";
    dbDelta( $sql_artists );
}

add_action( 'plugins_loaded', 'mds_release_manager_check_upgrade' );
function mds_release_manager_check_upgrade() {
    $current_version = get_site_option( 'mds_release_manager_version', '0' );
    if ( version_compare( $current_version, MDS_RELEASE_MANAGER_VERSION, '<' ) ) {
        mds_release_manager_create_global_tables();
        update_site_option( 'mds_release_manager_version', MDS_RELEASE_MANAGER_VERSION );
    }
}

// --- 5. Register CPT & Taxonomies ---
add_action( 'init', 'mds_register_cpts' );
function mds_register_cpts() {
    register_post_type( 'release', array(
        'labels'       => array(
            'name'               => 'Releases',
            'singular_name'      => 'Release',
            'add_new'            => 'Add Release',
            'add_new_item'       => 'Add New Release',
            'edit_item'          => 'Edit Release',
            'new_item'           => 'New Release',
            'all_items'          => 'All Releases',
            'menu_name'          => 'Releases'
        ),
        'public'       => true,
        'has_archive'  => true,
        'show_in_rest' => false,
        'supports'     => array( 'title', 'editor' ),
        'menu_icon'    => 'dashicons-album',
        'rewrite'      => array( 'slug' => 'release' ),
    ) );

    register_post_type( 'artist', array(
        'labels'       => array(
            'name'               => 'Artists',
            'singular_name'      => 'Artist',
            'add_new'            => 'Add Artist',
            'add_new_item'       => 'Add New Artist',
            'edit_item'          => 'Edit Artist',
            'new_item'           => 'New Artist',
            'all_items'          => 'All Artists',
            'menu_name'          => 'Artists'
        ),
        'public'       => true,
        'has_archive'  => true,
        'show_in_rest' => false,
        'show_in_menu' => 'edit.php?post_type=release',
        'supports'     => array( 'title', 'editor' ),
        'menu_icon'    => 'dashicons-groups',
        'rewrite'      => array( 'slug' => 'artist' ),
    ) );

    register_taxonomy( 'release_tag', 'release', array(
        'label'             => 'Release Tags',
        'hierarchical'      => false,
        'show_admin_column' => true,
        'show_in_rest'      => false,
        'meta_box_cb'       => false,
        'rewrite'           => array( 'slug' => 'release-tag' ),
    ) );

    register_taxonomy( 'release_format', 'release', array(
        'labels'            => array(
            'name'          => 'Release Formats',
            'singular_name' => 'Release Format',
            'menu_name'     => 'Release Formats'
        ),
        'hierarchical'      => true,
        'show_admin_column' => true,
        'show_ui'           => true,
        'show_in_rest'      => false,
        'meta_box_cb'       => false,
        'rewrite'           => array( 'slug' => 'release-format' ),
    ) );
}

// --- 5.5 Elementor Support ---
add_action( 'init', 'mds_add_elementor_support_artist', 99 );
function mds_add_elementor_support_artist() {
    add_post_type_support( 'artist', 'elementor' );
}

add_action( 'elementor/dynamic_tags/register', 'mds_rm_register_elementor_tags' );
function mds_rm_register_elementor_tags( $dynamic_tags_manager ) {
    if ( ! class_exists( '\Elementor\Core\DynamicTags\Data_Tag' ) ) return;
    
    class MDS_Release_Meta_Tag extends \Elementor\Core\DynamicTags\Data_Tag {
        public function get_name() { return 'mds_release_meta'; }
        public function get_title() { return 'Release Metadata'; }
        public function get_group() { return 'post'; }
        public function get_categories() {
            return [
                \Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY,
                \Elementor\Modules\DynamicTags\Module::URL_CATEGORY,
                \Elementor\Modules\DynamicTags\Module::IMAGE_CATEGORY
            ];
        }
        protected function register_controls() {
            $this->add_control( 'mds_meta_key', [
                'label'   => 'Select Field',
                'type'    => \Elementor\Controls_Manager::SELECT,
                'options' => [
                    'upc_ean'              => 'UPC/EAN',
                    'release_year'         => 'Release Year (Derived)',
                    'record_label'         => 'Record Label',
                    'distributor'          => 'Distributor',
                    'buy_now_link'         => 'Buy Now Link',
                    'featured_image_url'   => 'Featured Image URL',
                    'bandcamp_shortcode'   => 'Bandcamp Shortcode',
                    'release_links_widget' => 'Streaming Links Widget',
                    'release_meta_widget'  => 'WooCommerce Meta Bar Widget'
                ]
            ] );
        }
        public function get_value( array $options = [] ) {
            $key = $this->get_settings( 'mds_meta_key' );
            if ( empty( $key ) ) return '';
            
            // FETCH DIRECTLY FROM REST API TO ENSURE SOURCE OF RECORD INTEGRITY
            $req = new WP_REST_Request( 'GET', '/mds/v1/releases/post/' . get_the_ID() );
            $req->set_param( 'blog_id', get_current_blog_id() );
            $res = rest_do_request( $req );
            $release_data = $res->is_error() ? array() : $res->get_data();
            
            if ( empty($release_data) ) return '';

            if ( $key === 'release_links_widget' ) {
                return ! empty( $release_data['upc_ean'] ) ? do_shortcode( '[release_links upc="' . esc_attr( $release_data['upc_ean'] ) . '"]' ) : '';
            }
            if ( $key === 'release_meta_widget' ) {
                return ! empty( $release_data['upc_ean'] ) ? mds_render_meta_bar_html( $release_data['upc_ean'], false ) : '';
            }
            if ( $key === 'release_year' ) {
                return ! empty( $release_data['release_date'] ) ? date( 'Y', strtotime( $release_data['release_date'] ) ) : '';
            }
            if ( $key === 'featured_image_url' ) {
                return [ 'id' => '', 'url' => $release_data['featured_image_url'] ?? '' ];
            }
            return $release_data[$key] ?? '';
        }
    }
    $dynamic_tags_manager->register( new MDS_Release_Meta_Tag() );
}

add_action( 'elementor/widgets/register', 'mds_rm_register_elementor_widgets' );
function mds_rm_register_elementor_widgets( $widgets_manager ) {
    require_once MDS_RM_PATH . 'includes/elementor-widgets.php';
    require_once MDS_RM_PATH . 'includes/csv-handler.php';
    $widgets_manager->register( new \MDS_Release_Meta_Widget() );
    $widgets_manager->register( new \MDS_Release_Links_Widget() );
}

// --- 6. Admin Menus Structure ---
add_action( 'admin_menu', 'mds_release_manager_menu' );
function mds_release_manager_menu() {
    add_submenu_page(
        'edit.php?post_type=release',
        'Add Artists',
        'Add Artists',
        'edit_posts',
        'post-new.php?post_type=artist'
    );
    add_submenu_page(
        'edit.php?post_type=release',
        'Settings',
        'Settings',
        'manage_options',
        'mds-release-settings',
        'mds_rm_settings_page'
    );
    add_submenu_page(
        'edit.php?post_type=release',
        'Import / Export CSV',
        'Import / Export',
        'manage_options',
        'mds-release-csv',
        'mds_rm_csv_page'
    );
}

add_action( 'admin_menu', 'mds_release_manager_admin_menu_updates', 999 );
function mds_release_manager_admin_menu_updates() {
    global $submenu;
    if ( isset( $submenu['edit.php?post_type=release'] ) ) {
        $lookup = array();
        foreach ( $submenu['edit.php?post_type=release'] as $item ) {
            $lookup[ $item[2] ] = $item;
        }

        $new_submenu = array();
        
        if ( isset( $lookup['edit.php?post_type=release'] ) ) {
            $lookup['edit.php?post_type=release'][0] = 'All Releases';
            $new_submenu[] = $lookup['edit.php?post_type=release'];
        }
        if ( isset( $lookup['post-new.php?post_type=release'] ) ) {
            $lookup['post-new.php?post_type=release'][0] = 'Add Release';
            $new_submenu[] = $lookup['post-new.php?post_type=release'];
        }
        if ( isset( $lookup['edit.php?post_type=artist'] ) ) {
            $new_submenu[] = $lookup['edit.php?post_type=artist'];
        }
        if ( isset( $lookup['post-new.php?post_type=artist'] ) ) {
            $lookup['post-new.php?post_type=artist'][0] = 'Add Artists';
            $new_submenu[] = $lookup['post-new.php?post_type=artist'];
        }
        if ( isset( $lookup['edit-tags.php?taxonomy=release_tag&amp;post_type=release'] ) ) {
            $new_submenu[] = $lookup['edit-tags.php?taxonomy=release_tag&amp;post_type=release'];
        }
        if ( isset( $lookup['edit-tags.php?taxonomy=release_format&amp;post_type=release'] ) ) {
            $new_submenu[] = $lookup['edit-tags.php?taxonomy=release_format&amp;post_type=release'];
        }
        if ( isset( $lookup['mds-release-settings'] ) ) {
            $new_submenu[] = $lookup['mds-release-settings'];
        }
        if ( isset( $lookup['mds-release-csv'] ) ) {
            $new_submenu[] = $lookup['mds-release-csv'];
        }

        $submenu['edit.php?post_type=release'] = $new_submenu;
    }
}

// --- 7. Meta Boxes, Taxonomy Filters, Custom Columns ---
add_action( 'restrict_manage_posts', 'mds_rm_filter_taxonomies' );
function mds_rm_filter_taxonomies( $post_type ) {
    if ( 'release' !== $post_type ) {
        return;
    }
    $taxonomies = array( 'release_tag', 'release_format' );
    foreach ( $taxonomies as $tax_slug ) {
        $tax_obj = get_taxonomy( $tax_slug );
        if ( ! $tax_obj ) continue;
        $selected = isset( $_GET[ $tax_slug ] ) ? sanitize_text_field( wp_unslash( $_GET[ $tax_slug ] ) ) : '';
        wp_dropdown_categories( array(
            'show_option_all' => "Show All {$tax_obj->label}",
            'taxonomy'        => $tax_slug,
            'name'            => $tax_slug,
            'orderby'         => 'name',
            'selected'        => $selected,
            'show_count'      => true,
            'hide_empty'      => false,
            'value_field'     => 'slug',
        ) );
    }
}

add_filter( 'manage_release_posts_columns', 'mds_rm_add_custom_columns' );
function mds_rm_add_custom_columns( $columns ) {
    $new_columns = array();
    foreach ( $columns as $key => $title ) {
        if ( $key === 'date' ) {
            $new_columns['mds_shortcode'] = 'Shortcode';
        }
        $new_columns[ $key ] = $title;
    }
    return $new_columns;
}

add_action( 'manage_release_posts_custom_column', 'mds_rm_render_custom_columns', 10, 2 );
function mds_rm_render_custom_columns( $column_name, $post_id ) {
    if ( $column_name === 'mds_shortcode' ) {
        // Fetch from REST API (Source of Record)
        $req = new WP_REST_Request( 'GET', '/mds/v1/releases/post/' . $post_id );
        $req->set_param('blog_id', get_current_blog_id());
        $res = rest_do_request( $req );
        $data = $res->is_error() ? array() : $res->get_data();
        
        $upc = $data['upc_ean'] ?? '';
        if ( ! empty( $upc ) ) {
            $shortcode_text = '[release_links upc="' . esc_attr( $upc ) . '"]';
            echo '<span class="mds-copy-badge" style="background:#2271b1; color:#fff; border-radius:4px; padding:4px 8px; font-family:monospace; font-size:12px; display:inline-block;" >' . esc_html( $shortcode_text ) . '</span>';
        } else {
            echo '&mdash;';
        }
    }
}

add_action( 'add_meta_boxes', 'mds_add_meta_boxes' );
function mds_add_meta_boxes() {
    add_meta_box( 'mds_release_settings', 'Release Settings', 'mds_render_release_settings_box', 'release', 'normal', 'high' );
    add_meta_box( 'mds_artist_settings', 'Artist Settings', 'mds_render_artist_settings_box', 'artist', 'normal', 'high' );
}

function mds_render_artist_settings_box( $post ) {
    // FETCH DIRECTLY FROM REST API TO ENSURE SOURCE OF RECORD INTEGRITY
    $req = new WP_REST_Request( 'GET', '/mds/v1/artists/post/' . $post->ID );
    $req->set_param( 'blog_id', get_current_blog_id() );
    $res = rest_do_request( $req );
    $data = $res->is_error() ? array() : $res->get_data();

    $featured_image = $data['artist_featured_image'] ?? '';
    $contact = $data['artist_contact'] ?? '';
    
    ob_start();
    wp_editor( wp_kses_post( $contact ), 'artist_contact', array('media_buttons' => false, 'textarea_rows' => 5) );
    $editor_html = ob_get_clean();

    $tokens = array(
        'nonce_field'           => wp_nonce_field( 'mds_save_artist_data', 'mds_artist_meta_nonce', true, false ),
        'artist_featured_image' => esc_url( $featured_image ),
        'preview_image'         => ! empty( $featured_image ) ? esc_url( $featured_image ) : 'https://via.placeholder.com/150/CCCCCC/969696?text=Artist',
        'editor_html'           => $editor_html
    );
    echo mds_render_template( 'templates/artist-meta-box.html', $tokens );
}

function mds_render_release_settings_box( $post ) {
    // FETCH DIRECTLY FROM REST API TO ENSURE SOURCE OF RECORD INTEGRITY
    $req = new WP_REST_Request( 'GET', '/mds/v1/releases/post/' . $post->ID );
    $req->set_param( 'blog_id', get_current_blog_id() );
    $res = rest_do_request( $req );
    $data = $res->is_error() ? array() : $res->get_data();

    global $wpdb;
    $artist_table_name = $wpdb->base_prefix . 'mds_artist_manager';
    $artists = $wpdb->get_results( "SELECT id, artist_name FROM $artist_table_name ORDER BY artist_name ASC" );
    
    $artist_id = $data['artist_id'] ?? '';
    $artist_options = '<option value="">-- Select Artist --</option>';
    foreach ( $artists as $artist ) {
        $artist_options .= '<option value="' . esc_attr( $artist->id ) . '" ' . selected( $artist_id, $artist->id, false ) . '>' . esc_html( $artist->artist_name ) . '</option>';
    }
    if ( empty( $artists ) ) {
        $artist_options = '<option value="">No Artists Found. Please Create One.</option>';
    }

    $release_format = $data['release_format'] ?? '';
    $format_options = '<option value="">-- Select Format --</option>';
    $formats = get_terms( array( 'taxonomy' => 'release_format', 'hide_empty' => false ) );
    if ( ! is_wp_error( $formats ) ) {
        foreach ( $formats as $f ) {
            $format_options .= '<option value="' . esc_attr( $f->name ) . '" ' . selected( $release_format, $f->name, false ) . '>' . esc_html( $f->name ) . '</option>';
        }
    }

    $global_providers = get_option('mds_rm_providers', array());
    $global_providers_opts = '<option value="">Select Provider</option>';
    foreach ( $global_providers as $gp ) {
        $global_providers_opts .= '<option value="' . esc_attr($gp['name']) . '">' . esc_html($gp['name']) . '</option>';
    }

    $streaming_links_json = $data['streaming_links'] ?? '[]';
    $streaming_links = json_decode( $streaming_links_json, true );
    if ( ! is_array( $streaming_links ) ) $streaming_links = array();
    
    $streaming_rows_html = '';
    foreach ( $streaming_links as $provider => $url ) {
        $opts = '<option value="">Select Provider</option>';
        $matched = false;
        foreach ( $global_providers as $gp ) {
            $is_selected = selected( $provider, $gp['name'], false );
            if ( $is_selected ) $matched = true;
            $opts .= '<option value="' . esc_attr($gp['name']) . '" ' . $is_selected . '>' . esc_html($gp['name']) . '</option>';
        }
        if ( ! $matched && ! empty( $provider ) ) {
            $opts .= '<option value="' . esc_attr($provider) . '" selected>' . esc_html($provider) . '</option>';
        }
        
        $streaming_rows_html .= '<div class="mds-streaming-row">';
        $streaming_rows_html .= '<select name="mds_streaming_provider[]">' . $opts . '</select>';
        $streaming_rows_html .= '<input type="url" name="mds_streaming_url[]" value="' . esc_url( $url ) . '" placeholder="https://..." />';
        $streaming_rows_html .= '<button type="button" class="button mds-remove-row">Remove</button>';
        $streaming_rows_html .= '</div>';
    }

    $featured_img = $data['featured_image_url'] ?? '';
    
    $tags = wp_get_post_terms( $post->ID, 'release_tag', array( 'fields' => 'names' ) );
    $tags_str = ( ! is_wp_error( $tags ) && is_array( $tags ) ) ? implode( ', ', $tags ) : '';
    
    $tokens = array(
        'nonce_field'              => wp_nonce_field( 'mds_save_release_data', 'mds_release_meta_nonce', true, false ),
        'featured_image_url'       => esc_url( $featured_img ),
        'preview_image'            => ! empty( $featured_img ) ? esc_url( $featured_img ) : 'https://via.placeholder.com/150/CCCCCC/969696?text=Release',
        'artists_options_html'     => $artist_options,
        'upc_ean'                  => esc_attr( $data['upc_ean'] ?? '' ),
        'formats_options_html'     => $format_options,
        'record_label'             => esc_attr( $data['record_label'] ?? '' ),
        'distributor'              => esc_attr( $data['distributor'] ?? '' ),
        'streaming_rows_html'      => $streaming_rows_html,
        'global_providers_options' => $global_providers_opts,
        'release_tags'             => esc_attr( $tags_str ),
        'buy_now_link'             => esc_url( $data['buy_now_link'] ?? '' ),
        'bandcamp_shortcode'       => esc_attr( $data['bandcamp_shortcode'] ?? '' ),
        'audio_preview_url'        => esc_url( $data['audio_preview_url'] ?? '' )
    );

    echo mds_render_template( 'templates/release-meta-box.html', $tokens );
}

// --- 8. Meta Saving Actions (Routed through REST API internally) ---
add_action( 'save_post_artist', 'mds_save_artist_meta_data' );
function mds_save_artist_meta_data( $post_id ) {
    if ( ! isset( $_POST['mds_artist_meta_nonce'] ) || ! wp_verify_nonce( $_POST['mds_artist_meta_nonce'], 'mds_save_artist_data' ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    // Use internal REST API call to fulfill CRUD requirements
    $req = new WP_REST_Request( 'POST', '/mds/v1/artists/post/' . $post_id );
    $req->set_body_params( $_POST );
    $req->set_param( 'blog_id', get_current_blog_id() );
    rest_do_request( $req );
    mds_rm_purge_cache();
}

add_action( 'save_post_release', 'mds_save_release_meta_data' );
function mds_save_release_meta_data( $post_id ) {
    if ( ! isset( $_POST['mds_release_meta_nonce'] ) || ! wp_verify_nonce( $_POST['mds_release_meta_nonce'], 'mds_save_release_data' ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;
    
    // Use internal REST API call to fulfill CRUD requirements
    $req = new WP_REST_Request( 'POST', '/mds/v1/releases/post/' . $post_id );
    $req->set_body_params( $_POST );
    $req->set_param( 'blog_id', get_current_blog_id() );
    rest_do_request( $req );
    mds_rm_purge_cache();
}

// --- 9. Shortcode Render via Templates ---
add_shortcode( 'release_links', 'mds_render_release_links_shortcode' );
function mds_render_release_links_shortcode( $atts ) {
    $atts = shortcode_atts( array( 'upc' => '' ), $atts );
    if ( empty( $atts['upc'] ) ) return '';
    $upc = preg_replace( '/[^0-9]/', '', $atts['upc'] );
    
    // FETCH DIRECTLY FROM REST API TO ENSURE SOURCE OF RECORD INTEGRITY
    $req = new WP_REST_Request( 'GET', '/mds/v1/releases/upc/' . $upc );
    $res = rest_do_request( $req );
    $release = $res->is_error() ? array() : $res->get_data();
    
    if ( empty($release) ) return '';
    
    $links_array = json_decode( $release['streaming_links'], true );
    if ( ! is_array( $links_array ) ) $links_array = array();
    
    $global_providers = get_option( 'mds_rm_providers', array() );
    
    $icon_bg       = get_option( 'mds_rm_links_icon_bg', 'transparent' );
    $buy_now_icon  = get_option( 'mds_rm_buy_now_icon', 'fas fa-cart-shopping' );
    $buy_now_color = get_option( 'mds_rm_buy_now_color', '#ffffff' );
    $buy_now_bg    = get_option( 'mds_rm_buy_now_bg', '#000000' );
    
    $icons_html = '';
    foreach ( $links_array as $provider_name => $url ) {
        $icon = 'fas fa-link';
        $color = '#ffffff';
        foreach ( $global_providers as $gp ) {
            if ( strcasecmp($gp['name'], $provider_name) === 0 ) {
                $icon = $gp['icon'];
                $color = $gp['color'];
                break;
            }
        }
        $icons_html .= '<a href="' . esc_url( $url ) . '" target="_blank" class="mds-icon-circle" style="background-color: ' . esc_attr( $icon_bg ) . '; color: ' . esc_attr( $color ) . ';" title="' . esc_attr( $provider_name ) . '">';
        $icons_html .= '<i class="' . esc_attr( $icon ) . '"></i></a>';
    }

    $buy_now_html = '';
    if ( ! empty( $release['buy_now_link'] ) ) {
        $buy_now_html .= '<a href="' . esc_url( $release['buy_now_link'] ) . '" target="_blank" class="mds-icon-circle mds-buy-now" style="background-color: ' . esc_attr( $buy_now_bg ) . '; color: ' . esc_attr( $buy_now_color ) . ';" title="Buy Now">';
        $buy_now_html .= '<i class="' . esc_attr( $buy_now_icon ) . '"></i></a>';
    }

    $audio_players = get_option( 'mds_rm_audio_players', array() );
    $audio_player_html = '';
    $bandcamp_used = false;
    
    if ( in_array( 'blocks_for_bandcamp', (array)$audio_players, true ) && ! empty( $release['bandcamp_shortcode'] ) ) {
        $input = trim( $release['bandcamp_shortcode'] );
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
    
    if ( ! $bandcamp_used && in_array( 'native', (array)$audio_players, true ) && ! empty( $release['audio_preview_url'] ) ) {
        $audio_player_html .= '<audio controls src="' . esc_url( $release['audio_preview_url'] ) . '" style="width: 100%;"></audio>';
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
        'audio_player_html' => $audio_player_html
    );

    return mds_render_template( 'templates/shortcode-widget.html', $tokens );
}

function mds_render_meta_bar_html( $upc, $include_shortcode = true ) {
    // FETCH DIRECTLY FROM REST API TO ENSURE SOURCE OF RECORD INTEGRITY
    $req = new WP_REST_Request( 'GET', '/mds/v1/releases/upc/' . $upc );
    $res = rest_do_request( $req );
    $release = $res->is_error() ? array() : $res->get_data();
    
    if ( empty($release) ) return '';
    
    $year = ! empty( $release['release_date'] ) && $release['release_date'] !== '0000-00-00' ? date( 'Y', strtotime( $release['release_date'] ) ) : '';
    $year_html = $year ? '<span class="mds-meta-item" title="Release Year"><i class="fas fa-calendar"></i> ' . esc_html( $year ) . '</span>' : '';
    $label_html = ! empty( $release['record_label'] ) ? '<span class="mds-meta-item" title="Record Label"><i class="fas fa-compact-disc"></i> ' . esc_html( $release['record_label'] ) . '</span>' : '';
    $dist_html = ! empty( $release['distributor'] ) ? '<span class="mds-meta-item" title="Distributor"><i class="fas fa-tower-broadcast"></i> ' . esc_html( $release['distributor'] ) . '</span>' : '';
    $upc_html = ! empty( $release['upc_ean'] ) ? '<span class="mds-meta-item" title="UPC/EAN"><i class="fas fa-barcode"></i> ' . esc_html( $release['upc_ean'] ) . '</span>' : '';
    
    $tokens = array(
        'meta_align'   => esc_attr( get_option( 'mds_rm_meta_align', 'flex-start' ) ),
        'meta_bg'      => esc_attr( get_option( 'mds_rm_meta_bg', '#000000' ) ),
        'meta_color'   => esc_attr( get_option( 'mds_rm_meta_color', '#ffffff' ) ),
        'meta_gap'     => esc_attr( get_option( 'mds_rm_meta_gap', '15px' ) ),
        'meta_padding' => esc_attr( get_option( 'mds_rm_meta_padding', '12px 18px' ) ),
        'meta_radius'  => esc_attr( get_option( 'mds_rm_meta_radius', '0px' ) ),
        'year_html'        => $year_html,
        'label_html'       => $label_html,
        'distributor_html' => $dist_html,
        'upc_html'         => $upc_html,
        'shortcode_widget' => $include_shortcode ? do_shortcode( '[release_links upc="' . esc_attr( $upc ) . '"]' ) : ''
    );
    
    return mds_render_template( 'templates/woo-meta-widget.html', $tokens );
}

// --- 10. Inject into WooCommerce via Templates ---
add_action( 'woocommerce_single_product_summary', 'mds_inject_release_links_by_sku', 25 );
function mds_inject_release_links_by_sku() {
    global $product;
    if ( ! is_a( $product, 'WC_Product' ) && function_exists( 'wc_get_product' ) ) {
        $product = wc_get_product( get_the_ID() );
    }
    if ( is_a( $product, 'WC_Product' ) ) {
        $sku = $product->get_sku();
        $sku_numeric = preg_replace( '/[^0-9]/', '', $sku );
        if ( ! empty( $sku_numeric ) ) {
            echo mds_render_meta_bar_html( $sku_numeric, true );
        }
    }
}

// --- 11. REST API CRUD, Migration & Template Editor ---
add_action( 'rest_api_init', function () {
    register_rest_route( 'mds/v1', '/settings', array(
        'methods' => 'POST',
        'callback' => 'mds_rm_save_settings_api',
        'permission_callback' => function () { return current_user_can( 'manage_options' ); }
    ) );
    
    register_rest_route( 'mds/v1', '/templates', array(
        array( 'methods' => 'GET', 'callback' => 'mds_rm_get_template_api', 'permission_callback' => function () { return current_user_can( 'manage_options' ); } ),
        array( 'methods' => 'POST', 'callback' => 'mds_rm_save_template_api', 'permission_callback' => function () { return current_user_can( 'manage_options' ); } )
    ) );

    register_rest_route( 'mds/v1', '/purge-cache', array(
        'methods' => 'POST',
        'callback' => function() {
            mds_rm_purge_cache();
            return rest_ensure_response( array( 'success' => true, 'message' => 'Cache purged.' ) );
        },
        'permission_callback' => function () { return current_user_can( 'manage_options' ); }
    ) );
    
    // Migration Endpoint
    register_rest_route( 'mds/v1', '/migrate', array(
        'methods' => 'POST',
        'callback' => 'mds_rm_run_migration_api',
        'permission_callback' => function () { return current_user_can( 'manage_options' ); }
    ) );
    
    // Public CRUD Endpoints - Unified Source of Record Architecture
    register_rest_route( 'mds/v1', '/releases', array(
        array( 'methods' => 'GET', 'callback' => 'mds_rm_api_get_all_releases', 'permission_callback' => '__return_true' )
    ) );
    register_rest_route( 'mds/v1', '/artists', array(
        array( 'methods' => 'GET', 'callback' => 'mds_rm_api_get_all_artists', 'permission_callback' => '__return_true' )
    ) );
    
    // Specific Resource Endpoints
    register_rest_route( 'mds/v1', '/releases/upc/(?P<upc>[\w\-]+)', array(
        array( 'methods' => 'GET', 'callback' => 'mds_rm_api_get_release_by_upc', 'permission_callback' => '__return_true' )
    ) );
    register_rest_route( 'mds/v1', '/releases/post/(?P<id>\d+)', array(
        array( 'methods' => 'GET', 'callback' => 'mds_rm_api_get_release_by_post', 'permission_callback' => '__return_true' ),
        array( 'methods' => 'POST', 'callback' => 'mds_rm_api_save_release_by_post', 'permission_callback' => function () { return current_user_can( 'edit_posts' ); } )
    ) );
    register_rest_route( 'mds/v1', '/artists/post/(?P<id>\d+)', array(
        array( 'methods' => 'GET', 'callback' => 'mds_rm_api_get_artist_by_post', 'permission_callback' => '__return_true' ),
        array( 'methods' => 'POST', 'callback' => 'mds_rm_api_save_artist_by_post', 'permission_callback' => function () { return current_user_can( 'edit_posts' ); } )
    ) );
} );

// API Controllers
function mds_rm_api_get_all_releases( $request ) {
    global $wpdb;
    $table = $wpdb->base_prefix . 'mds_release_manager';
    return rest_ensure_response( $wpdb->get_results( "SELECT * FROM $table", ARRAY_A ) );
}
function mds_rm_api_get_all_artists( $request ) {
    global $wpdb;
    $table = $wpdb->base_prefix . 'mds_artist_manager';
    return rest_ensure_response( $wpdb->get_results( "SELECT * FROM $table", ARRAY_A ) );
}
function mds_rm_api_get_release_by_upc( $request ) {
    global $wpdb;
    $upc = $request->get_param('upc');
    $table = $wpdb->base_prefix . 'mds_release_manager';
    $data = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE upc_ean = %s", $upc ), ARRAY_A );
    return rest_ensure_response( $data ?: array() );
}
function mds_rm_api_get_release_by_post( $request ) {
    global $wpdb;
    $post_id = $request->get_param('id');
    $blog_id = $request->get_param('blog_id') ?: get_current_blog_id();
    $table = $wpdb->base_prefix . 'mds_release_manager';
    $data = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE post_id = %d AND blog_id = %d", $post_id, $blog_id ), ARRAY_A );
    return rest_ensure_response( $data ?: array() );
}
function mds_rm_api_get_artist_by_post( $request ) {
    global $wpdb;
    $post_id = $request->get_param('id');
    $blog_id = $request->get_param('blog_id') ?: get_current_blog_id();
    $table = $wpdb->base_prefix . 'mds_artist_manager';
    $data = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE post_id = %d AND blog_id = %d", $post_id, $blog_id ), ARRAY_A );
    return rest_ensure_response( $data ?: array() );
}

function mds_rm_api_save_artist_by_post( $request ) {
    global $wpdb;
    $post_id = $request->get_param('id');
    $blog_id = $request->get_param('blog_id') ?: get_current_blog_id();
    $params = $request->get_body_params();
    
    $artist_table = $wpdb->base_prefix . 'mds_artist_manager';
    
    $uuid = get_post_meta( $post_id, '_mds_artist_uuid', true );
    if ( empty( $uuid ) && function_exists( 'wp_generate_uuid4' ) ) {
        $uuid = wp_generate_uuid4();
        update_post_meta( $post_id, '_mds_artist_uuid', $uuid );
    } elseif ( empty( $uuid ) ) {
        $uuid = sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0x0fff ) | 0x4000, mt_rand( 0, 0x3fff ) | 0x8000, mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ) );
        update_post_meta( $post_id, '_mds_artist_uuid', $uuid );
    }

    $artist_name = get_the_title( $post_id );
    $biography = get_post_field( 'post_content', $post_id );
    $contact = wp_kses_post( wp_unslash( $params['artist_contact'] ?? '' ) );
    $image = esc_url_raw( wp_unslash( $params['artist_featured_image'] ?? '' ) );
    
    $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $artist_table WHERE post_id = %d AND blog_id = %d", $post_id, $blog_id ) );
    
    $sync_data = array(
        'id' => $uuid,
        'blog_id' => $blog_id,
        'post_id' => $post_id,
        'artist_name' => $artist_name,
        'artist_biography' => $biography,
        'artist_contact' => $contact,
        'artist_featured_image' => $image
    );

    if ( $existing ) {
        $wpdb->update( $artist_table, $sync_data, array( 'id' => $existing ) );
    } else {
        $wpdb->insert( $artist_table, $sync_data );
    }
    
    return rest_ensure_response( array( 'success' => true ) );
}

function mds_rm_api_save_release_by_post( $request ) {
    global $wpdb;
    $post_id = $request->get_param('id');
    $blog_id = $request->get_param('blog_id') ?: get_current_blog_id();
    $params = $request->get_body_params();
    
    $upc_ean = preg_replace( '/[^0-9]/', '', $params['upc_ean'] ?? '' );
    
    $providers = isset( $params['mds_streaming_provider'] ) ? (array) $params['mds_streaming_provider'] : array();
    $urls      = isset( $params['mds_streaming_url'] ) ? (array) $params['mds_streaming_url'] : array();
    $streaming_links_arr = array();
    for ( $i = 0; $i < count( $providers ); $i++ ) {
        $p = sanitize_text_field( wp_unslash( $providers[ $i ] ?? '' ) );
        $u = esc_url_raw( wp_unslash( $urls[ $i ] ?? '' ) );
        if ( ! empty( $p ) && ! empty( $u ) ) {
            $streaming_links_arr[ $p ] = $u;
        }
    }
    $streaming = wp_json_encode( $streaming_links_arr );
    
    $tags_raw = sanitize_text_field( wp_unslash( $params['release_tags'] ?? '' ) );
    $tag_array = ! empty( $tags_raw ) ? array_map( 'trim', explode( ',', $tags_raw ) ) : array();
    wp_set_post_terms( $post_id, $tag_array, 'release_tag', false );
    $tags_str = implode(', ', $tag_array);
    
    $post_obj = get_post( $post_id );
    $release_date = ( $post_obj && $post_obj->post_date && $post_obj->post_date !== '0000-00-00 00:00:00' ) ? date( 'Y-m-d', strtotime( $post_obj->post_date ) ) : null;
    $release_title = $post_obj ? $post_obj->post_title : '';
    $about = $post_obj ? $post_obj->post_content : '';
    
    $artist_id = sanitize_text_field( wp_unslash( $params['artist_id'] ?? '' ) );
    if ( empty( $artist_id ) ) {
        $artist_id = '00000000-0000-0000-0000-000000000000'; // Default valid UUID
    }
    
    $table_name = $wpdb->base_prefix . 'mds_release_manager';
    $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table_name WHERE post_id = %d AND blog_id = %d", $post_id, $blog_id ) );
    
    $sync_data = array(
        'blog_id'            => $blog_id,
        'post_id'            => $post_id,
        'artist_id'          => $artist_id,
        'upc_ean'            => $upc_ean,
        'release_title'      => $release_title,
        'release_date'       => $release_date,
        'release_format'     => sanitize_text_field( wp_unslash( $params['release_format'] ?? '' ) ),
        'record_label'       => sanitize_text_field( wp_unslash( $params['record_label'] ?? '' ) ),
        'distributor'        => sanitize_text_field( wp_unslash( $params['distributor'] ?? '' ) ),
        'buy_now_link'       => esc_url_raw( wp_unslash( $params['buy_now_link'] ?? '' ) ),
        'bandcamp_shortcode' => sanitize_text_field( wp_unslash( $params['bandcamp_shortcode'] ?? '' ) ),
        'audio_preview_url'  => esc_url_raw( wp_unslash( $params['audio_preview_url'] ?? '' ) ),
        'tags'               => $tags_str,
        'featured_image_url' => esc_url_raw( wp_unslash( $params['featured_image_url'] ?? '' ) ),
        'about'              => $about,
        'streaming_links'    => $streaming
    );

    if ( $existing ) {
        $wpdb->update( $table_name, $sync_data, array( 'id' => $existing ) );
    } else {
        $wpdb->insert( $table_name, $sync_data );
    }
    
    return rest_ensure_response( array( 'success' => true ) );
}


function mds_rm_run_migration_api( $request ) {
    $sites = function_exists('get_sites') ? get_sites() : array();
    $is_ms = is_multisite();
    $artist_count = 0;
    $release_count = 0;

    if ( $is_ms ) {
        foreach ( $sites as $site ) {
            switch_to_blog( $site->blog_id );
            
            $artists = get_posts( array( 'post_type' => 'artist', 'numberposts' => -1, 'post_status' => 'any' ) );
            foreach ( $artists as $a ) { 
                $req = new WP_REST_Request( 'POST', '/mds/v1/artists/post/' . $a->ID );
                $meta = get_post_meta( $a->ID );
                $params = array();
                foreach($meta as $k => $v) { $params[$k] = $v[0]; }
                $req->set_body_params( $params );
                $req->set_param( 'blog_id', $site->blog_id );
                $res = rest_do_request( $req );
                if ( ! $res->is_error() ) $artist_count++; 
            }
            
            $releases = get_posts( array( 'post_type' => 'release', 'numberposts' => -1, 'post_status' => 'any' ) );
            foreach ( $releases as $r ) { 
                $req = new WP_REST_Request( 'POST', '/mds/v1/releases/post/' . $r->ID );
                $meta = get_post_meta( $r->ID );
                $params = array();
                foreach($meta as $k => $v) { $params[$k] = $v[0]; }
                
                $tags = wp_get_post_terms( $r->ID, 'release_tag', array( 'fields' => 'names' ) );
                $params['release_tags'] = ( ! is_wp_error( $tags ) && is_array( $tags ) ) ? implode( ', ', $tags ) : '';
                
                // Deconstruct legacy local post JSON array so REST controller can rebuild it
                $links_array = json_decode( $params['streaming_links'] ?? '[]', true );
                if ( ! is_array( $links_array ) ) $links_array = array();
                $params['mds_streaming_provider'] = array_keys( $links_array );
                $params['mds_streaming_url']      = array_values( $links_array );
                
                $req->set_body_params( $params );
                $req->set_param( 'blog_id', $site->blog_id );
                $res = rest_do_request( $req );
                if ( ! $res->is_error() ) $release_count++; 
            }
            
            restore_current_blog();
        }
    } else {
        $blog_id = get_current_blog_id();
        $artists = get_posts( array( 'post_type' => 'artist', 'numberposts' => -1, 'post_status' => 'any' ) );
        foreach ( $artists as $a ) { 
            $req = new WP_REST_Request( 'POST', '/mds/v1/artists/post/' . $a->ID );
            $meta = get_post_meta( $a->ID );
            $params = array();
            foreach($meta as $k => $v) { $params[$k] = $v[0]; }
            $req->set_body_params( $params );
            $req->set_param( 'blog_id', $blog_id );
            $res = rest_do_request( $req );
            if ( ! $res->is_error() ) $artist_count++; 
        }
        
        $releases = get_posts( array( 'post_type' => 'release', 'numberposts' => -1, 'post_status' => 'any' ) );
        foreach ( $releases as $r ) { 
            $req = new WP_REST_Request( 'POST', '/mds/v1/releases/post/' . $r->ID );
            $meta = get_post_meta( $r->ID );
            $params = array();
            foreach($meta as $k => $v) { $params[$k] = $v[0]; }
            
            $tags = wp_get_post_terms( $r->ID, 'release_tag', array( 'fields' => 'names' ) );
            $params['release_tags'] = ( ! is_wp_error( $tags ) && is_array( $tags ) ) ? implode( ', ', $tags ) : '';
            
            $req->set_body_params( $params );
            $req->set_param( 'blog_id', $blog_id );
            $res = rest_do_request( $req );
            if ( ! $res->is_error() ) $release_count++; 
        }
    }
    
    return rest_ensure_response( array( 'success' => true, 'message' => "Successfully migrated {$release_count} releases and {$artist_count} artists to global tables via REST APIs." ) );
}

function mds_rm_get_template_api( $request ) {
    $file_path = sanitize_text_field( $request->get_param('file') );
    if ( strpos($file_path, '..') === false && preg_match('/^(templates|css|js)\//', $file_path) ) {
        $full_path = MDS_RM_PATH . $file_path;
        if ( file_exists($full_path) ) {
            return rest_ensure_response( array( 'success' => true, 'content' => file_get_contents($full_path) ) );
        }
    }
    return new WP_Error( 'file_not_found', 'Invalid file path.', array( 'status' => 404 ) );
}

function mds_rm_save_template_api( $request ) {
    $params = $request->get_body_params();
    $file_path = sanitize_text_field( $params['file'] ?? '' );
    if ( strpos($file_path, '..') === false && preg_match('/^(templates|css|js)\//', $file_path) ) {
        $full_path = MDS_RM_PATH . $file_path;
        if ( file_exists($full_path) ) {
            file_put_contents($full_path, wp_unslash($params['content']));
            mds_rm_purge_cache();
            return rest_ensure_response( array( 'success' => true, 'message' => 'File updated successfully.' ) );
        }
    }
    return new WP_Error( 'file_not_found', 'Invalid file path.', array( 'status' => 404 ) );
}

function mds_rm_detect_plugins() {
    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $all_plugins = get_plugins();
    $data = array( 'fa_plugins' => array(), 'audio_plugins' => array(), 'bandcamp_detected' => false, 'official_fa_path' => '' );
    foreach ( $all_plugins as $path => $plugin ) {
        if ( ! is_plugin_active( $path ) && ! is_plugin_active_for_network( $path ) ) continue;
        if ( stripos( $plugin['Name'], 'font-awesome' ) !== false || stripos( $plugin['Name'], 'fontawesome' ) !== false || stripos( $plugin['Name'], 'Font Awesome' ) !== false ) {
            $data['fa_plugins'][$path] = $plugin;
            if ( stripos( $plugin['Name'], 'Font Awesome' ) !== false ) $data['official_fa_path'] = $path;
        }
        if ( stripos( $plugin['Name'], 'audio' ) !== false || stripos( $plugin['Name'], 'player' ) !== false ) {
            $data['audio_plugins'][$path] = $plugin;
        }
        if ( stripos( $plugin['Name'], 'blocks for bandcamp' ) !== false ) {
            $data['bandcamp_detected'] = true;
        }
    }
    return $data;
}

function mds_rm_settings_page() {
    $detected = mds_rm_detect_plugins();
    
    // --- INTEGRATIONS: Font Awesome ---
    $fa_support = get_option( 'mds_rm_fa_support', array( 'internal' ) );
    $fa_support_html = '<label style="display:block; margin-bottom:10px;"><input type="checkbox" name="mds_rm_fa_support[]" value="internal" ' . checked( in_array( 'internal', (array)$fa_support ), true, false ) . ' /> <strong>Internal Font-Awesome Support</strong> (Default built-in)</label>';
    foreach ( $detected['fa_plugins'] as $path => $plugin ) {
        $fa_support_html .= '<label style="display:block; margin-bottom:10px;"><input type="checkbox" name="mds_rm_fa_support[]" value="' . esc_attr( $path ) . '" ' . checked( in_array( $path, (array)$fa_support ), true, false ) . ' /> Detected: ' . esc_html( $plugin['Name'] ) . ' by ' . esc_html( $plugin['AuthorName'] ) . '</label>';
    }

    // --- INTEGRATIONS: WooCommerce Sync ---
    $is_woo_active = class_exists( 'WooCommerce' );
    $sites = function_exists('get_sites') ? get_sites() : array();
    $selected_sites = get_option( 'mds_rm_woo_sites', array() );
    $woo_sites_html = '';
    foreach ( $sites as $site ) {
        $checked = ( ! $is_woo_active && in_array( $site->blog_id, (array) $selected_sites ) ) ? 'checked' : '';
        $disabled = $is_woo_active ? 'disabled' : '';
        $site_details = get_blog_details( $site->blog_id );
        $woo_sites_html .= '<label style="display:block; margin-bottom: 5px;">';
        $woo_sites_html .= '<input type="checkbox" name="mds_rm_woo_sites[]" value="' . esc_attr( $site->blog_id ) . '" ' . $checked . ' ' . $disabled . '> ';
        $woo_sites_html .= esc_html( $site_details->blogname ) . ' <span style="color:#777;">(' . esc_html( $site->domain . $site->path ) . ')</span>';
        $woo_sites_html .= '</label>';
    }

    // --- SHORTCODE: Providers ---
    $providers = get_option( 'mds_rm_providers', array(
        array( 'name' => 'Spotify', 'icon' => 'fab fa-spotify', 'color' => '#1DB954' ),
        array( 'name' => 'Apple Music', 'icon' => 'fab fa-apple', 'color' => '#FA243C' )
    ));
    $providers_html = '';
    foreach ( $providers as $provider ) {
        $providers_html .= '<div class="mds-provider-row" style="display:flex; gap:10px; align-items:center; margin-bottom:10px; background:#f9f9f9; padding:10px; border:1px solid #ddd;">
            <span class="dashicons dashicons-menu" style="cursor:move; color:#999;"></span>
            <input type="text" name="mds_rm_providers[name][]" value="' . esc_attr( $provider['name'] ) . '" placeholder="Provider Name" />
            <div class="mds-icon-input-wrapper">
                <input type="text" name="mds_rm_providers[icon][]" value="' . esc_attr( $provider['icon'] ) . '" placeholder="fa-brands fa-spotify" class="mds-icon-input" />
            </div>
            <input type="text" name="mds_rm_providers[color][]" value="' . esc_attr( $provider['color'] ) . '" class="mds-color-picker" />
            <button type="button" class="button mds-remove-provider" style="color:#d63638; border-color:#d63638;">Remove</button>
        </div>';
    }

    // --- SHORTCODE: Audio Players ---
    $audio_players = get_option( 'mds_rm_audio_players', array() );
    $audio_players_html = '<label style="display:block; margin-bottom:5px;"><input type="checkbox" name="mds_rm_audio_players[]" value="native" class="mds-audio-player-cb" ' . checked( in_array( 'native', (array)$audio_players ), true, false ) . ' /> Native HTML5 Audio Player</label>';
    if ( $detected['bandcamp_detected'] ) {
        $audio_players_html .= '<label style="display:block; margin-bottom:5px;"><input type="checkbox" name="mds_rm_audio_players[]" value="blocks_for_bandcamp" class="mds-audio-player-cb" id="mds_cb_bandcamp" ' . checked( in_array( 'blocks_for_bandcamp', (array)$audio_players ), true, false ) . ' /> Blocks for Bandcamp</label>';
    }
    foreach ( $detected['audio_plugins'] as $path => $plugin ) {
        $audio_players_html .= '<label style="display:block; margin-bottom:5px;"><input type="checkbox" name="mds_rm_audio_players[]" value="' . esc_attr( $path ) . '" class="mds-audio-player-cb" ' . checked( in_array( $path, (array)$audio_players ), true, false ) . ' /> ' . esc_html( $plugin['Name'] ) . '</label>';
    }

    // --- TEMPLATES: Files ---
    $files = array(
        'templates/shortcode-widget.html',
        'templates/woo-meta-widget.html',
        'templates/artist-meta-box.html',
        'templates/release-meta-box.html',
        'templates/settings-page.html',
        'templates/csv-page.html',
        'css/frontend.css',
        'css/admin-styles.css',
        'js/settings.js',
        'js/meta-boxes.js'
    );
    $file_options_html = '';
    
    foreach ( $files as $file ) {
        $file_options_html .= '<option value="' . esc_attr($file) . '">' . esc_html($file) . '</option>';
    }

    $tokens = array(
        'providers_html'          => $providers_html,
        'audio_players_html'      => $audio_players_html,
        'bandcamp_template'       => esc_attr( get_option( 'mds_rm_bandcamp_template', '[bandcamp width=100% height=42 album={release_number} size=small bgcol=ffffff linkcol=0687f5 artwork=none]' ) ),
        
        'woo_sites_html'          => $woo_sites_html,
        'global_redirect_checked' => checked( get_option('mds_rm_global_redirect', '0'), '1', false ),
        'fa_support_html'         => $fa_support_html,

        'links_color'             => esc_attr( get_option( 'mds_rm_links_color', '#333333' ) ),
        'links_bg'                => esc_attr( get_option( 'mds_rm_links_bg', 'transparent' ) ),
        'links_icon_bg'           => esc_attr( get_option( 'mds_rm_links_icon_bg', 'transparent' ) ),
        'links_radius'            => esc_attr( get_option( 'mds_rm_links_radius', '0px' ) ),
        'links_gap'               => esc_attr( get_option( 'mds_rm_links_gap', '15px' ) ),
        'links_padding'           => esc_attr( get_option( 'mds_rm_links_padding', '0px' ) ),
        'links_align_start'       => selected(get_option('mds_rm_links_align', 'flex-start'), 'flex-start', false),
        'links_align_center'      => selected(get_option('mds_rm_links_align'), 'center', false),
        'links_align_end'         => selected(get_option('mds_rm_links_align'), 'flex-end', false),
        
        'meta_color'              => esc_attr( get_option( 'mds_rm_meta_color', '#ffffff' ) ),
        'meta_bg'                 => esc_attr( get_option( 'mds_rm_meta_bg', '#000000' ) ),
        'meta_radius'             => esc_attr( get_option( 'mds_rm_meta_radius', '0px' ) ),
        'meta_gap'                => esc_attr( get_option( 'mds_rm_meta_gap', '15px' ) ),
        'meta_padding'            => esc_attr( get_option( 'mds_rm_meta_padding', '12px 18px' ) ),
        'meta_align_start'        => selected(get_option('mds_rm_meta_align', 'flex-start'), 'flex-start', false),
        'meta_align_center'       => selected(get_option('mds_rm_meta_align'), 'center', false),
        'meta_align_end'          => selected(get_option('mds_rm_meta_align'), 'flex-end', false),
        
        'buy_now_icon'            => esc_attr( get_option( 'mds_rm_buy_now_icon', 'fas fa-cart-shopping' ) ),
        'buy_now_color'           => esc_attr( get_option( 'mds_rm_buy_now_color', '#ffffff' ) ),
        'buy_now_bg'              => esc_attr( get_option( 'mds_rm_buy_now_bg', '#000000' ) ),
        
        'file_options_html'       => $file_options_html,
    );

    echo mds_render_template( 'templates/settings-page.html',
        'templates/csv-page.html', $tokens );
}

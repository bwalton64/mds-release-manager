<?php
declare(strict_types=1);

/**
 * Admin Interface Functions
 *
 * @package MDS_Release_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin interface functionality for the release manager.
 *
 * @package MDS_Release_Manager
 * @since 0.9.0
 */
final class MDS_RM_Admin_Interface {

	/**
	 * Register admin hooks for release and artist management.
	 *
	 * @since 0.9.0
	 * @return void
	 */
	public static function register_hooks(): void {
		add_filter( 'manage_release_posts_columns', array( self::class, 'add_featured_image_column' ), 10 );
		add_filter( 'manage_release_posts_columns', array( self::class, 'order_release_columns' ), 99 );
		add_filter( 'manage_edit-release_sortable_columns', array( self::class, 'add_sortable_release_taxonomy_columns' ) );
		add_filter( 'manage_artist_posts_columns', array( self::class, 'add_featured_image_column' ), 10 );
		add_action( 'pre_get_posts', array( self::class, 'prepare_release_taxonomy_sorting' ) );
		add_filter( 'posts_orderby', array( self::class, 'order_release_taxonomy_posts' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( self::class, 'render_release_taxonomy_filters' ), 10, 2 );
		add_action( 'manage_release_posts_custom_column', array( self::class, 'render_featured_image_column' ), 5, 2 );
		add_action( 'manage_artist_posts_custom_column', array( self::class, 'render_featured_image_column' ), 5, 2 );
		add_action( 'add_meta_boxes', array( self::class, 'add_meta_boxes' ), 10, 2 );
		add_action( 'save_post_artist', array( self::class, 'save_artist_meta_data' ) );
		add_action( 'admin_init', array( self::class, 'remove_legacy_artist_images' ) );
		add_action( 'save_post_release', array( self::class, 'save_release_meta_data' ) );
		add_action( 'admin_menu', array( self::class, 'register_settings_page' ) );
	}

	/**
	 * Remove deprecated artist image URLs in bounded batches on each site.
	 *
	 * @return void
	 */
	public static function remove_legacy_artist_images(): void {
		if ( ! current_user_can( 'manage_options' ) || '1' === get_option( 'mds_rm_artist_image_cleanup_complete', '0' ) ) {
			return;
		}
		$artists = get_posts(
			array(
				'post_type'      => 'artist',
				'post_status'    => array_keys( get_post_stati() ),
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'meta_key'       => 'artist_featured_image',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		foreach ( $artists as $artist_id ) {
			if ( ! delete_post_meta( $artist_id, 'artist_featured_image' ) ) {
				error_log( '[MDS Release Manager] Could not remove deprecated image URL from artist ' . $artist_id );
				return;
			}
		}
		if ( count( $artists ) < 100 ) {
			update_option( 'mds_rm_artist_image_cleanup_complete', '1', false );
		}
	}


	/**
	 * Adds the image column to release and artist listings.
	 *
	 * @since 0.9.0
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string> The updated columns.
	 */
	public static function add_featured_image_column( array $columns ): array {
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
	 * Sets the column order for release listings.
	 *
	 * @since 0.9.0
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string> The reordered columns.
	 */
	public static function order_release_columns( array $columns ): array {
		$column_order = array( 'cb', 'mds_thumb', 'title', 'taxonomy-release_tag', 'taxonomy-release_format', 'date' );
		$ordered      = array();

		foreach ( $column_order as $column ) {
			if ( isset( $columns[ $column ] ) ) {
				$ordered[ $column ] = $columns[ $column ];
			}
		}

		foreach ( $columns as $key => $title ) {
			if ( ! in_array( $key, $column_order, true ) ) {
				$ordered[ $key ] = $title;
			}
		}

		return $ordered;
	}

	/**
	 * Makes release taxonomy columns sortable.
	 *
	 * @since 0.9.0
	 * @param array<string, string> $columns Existing sortable columns.
	 * @return array<string, string> The updated sortable columns.
	 */
	public static function add_sortable_release_taxonomy_columns( array $columns ): array {
		$columns['taxonomy-release_tag']    = 'release_tag';
		$columns['taxonomy-release_format'] = 'release_format';

		return $columns;
	}

	/**
	 * Marks taxonomy sorting requests for release list queries.
	 *
	 * @since 0.9.0
	 * @param WP_Query $query The query being modified.
	 * @return void
	 */
	public static function prepare_release_taxonomy_sorting( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || 'release' !== $query->get( 'post_type' ) ) {
			return;
		}

		$taxonomy = $query->get( 'orderby' );
		if ( ! in_array( $taxonomy, array( 'release_tag', 'release_format' ), true ) ) {
			return;
		}

		$query->set( 'mds_rm_sort_taxonomy', $taxonomy );
		$query->set( 'orderby', 'title' );
	}

	/**
	 * Sorts release list queries by the selected taxonomy term name.
	 *
	 * @since 0.9.0
	 * @param string   $orderby The existing ORDER BY clause.
	 * @param WP_Query $query   The query being modified.
	 * @return string The updated ORDER BY clause.
	 */
	public static function order_release_taxonomy_posts( string $orderby, WP_Query $query ): string {
		$taxonomy = $query->get( 'mds_rm_sort_taxonomy' );
		if ( ! in_array( $taxonomy, array( 'release_tag', 'release_format' ), true ) ) {
			return $orderby;
		}

		global $wpdb;

		$term_name = $wpdb->prepare(
			"( SELECT MIN( mds_rm_terms.name ) FROM {$wpdb->term_relationships} AS mds_rm_relationships INNER JOIN {$wpdb->term_taxonomy} AS mds_rm_taxonomy ON mds_rm_relationships.term_taxonomy_id = mds_rm_taxonomy.term_taxonomy_id INNER JOIN {$wpdb->terms} AS mds_rm_terms ON mds_rm_taxonomy.term_id = mds_rm_terms.term_id WHERE mds_rm_relationships.object_id = {$wpdb->posts}.ID AND mds_rm_taxonomy.taxonomy = %s )",
			$taxonomy
		);
		$direction = 'DESC' === strtoupper( $query->get( 'order' ) ) ? 'DESC' : 'ASC';

		return $term_name . ' ' . $direction . ( '' !== $orderby ? ', ' . $orderby : '' );
	}

	/**
	 * Displays taxonomy filters on the release list table.
	 *
	 * @since 0.9.0
	 * @param string $post_type The post type being displayed.
	 * @param string $which     The location of the filter controls.
	 * @return void
	 */
	public static function render_release_taxonomy_filters( string $post_type, string $which ): void {
		if ( 'release' !== $post_type || 'top' !== $which ) {
			return;
		}

		wp_nonce_field( 'mds_rm_filter_releases', '_mds_rm_filter_nonce', false );
		$has_valid_nonce = isset( $_GET['_mds_rm_filter_nonce'] )
			&& is_string( $_GET['_mds_rm_filter_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_mds_rm_filter_nonce'] ) ), 'mds_rm_filter_releases' );

		foreach ( array( 'release_tag', 'release_format' ) as $taxonomy ) {
			$taxonomy_object = get_taxonomy( $taxonomy );
			if ( ! $taxonomy_object ) {
				continue;
			}

			$selected = $has_valid_nonce && isset( $_GET[ $taxonomy ] ) && is_string( $_GET[ $taxonomy ] )
				? sanitize_text_field( wp_unslash( $_GET[ $taxonomy ] ) )
				: '';

			wp_dropdown_categories(
				array(
					'show_option_all' => sprintf( 'All %s', $taxonomy_object->labels->name ),
					'taxonomy'        => $taxonomy,
					'name'            => $taxonomy,
					'id'              => 'filter-by-' . $taxonomy,
					'selected'        => $selected,
					'hierarchical'    => $taxonomy_object->hierarchical,
					'orderby'         => 'name',
					'hide_empty'      => false,
					'value_field'     => 'slug',
				)
			);
		}
	}

	/**
	 * Renders the featured image column.
	 *
	 * @since 0.9.0
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public static function render_featured_image_column( string $column, int $post_id ): void {
		if ( 'mds_thumb' === $column ) {
			echo get_the_post_thumbnail( $post_id, array( 50, 50 ) );
		}
	}


	/**
	 * Registers the release and artist meta boxes.
	 *
	 * @since 0.9.0
	 * @param string  $post_type Post type.
	 * @param WP_Post $post      Post object.
	 * @return void
	 */
	public static function add_meta_boxes( string $post_type, WP_Post $post ): void {
		if ( 'release' === $post_type ) {
			add_meta_box( 'mds_release_settings', 'Release Settings', array( self::class, 'render_release_settings_box' ), 'release', 'normal', 'high' );
		}

		if ( 'artist' === $post_type ) {
			add_meta_box( 'mds_artist_settings', 'Artist Settings', array( self::class, 'render_artist_settings_box' ), 'artist', 'normal', 'high' );
		}

		/**
		 * Hook: mds_rm_register_custom_meta_boxes
		 * Allows feature plugins to register custom meta boxes to the CPTs.
		 */
		do_action( 'mds_rm_register_custom_meta_boxes', $post );
	}

	/**
	 * Renders the artist settings meta box.
	 *
	 * @since 0.9.0
	 * @param WP_Post $post The post object.
	 * @return void
	 */
	public static function render_artist_settings_box( WP_Post $post ): void {
		$contact = get_post_meta( $post->ID, 'artist_contact', true );

		ob_start();
		wp_editor(
			wp_kses_post( ! empty( $contact ) ? $contact : '' ),
			'artist_contact',
			array(
				'media_buttons' => false,
				'textarea_rows' => 5,
			)
		);
		$editor_html = ob_get_clean();

		$args = array(
			'nonce_field'      => wp_nonce_field( 'mds_save_artist_data', 'mds_artist_meta_nonce', true, false ),
			'editor_html'      => $editor_html,
			'homepage_url'     => get_post_meta( $post->ID, 'artist_homepage_url', true ),
			'homepage_routing' => '1' === get_post_meta( $post->ID, 'artist_homepage_routing', true ),
			'post_id'          => $post->ID,
		);
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The template escapes values and emits WordPress-generated HTML.
		echo MDS_RM_Template_Loader::render_admin_view( 'artist-meta-box.php', $args );
	}

	/**
	 * Renders the release settings meta box.
	 *
	 * @since 0.9.0
	 * @param WP_Post $post The post object.
	 * @return void
	 */
	public static function render_release_settings_box( WP_Post $post ): void {
		$artist_id = get_post_meta( $post->ID, 'artist_id', true );
		if ( ! $artist_id ) {
			$artist_id = '';
		}

		$artist_posts = get_posts(
			array(
				'post_type'   => 'artist',
				'numberposts' => -1,
				'post_status' => 'any',
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);

		$artists = array();
		foreach ( $artist_posts as $artist ) {
			$a_uuid    = get_post_meta( $artist->ID, '_mds_artist_uuid', true );
			$artists[] = array(
				'uuid'  => $a_uuid,
				'title' => $artist->post_title,
			);
		}

		$global_providers = get_option( 'mds_rm_providers', array() );
		$global_providers = is_array( $global_providers ) ? $global_providers : array();
		$provider_names   = array();
		foreach ( $global_providers as $gp ) {
			if ( ! isset( $gp['name'] ) ) {
				continue;
			}

			$provider_names[] = $gp['name'];
		}

		$streaming_links_value = get_post_meta( $post->ID, 'streaming_links', true );
		$streaming_links_json  = empty( $streaming_links_value ) ? '[]' : $streaming_links_value;
		$streaming_links       = json_decode( $streaming_links_json, true );
		if ( ! is_array( $streaming_links ) ) {
			$streaming_links = array();
		}

		$upc_ean           = get_post_meta( $post->ID, 'upc_ean', true );
		$record_label      = get_post_meta( $post->ID, 'record_label', true );
		$distributor       = get_post_meta( $post->ID, 'distributor', true );
		$buy_now_link      = get_post_meta( $post->ID, 'buy_now_link', true );
		$audio_preview_url = get_post_meta( $post->ID, 'audio_preview_url', true );

		if ( empty( $upc_ean ) ) {
			$upc_ean = '';
		}
		if ( empty( $record_label ) ) {
			$record_label = '';
		}
		if ( empty( $distributor ) ) {
			$distributor = '';
		}
		if ( empty( $buy_now_link ) ) {
			$buy_now_link = '';
		}
		if ( empty( $audio_preview_url ) ) {
			$audio_preview_url = '';
		}

		$release_formats = wp_get_post_terms( $post->ID, 'release_format', array( 'fields' => 'ids' ) );

		$all_formats = get_terms(
			array(
				'taxonomy'   => 'release_format',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);

		$args = array(
			'nonce_field'       => wp_nonce_field( 'mds_save_release_data', 'mds_release_meta_nonce', true, false ),
			'post'              => $post,
			'artists'           => $artists,
			'artists_found'     => ! empty( $artist_posts ),
			'artist_id'         => $artist_id,
			'providers'         => $provider_names,
			'streaming_links'   => $streaming_links,
			'upc_ean'           => $upc_ean,
			'record_label'      => $record_label,
			'distributor'       => $distributor,
			'buy_now_link'      => $buy_now_link,
			'audio_preview_url' => $audio_preview_url,
			'release_formats'   => $release_formats,
			'all_formats'       => $all_formats,
			'format_conflict'   => MDS_RM_Release_Model::has_legacy_format_conflict( $post->ID ),
		);

    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The template escapes values and emits WordPress-generated HTML.
		echo MDS_RM_Template_Loader::render_admin_view( 'release-meta-box.php', $args );
	}

	/**
	 * Save Artist Meta Data.
	 *
	 * @since 0.9.0
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function save_artist_meta_data( int $post_id ): void {
		if ( is_multisite() && get_current_blog_id() !== (int) get_site_option( 'mds_rm_master_blog_id', get_main_site_id() ) ) {
			return;
		}

		// Verify nonce first
		$nonce = isset( $_POST['mds_artist_meta_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['mds_artist_meta_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mds_save_artist_data' ) ) {
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

		if ( isset( $_POST['artist_contact'] ) && is_string( $_POST['artist_contact'] ) ) {
			$artist_contact = wp_kses_post( wp_unslash( $_POST['artist_contact'] ) );
			update_post_meta( $post_id, 'artist_contact', $artist_contact );
		}

		if ( isset( $_POST['artist_homepage_url'] ) && is_string( $_POST['artist_homepage_url'] ) ) {
			update_post_meta( $post_id, 'artist_homepage_url', esc_url_raw( wp_unslash( $_POST['artist_homepage_url'] ) ) );
		}

		update_post_meta( $post_id, 'artist_homepage_routing', isset( $_POST['artist_homepage_routing'] ) ? '1' : '0' );
		delete_post_meta( $post_id, 'artist_featured_image' );

		$sync_result = MDS_RM_Sync::broadcast_to_spokes( $post_id, 'artist' );

		/**
		 * Hook: mds_rm_save_custom_meta
		 * Fires after core artist meta is saved.
		 *
		 * @param int   $post_id Post ID.
		 * @param array $_POST   Sanitized POST data.
		 */
		do_action( 'mds_rm_save_custom_meta', $post_id, $_POST );

		MDS_RM_Sync::purge_cache();
		if ( is_wp_error( $sync_result ) ) {
			wp_die(
				esc_html(
					sprintf(
						/* translators: %s: synchronization error message */
						__( 'The artist was saved, but synchronization failed: %s', 'mds-release-manager' ),
						$sync_result->get_error_message()
					)
				),
				'',
				array(
					'response'  => 500,
					'back_link' => true,
				)
			);
		}
	}

	/**
	 * Save Release Meta Data.
	 *
	 * @since 0.9.0
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function save_release_meta_data( int $post_id ): void {
		if ( is_multisite() && get_current_blog_id() !== (int) get_site_option( 'mds_rm_master_blog_id', get_main_site_id() ) ) {
			return;
		}

		// Verify nonce first
		$nonce = isset( $_POST['mds_release_meta_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['mds_release_meta_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mds_save_release_data' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['upc_ean'] ) && is_string( $_POST['upc_ean'] ) ) {
			$previous_upc = get_post_meta( $post_id, 'upc_ean', true );
			$upc_clean    = preg_replace( '/[^0-9]/', '', sanitize_text_field( wp_unslash( $_POST['upc_ean'] ) ) );
			update_post_meta( $post_id, 'upc_ean', $upc_clean );
			MDS_RM_Release_Model::remember_previous_upc( $post_id, $previous_upc, $upc_clean );
		}

		if ( isset( $_POST['artist_id'] ) && is_string( $_POST['artist_id'] ) ) {
			update_post_meta( $post_id, 'artist_id', sanitize_text_field( wp_unslash( $_POST['artist_id'] ) ) );
		}

		if ( isset( $_POST['record_label'] ) && is_string( $_POST['record_label'] ) ) {
			update_post_meta( $post_id, 'record_label', sanitize_text_field( wp_unslash( $_POST['record_label'] ) ) );
		}

		if ( isset( $_POST['distributor'] ) && is_string( $_POST['distributor'] ) ) {
			update_post_meta( $post_id, 'distributor', sanitize_text_field( wp_unslash( $_POST['distributor'] ) ) );
		}

		if ( isset( $_POST['buy_now_link'] ) && is_string( $_POST['buy_now_link'] ) ) {
			update_post_meta( $post_id, 'buy_now_link', esc_url_raw( wp_unslash( $_POST['buy_now_link'] ) ) );
		}

		if ( isset( $_POST['audio_preview_url'] ) && is_string( $_POST['audio_preview_url'] ) ) {
			$audio_preview_url = sanitize_text_field( wp_unslash( $_POST['audio_preview_url'] ) );
			update_post_meta( $post_id, 'audio_preview_url', esc_url_raw( $audio_preview_url ) );
		}

		$providers           = isset( $_POST['mds_streaming_provider'] ) && is_array( $_POST['mds_streaming_provider'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['mds_streaming_provider'] ) ) : array();
		$urls                = isset( $_POST['mds_streaming_url'] ) && is_array( $_POST['mds_streaming_url'] ) ? array_map( 'esc_url_raw', wp_unslash( $_POST['mds_streaming_url'] ) ) : array();
		$streaming_links_arr = array();

		$count = count( $providers );
		for ( $i = 0; $i < $count; $i++ ) {
			$p = $providers[ $i ] ?? '';
			$u = $urls[ $i ] ?? '';
			if ( ! empty( $p ) && ! empty( $u ) ) {
				$streaming_links_arr[ $p ] = $u;
			}
		}
		update_post_meta( $post_id, 'streaming_links', wp_slash( wp_json_encode( $streaming_links_arr ) ) );

		$format_taxonomy = get_taxonomy( 'release_format' );
		if ( $format_taxonomy && current_user_can( $format_taxonomy->cap->assign_terms ) && isset( $_POST['release_format'] ) && is_scalar( $_POST['release_format'] ) ) {
			$result = wp_set_post_terms( $post_id, array( absint( wp_unslash( $_POST['release_format'] ) ) ), 'release_format' );
			if ( is_wp_error( $result ) ) {
				wp_die( esc_html( $result->get_error_message() ) );
			}
			delete_post_meta( $post_id, 'release_format' );
		}

		$sync_result = MDS_RM_Sync::broadcast_to_spokes( $post_id, 'release' );
		MDS_RM_Release_Model::release_upc_lock( get_post_meta( $post_id, 'upc_ean', true ) );

		/**
		 * Hook: mds_rm_save_custom_meta
		 * Fires after core release meta is saved.
		 *
		 * @param int   $post_id Post ID.
		 * @param array $_POST   Sanitized POST data.
		 */
		do_action( 'mds_rm_save_custom_meta', $post_id, $_POST );

		MDS_RM_Sync::purge_cache();
		if ( is_wp_error( $sync_result ) ) {
			wp_die(
				esc_html(
					sprintf(
						/* translators: %s: synchronization error message */
						__( 'The release was saved, but synchronization failed: %s', 'mds-release-manager' ),
						$sync_result->get_error_message()
					)
				),
				'',
				array(
					'response'  => 500,
					'back_link' => true,
				)
			);
		}
	}

	/**
	 * Registers the release settings page.
	 *
	 * @since 0.9.0
	 * @return void
	 */
	public static function register_settings_page(): void {
		add_submenu_page(
			'edit.php?post_type=release',
			'Release Settings',
			'Settings',
			'manage_options',
			'mds-release-settings',
			array( self::class, 'settings_page' )
		);
	}

	/**
	 * Renders the release settings page.
	 *
	 * @since 0.9.0
	 * @return void
	 */
	public static function settings_page(): void {
		$global_providers = get_option( 'mds_rm_providers', array() );
		if ( ! is_array( $global_providers ) ) {
			$global_providers = array();
		}

		$audio_players = array(
			'html5' => __( 'HTML5 Player (.mp3)', 'mds-release-manager' ),
		);

		$active_players = get_option( 'mds_rm_audio_players', array( 'html5' ) );
		$active_players = is_array( $active_players ) ? $active_players : array();

		$fa_sources = array(
			'cdn'    => __( 'Load Free Font-Awesome 6 via CDN', 'mds-release-manager' ),
			'plugin' => __( 'Use existing plugin (Font Awesome Official)', 'mds-release-manager' ),
			'theme'  => __( 'Use theme provided Font-Awesome', 'mds-release-manager' ),
			'none'   => __( 'Do not load Font-Awesome', 'mds-release-manager' ),
		);

		$args = array(
			'providers'                  => $global_providers,
			'audio_players'              => $audio_players,
			'active_players'             => $active_players,
			'fa_sources'                 => $fa_sources,
			'active_fa_source'           => get_option( 'mds_rm_fa_support', 'cdn' ),
			'audio_player_custom_fields' => apply_filters( 'mds_rm_settings_audio_fields', '' ),
			'settings'                   => array(
				'bandcamp_template' => get_option( 'mds_rm_bandcamp_template', '[bandcamp width=100% height=42 album={release_number} size=small bgcol=ffffff linkcol=0687f5 artwork=none]' ),
				'global_redirect'   => '1' === (string) get_option( 'mds_rm_global_redirect', '0' ),
			),
		);

    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The template escapes values and renders trusted extension markup.
		echo MDS_RM_Template_Loader::render_admin_view( 'settings-page.php', $args );
	}
}

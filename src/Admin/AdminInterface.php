<?php
/**
 * Admin Interface Functions
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Admin;

use WP_Post;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin interface functionality for the release manager.
 *
 * @package MDS\ReleaseManager
 * @since 0.9.0
 */
final class AdminInterface {

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
				'meta_key'       => 'artist_featured_image', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The migration batches only artists that still have the deprecated field.
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		foreach ( $artists as $artist_id ) {
			if ( ! delete_post_meta( $artist_id, 'artist_featured_image' ) ) {
				\MDS\ReleaseManager\Core\Logger::report_failure( 'Could not remove deprecated image URL from artist ' . $artist_id );
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
		$column_order = array( 'cb', 'mds_thumb', 'title', 'taxonomy-release_type', 'taxonomy-release_media_format', 'taxonomy-release_tag', 'date' );
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
		$columns['taxonomy-release_tag']          = 'release_tag';
		$columns['taxonomy-release_type']         = 'release_type';
		$columns['taxonomy-release_media_format'] = 'release_media_format';

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
		if ( ! in_array( $taxonomy, array( 'release_tag', 'release_type', 'release_media_format' ), true ) ) {
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
		if ( ! in_array( $taxonomy, array( 'release_tag', 'release_type', 'release_media_format' ), true ) ) {
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

		foreach ( array( 'release_type', 'release_media_format', 'release_tag' ) as $taxonomy ) {
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
			add_meta_box( 'mds_release_identity', __( 'Release Manager', 'mds-release-manager' ), array( self::class, 'render_release_workspace_box' ), 'release', 'normal', 'high' );
		}

		if ( 'artist' === $post_type ) {
			add_meta_box( 'mds_artist_settings', __( 'Artist Manager', 'mds-release-manager' ), array( self::class, 'render_artist_settings_box' ), 'artist', 'normal', 'high' );
		}

		/**
		 * Hook: mds_rm_register_custom_meta_boxes
		 * Allows feature plugins to register custom meta boxes to the CPTs.
		 */
		do_action( 'mds_rm_register_custom_meta_boxes', $post );
	}

	/**
	 * Returns serialized client payload for the native React Artist Editor.
	 *
	 * @since 1.1.0
	 * @param WP_Post $post Artist post object.
	 * @return array<string, mixed>
	 */
	public static function get_artist_editor_client_data( WP_Post $post ): array {
		$uuid     = (string) get_post_meta( $post->ID, '_mds_artist_uuid', true );
		$releases = array();

		if ( '' !== $uuid ) {
			$release_posts = get_posts(
				array(
					'post_type'      => 'release',
					'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
					'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Bounded artist release routing table.
					'meta_key'       => 'artist_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- List releases belonging to this Artist.
					'meta_value'     => $uuid, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'orderby'        => array(
						'date' => 'DESC',
						'ID'   => 'DESC',
					),
					'no_found_rows'  => true,
				)
			);
			if ( ! empty( $release_posts ) ) {
				$release_ids = array_values( array_map( static fn( WP_Post $r ): int => (int) $r->ID, $release_posts ) );
				/**
				 * Fires before per-release routing destinations are resolved for the Artist editor table.
				 *
				 * Extensions can batch-prime SKU/product or external URL lookups here to avoid N+1 queries.
				 *
				 * @since 2.0.0
				 * @param int[] $release_ids Release post IDs in the Artist routing table.
				 * @param int   $artist_id   Artist post ID.
				 */
				do_action( 'mds_rm_prime_routing_destinations', $release_ids, (int) $post->ID );
			}
			foreach ( $release_posts as $release ) {
				$own_mode   = \MDS\ReleaseManager\Frontend\ArtistRouting::sanitize_release_mode( get_post_meta( $release->ID, 'release_routing_mode', true ) );
				$custom_url = (string) get_post_meta( $release->ID, 'release_routing_url', true );
				$thumb_url  = (string) get_the_post_thumbnail_url( $release->ID, 'thumbnail' );
				$releases[] = array(
					'id'        => (int) $release->ID,
					'title'     => get_the_title( $release ) ? get_the_title( $release ) : __( '(no title)', 'mds-release-manager' ),
					'editUrl'   => (string) get_edit_post_link( $release->ID, 'raw' ),
					'upc'       => (string) get_post_meta( $release->ID, 'upc_ean', true ),
					'thumbUrl'  => $thumb_url,
					'ownMode'   => $own_mode,
					'customUrl' => $custom_url,
					'preview'   => \MDS\ReleaseManager\Frontend\ArtistRouting::describe_release_destination( $release->ID, $post->ID ),
				);
			}
		}

		$artist_modes = array();
		foreach ( \MDS\ReleaseManager\Frontend\ArtistRouting::artist_release_modes() as $slug => $label ) {
			$artist_modes[] = array(
				'value' => (string) $slug,
				'label' => (string) $label,
			);
		}

		$release_modes = array();
		foreach ( \MDS\ReleaseManager\Frontend\ArtistRouting::release_modes() as $slug => $label ) {
			$release_modes[] = array(
				'value' => (string) $slug,
				'label' => (string) $label,
			);
		}

		return array(
			'postId'                    => (int) $post->ID,
			'restBase'                  => esc_url_raw( rest_url( 'mds/v1/' ) ),
			'nonce'                     => wp_create_nonce( 'wp_rest' ),
			'permalink'                 => (string) get_permalink( $post->ID ),
			'homepageUrl'               => (string) get_post_meta( $post->ID, 'artist_homepage_url', true ),
			'homepageRouting'           => '1' === (string) get_post_meta( $post->ID, 'artist_homepage_routing', true ),
			'artistReleasesRoutingMode' => \MDS\ReleaseManager\Frontend\ArtistRouting::sanitize_artist_release_mode( get_post_meta( $post->ID, 'artist_releases_routing_mode', true ) ),
			'artistModes'               => $artist_modes,
			'releaseModes'              => $release_modes,
			'releases'                  => $releases,
			'settingsUrl'               => admin_url( 'edit.php?post_type=release&page=mds-release-settings' ),
		);
	}

	/**
	 * Renders the unified native React Artist Manager meta box.
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
			'nonce_field' => wp_nonce_field( 'mds_save_artist_data', 'mds_artist_meta_nonce', true, false ),
			'editor_html' => $editor_html,
			'post_id'     => $post->ID,
		);
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The template escapes values and emits WordPress-generated HTML.
		echo \MDS\ReleaseManager\Core\TemplateLoader::render_admin_view( 'artist-workspace-box.php', $args );
	}

	/**
	 * Renders the unified React Release Manager meta box.
	 *
	 * @since 1.1.0
	 * @param WP_Post $post The post object.
	 * @return void
	 */
	public static function render_release_workspace_box( WP_Post $post ): void {
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The template escapes values and emits WordPress-generated HTML.
		echo \MDS\ReleaseManager\Core\TemplateLoader::render_admin_view( 'release-workspace-box.php', self::release_box_args( $post ) );
	}

	/**
	 * Returns serialized client payload for the native React Release Editor.
	 *
	 * @since 1.1.0
	 * @param WP_Post $post The release post being edited.
	 * @return array<string, mixed>
	 */
	public static function get_release_editor_client_data( WP_Post $post ): array {
		$args = self::release_box_args( $post );

		$release_types = array();
		if ( is_array( $args['all_formats'] ) ) {
			foreach ( $args['all_formats'] as $term ) {
				if ( 0 !== (int) $term->parent || ! in_array( $term->name, \MDS\ReleaseManager\Core\ReleaseModel::MDS_RM_FORMATS, true ) ) {
					continue;
				}
				$release_types[] = array(
					'id'   => (int) $term->term_id,
					'name' => (string) $term->name,
				);
			}
		}

		$selected_type = 0;
		if ( empty( $args['format_conflict'] ) && is_array( $args['release_formats'] ) && 1 === count( $args['release_formats'] ) ) {
			$selected_type = (int) $args['release_formats'][0];
		}

		$media_formats = array();
		foreach ( (array) $args['all_media_formats'] as $term ) {
			$media_formats[] = array(
				'slug' => (string) $term->slug,
				'name' => (string) $term->name,
			);
		}

		$streaming_rows = array();
		foreach ( (array) $args['streaming_links'] as $provider => $url ) {
			$streaming_rows[] = array(
				'provider' => (string) $provider,
				'url'      => (string) $url,
			);
		}

		$current_tags = wp_get_post_terms( $post->ID, 'release_tag', array( 'fields' => 'names' ) );
		if ( is_wp_error( $current_tags ) ) {
			$current_tags = array();
		}
		$all_tags = get_terms(
			array(
				'taxonomy'   => 'release_tag',
				'hide_empty' => false,
				'fields'     => 'names',
				'number'     => 200,
			)
		);
		if ( is_wp_error( $all_tags ) ) {
			$all_tags = array();
		}

		$routing_modes = array();
		foreach ( (array) $args['release_routing_modes'] as $slug => $label ) {
			$routing_modes[] = array(
				'value' => (string) $slug,
				'label' => (string) $label,
			);
		}

		return array(
			'postId'                 => (int) $post->ID,
			'restBase'               => esc_url_raw( rest_url( 'mds/v1/' ) ),
			'nonce'                  => wp_create_nonce( 'wp_rest' ),
			'upcEan'                 => (string) $args['upc_ean'],
			'releaseType'            => $selected_type,
			'releaseTypes'           => $release_types,
			'canAssignTypes'         => current_user_can( get_taxonomy( 'release_type' )->cap->assign_terms ),
			'formatConflict'         => ! empty( $args['format_conflict'] ),
			'artistId'               => (string) $args['artist_id'],
			'artistTitle'            => (string) $args['selected_artist_title'],
			'artistEditUrl'          => (string) $args['selected_artist_edit_url'],
			'artistHomepage'         => (string) $args['artist_homepage_url'],
			'recordLabel'            => (string) $args['record_label'],
			'distributor'            => (string) $args['distributor'],
			'tags'                   => array_values( array_map( 'strval', (array) $current_tags ) ),
			'tagSuggestions'         => array_values( array_map( 'strval', (array) $all_tags ) ),
			'providers'              => array_values( array_map( 'strval', (array) $args['providers'] ) ),
			'streamingLinks'         => $streaming_rows,
			'audioPreviewUrl'        => (string) $args['audio_preview_url'],
			'activeAudioPlayerSlug'  => (string) $args['active_audio_player_slug'],
			'activeAudioPlayerLabel' => (string) $args['active_audio_player_label'],
			'mediaFormats'           => $media_formats,
			'buyNowLinks'            => array_values( (array) $args['buy_now_links'] ),
			'buyNowMode'             => (string) $args['buy_now_mode'],
			'routingMode'            => (string) $args['release_routing_mode'],
			'routingUrl'             => (string) $args['release_routing_url'],
			'routingModes'           => $routing_modes,
			'routingPreviewLabel'    => (string) ( $args['release_routing_preview']['label'] ?? __( 'Release Fallback Page', 'mds-release-manager' ) ),
			'settingsUrl'            => (string) $args['settings_url'],
			'mediaFormatsUrl'        => (string) $args['media_formats_url'],
		);
	}

	/**
	 * Collects the shared template arguments for the release meta boxes.
	 *
	 * The result is cached per post so each box render reuses one lookup.
	 *
	 * @since 1.0.0
	 * @param WP_Post $post The post object.
	 * @return array<string, mixed> The template arguments.
	 */
	private static function release_box_args( WP_Post $post ): array {
		static $cache = array();
		if ( isset( $cache[ $post->ID ] ) ) {
			return $cache[ $post->ID ];
		}

		$artist_id = get_post_meta( $post->ID, 'artist_id', true );
		if ( ! $artist_id ) {
			$artist_id = '';
		}

		$selected_artist_title    = '';
		$selected_artist_post_id  = 0;
		$selected_artist_edit_url = '';
		$artist_homepage_url      = '';
		if ( $artist_id ) {
			$selected_artists = get_posts(
				array(
					'post_type'      => 'artist',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => '_mds_artist_uuid', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Resolve only the currently selected Artist.
					'meta_value'     => $artist_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'no_found_rows'  => true,
				)
			);
			if ( $selected_artists ) {
				$selected_artist_post_id  = (int) $selected_artists[0];
				$selected_artist_title    = get_the_title( $selected_artist_post_id );
				$selected_artist_edit_url = (string) get_edit_post_link( $selected_artist_post_id, 'raw' );
				$artist_homepage_url      = (string) get_post_meta( $selected_artist_post_id, 'artist_homepage_url', true );
			}
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

		$release_formats = wp_get_post_terms( $post->ID, 'release_type', array( 'fields' => 'ids' ) );

		$all_formats = get_terms(
			array(
				'taxonomy'   => 'release_type',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);

		$all_media_formats = get_terms(
			array(
				'taxonomy'   => 'release_media_format',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		if ( is_wp_error( $all_media_formats ) ) {
			$all_media_formats = array();
		}

		$buy_now_links = \MDS\ReleaseManager\Core\ReleaseModel::get_buy_now_links( $post->ID );

		$active_audio_slug  = \MDS\ReleaseManager\Core\AudioPlayers::get_active_player();
		$registered_players = \MDS\ReleaseManager\Core\AudioPlayers::get_registered_players();
		$active_audio_label = isset( $registered_players[ $active_audio_slug ]['label'] )
			? (string) $registered_players[ $active_audio_slug ]['label']
			: '';

		$args = array(
			'nonce_field'               => wp_nonce_field( 'mds_save_release_data', 'mds_release_meta_nonce', true, false ),
			'post'                      => $post,
			'artist_id'                 => $artist_id,
			'selected_artist_title'     => $selected_artist_title,
			'selected_artist_post_id'   => $selected_artist_post_id,
			'selected_artist_edit_url'  => $selected_artist_edit_url,
			'artist_homepage_url'       => $artist_homepage_url,
			'providers'                 => $provider_names,
			'streaming_links'           => $streaming_links,
			'upc_ean'                   => $upc_ean,
			'record_label'              => $record_label,
			'distributor'               => $distributor,
			'buy_now_link'              => $buy_now_link,
			'buy_now_links'             => $buy_now_links,
			'buy_now_mode'              => (string) get_post_meta( $post->ID, 'buy_now_mode', true ),
			'release_routing_mode'      => \MDS\ReleaseManager\Frontend\ArtistRouting::sanitize_release_mode( get_post_meta( $post->ID, 'release_routing_mode', true ) ),
			'release_routing_url'       => (string) get_post_meta( $post->ID, 'release_routing_url', true ),
			'release_routing_modes'     => \MDS\ReleaseManager\Frontend\ArtistRouting::release_modes(),
			'release_routing_preview'   => \MDS\ReleaseManager\Frontend\ArtistRouting::describe_release_destination( $post->ID ),
			'audio_preview_url'         => $audio_preview_url,
			'active_audio_player_slug'  => $active_audio_slug,
			'active_audio_player_label' => $active_audio_label,
			'settings_url'              => admin_url( 'edit.php?post_type=release&page=mds-release-settings' ),
			'media_formats_url'         => admin_url( 'edit-tags.php?taxonomy=release_media_format&post_type=release' ),
			'release_formats'           => $release_formats,
			'all_formats'               => $all_formats,
			'all_media_formats'         => $all_media_formats,
			'format_conflict'           => \MDS\ReleaseManager\Core\ReleaseModel::has_legacy_format_conflict( $post->ID ),
		);

		$cache[ $post->ID ] = $args;

		return $args;
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

		// Verify nonce first. If absent (e.g., Quick Edit, REST API, or WP-CLI), still broadcast changes from the Hub.
		$nonce = isset( $_POST['mds_artist_meta_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['mds_artist_meta_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mds_save_artist_data' ) ) {
			if ( ( ! defined( 'DOING_AUTOSAVE' ) || ! DOING_AUTOSAVE )
				&& ! wp_is_post_revision( $post_id )
				&& 'auto-draft' !== get_post_status( $post_id )
				&& current_user_can( 'edit_post', $post_id )
			) {
				\MDS\ReleaseManager\Core\Sync::broadcast_to_spokes( $post_id, 'artist' );
				\MDS\ReleaseManager\Core\Sync::purge_cache( 'post_save' );
			}
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
		if ( isset( $_POST['artist_releases_routing_mode'] ) && is_string( $_POST['artist_releases_routing_mode'] ) ) {
			update_post_meta(
				$post_id,
				'artist_releases_routing_mode',
				\MDS\ReleaseManager\Frontend\ArtistRouting::sanitize_artist_release_mode( sanitize_text_field( wp_unslash( $_POST['artist_releases_routing_mode'] ) ) )
			);
		}
		delete_post_meta( $post_id, 'artist_featured_image' );

		$artist_post = get_post( $post_id );
		if ( $artist_post instanceof WP_Post ) {
			/**
			 * Fires after core Artist meta is saved and before spoke synchronization.
			 *
			 * Extensions must verify their own nonce and sanitize their own POST fields.
			 *
			 * @since 2.0.0
			 * @param int     $post_id     Artist post ID.
			 * @param WP_Post $artist_post Artist post object.
			 */
			do_action( 'mds_rm_save_artist_meta', $post_id, $artist_post );
		}

		$sync_result = \MDS\ReleaseManager\Core\Sync::broadcast_to_spokes( $post_id, 'artist' );

		\MDS\ReleaseManager\Core\Sync::purge_cache( 'post_save' );
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

		// Verify nonce first. If absent (e.g., Quick Edit, REST API, or WP-CLI), still broadcast changes from the Hub.
		$nonce = isset( $_POST['mds_release_meta_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['mds_release_meta_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mds_save_release_data' ) ) {
			if ( ( ! defined( 'DOING_AUTOSAVE' ) || ! DOING_AUTOSAVE )
				&& ! wp_is_post_revision( $post_id )
				&& 'auto-draft' !== get_post_status( $post_id )
				&& current_user_can( 'edit_post', $post_id )
			) {
				\MDS\ReleaseManager\Core\Sync::broadcast_to_spokes( $post_id, 'release' );
				\MDS\ReleaseManager\Core\Sync::purge_cache( 'post_save' );
			}
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$locked_upc = '';
		try {
			if ( isset( $_POST['upc_ean'] ) && is_string( $_POST['upc_ean'] ) ) {
				$previous_upc = get_post_meta( $post_id, 'upc_ean', true );
				$upc_clean    = preg_replace( '/[^0-9]/', '', sanitize_text_field( wp_unslash( $_POST['upc_ean'] ) ) );
				$locked_upc   = (string) $upc_clean;
				update_post_meta( $post_id, 'upc_ean', $upc_clean );
				\MDS\ReleaseManager\Core\ReleaseModel::remember_previous_upc( $post_id, $previous_upc, $upc_clean );
			} else {
				$locked_upc = (string) get_post_meta( $post_id, 'upc_ean', true );
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

			if ( isset( $_POST['mds_buy_now_url'] ) && is_array( $_POST['mds_buy_now_url'] ) ) {
				$bn_formats   = isset( $_POST['mds_buy_now_format'] ) && is_array( $_POST['mds_buy_now_format'] ) ? array_map( 'sanitize_title', wp_unslash( $_POST['mds_buy_now_format'] ) ) : array();
				$bn_stores    = isset( $_POST['mds_buy_now_store'] ) && is_array( $_POST['mds_buy_now_store'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['mds_buy_now_store'] ) ) : array();
				$bn_urls      = array_map( 'esc_url_raw', wp_unslash( $_POST['mds_buy_now_url'] ) );
				$primary_idx  = isset( $_POST['mds_buy_now_primary'] ) && is_scalar( $_POST['mds_buy_now_primary'] ) ? (int) $_POST['mds_buy_now_primary'] : 0;
				$raw_buy_rows = array();
				$bn_count     = count( $bn_urls );
				for ( $i = 0; $i < $bn_count; $i++ ) {
					$raw_buy_rows[] = array(
						'format'  => $bn_formats[ $i ] ?? '',
						'store'   => $bn_stores[ $i ] ?? '',
						'url'     => $bn_urls[ $i ] ?? '',
						'primary' => ( $i === $primary_idx ),
					);
				}
				$sanitized_buy_links = \MDS\ReleaseManager\Core\ReleaseModel::sanitize_buy_now_links( $raw_buy_rows );
				if ( $sanitized_buy_links ) {
					update_post_meta( $post_id, 'buy_now_links', wp_slash( wp_json_encode( $sanitized_buy_links ) ) );
					$primary_url = '';
					$media_slugs = array();
					foreach ( $sanitized_buy_links as $row ) {
						if ( ! empty( $row['primary'] ) && '' === $primary_url ) {
							$primary_url = $row['url'];
						}
						if ( ! empty( $row['format'] ) ) {
							$media_slugs[] = $row['format'];
						}
					}
					update_post_meta( $post_id, 'buy_now_link', $primary_url ? $primary_url : $sanitized_buy_links[0]['url'] );
					wp_set_object_terms( $post_id, array_values( array_unique( $media_slugs ) ), 'release_media_format', false );
				} else {
					delete_post_meta( $post_id, 'buy_now_links' );
					delete_post_meta( $post_id, 'buy_now_link' );
					wp_set_object_terms( $post_id, array(), 'release_media_format', false );
				}
			} elseif ( isset( $_POST['buy_now_link'] ) && is_string( $_POST['buy_now_link'] ) ) {
				update_post_meta( $post_id, 'buy_now_link', esc_url_raw( wp_unslash( $_POST['buy_now_link'] ) ) );
			}

			if ( isset( $_POST['mds_buy_now_mode'] ) && is_string( $_POST['mds_buy_now_mode'] ) ) {
				$submitted_mode = sanitize_key( wp_unslash( $_POST['mds_buy_now_mode'] ) );
				if ( in_array( $submitted_mode, array( 'direct', 'picker' ), true ) ) {
					update_post_meta( $post_id, 'buy_now_mode', $submitted_mode );
				} else {
					delete_post_meta( $post_id, 'buy_now_mode' );
				}
			}

			if ( isset( $_POST['mds_release_routing_mode'] ) && is_string( $_POST['mds_release_routing_mode'] ) ) {
				$routing_mode = \MDS\ReleaseManager\Frontend\ArtistRouting::sanitize_release_mode( sanitize_text_field( wp_unslash( $_POST['mds_release_routing_mode'] ) ) );
				$routing_url  = isset( $_POST['mds_release_routing_url'] ) && is_string( $_POST['mds_release_routing_url'] )
				? esc_url_raw( trim( wp_unslash( $_POST['mds_release_routing_url'] ) ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw() sanitizes; sanitize_text_field() would strip percent-encoded octets.
				: '';
				if ( 'inherit' === $routing_mode ) {
					delete_post_meta( $post_id, 'release_routing_mode' );
					delete_post_meta( $post_id, 'release_routing_url' );
				} else {
					update_post_meta( $post_id, 'release_routing_mode', $routing_mode );
					if ( 'custom_url' === $routing_mode && '' !== $routing_url ) {
						update_post_meta( $post_id, 'release_routing_url', $routing_url );
					} else {
						delete_post_meta( $post_id, 'release_routing_url' );
					}
				}
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

			$format_taxonomy = get_taxonomy( 'release_type' );
			if ( $format_taxonomy && current_user_can( $format_taxonomy->cap->assign_terms ) && isset( $_POST['release_type'] ) && is_scalar( $_POST['release_type'] ) ) {
				$result = wp_set_post_terms( $post_id, array( absint( wp_unslash( $_POST['release_type'] ) ) ), 'release_type' );
				if ( is_wp_error( $result ) ) {
					wp_die( esc_html( $result->get_error_message() ) );
				}
				delete_post_meta( $post_id, 'release_format' );
			}
		} finally {
			\MDS\ReleaseManager\Core\ReleaseModel::release_upc_lock( $locked_upc );
		}

		$release_post = get_post( $post_id );
		if ( $release_post instanceof WP_Post ) {
			/**
			 * Fires after core Release meta is saved and the UPC lock is released,
			 * before spoke synchronization runs.
			 *
			 * Extensions must verify their own nonce and sanitize their own POST fields.
			 *
			 * @since 2.0.0
			 * @param int     $post_id      Release post ID.
			 * @param WP_Post $release_post Release post object.
			 */
			do_action( 'mds_rm_save_release_meta', $post_id, $release_post );
		}

		$sync_result = \MDS\ReleaseManager\Core\Sync::broadcast_to_spokes( $post_id, 'release' );

		\MDS\ReleaseManager\Core\Sync::purge_cache( 'post_save' );
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

		$audio_players = array();
		foreach ( \MDS\ReleaseManager\Core\AudioPlayers::get_registered_players() as $slug => $player ) {
			$audio_players[ $slug ] = $player['label'];
		}

		$active_player  = \MDS\ReleaseManager\Core\AudioPlayers::get_active_player();
		$active_players = '' !== $active_player ? array( $active_player ) : array();

		$fa_sources  = array(
			'auto'   => __( 'Auto (Recommended — use active Font Awesome plugin if detected, otherwise Public CDN)', 'mds-release-manager' ),
			'cdn'    => __( 'Load Font Awesome Free 6.7.2 via Public CDN (cdnjs)', 'mds-release-manager' ),
			'plugin' => __( 'Use existing Font Awesome plugin', 'mds-release-manager' ),
			'theme'  => __( 'Use theme-provided Font Awesome', 'mds-release-manager' ),
			'none'   => __( 'Do not load Font Awesome', 'mds-release-manager' ),
		);
		$detected_fa = \MDS\ReleaseManager\Frontend\Assets::detect_font_awesome_plugin();

		$args = array(
			'providers'               => $global_providers,
			'audio_players'           => $audio_players,
			'active_players'          => $active_players,
			'active_audio_player'     => $active_player,
			'audio_player_settings'   => \MDS\ReleaseManager\Core\AudioPlayers::get_all_settings(),
			'fa_sources'              => $fa_sources,
			'active_fa_source'        => \MDS\ReleaseManager\Frontend\Assets::sanitize_font_awesome_source( get_option( 'mds_rm_fa_support', 'auto' ) ),
			'detected_fa_plugin'      => $detected_fa,
			'cache_settings'          => \MDS\ReleaseManager\Core\Cache::get_settings(),
			'detected_cache_adapters' => \MDS\ReleaseManager\Core\Cache::detected_adapters(),
			'extension_tabs'          => self::extension_tabs( 'mds_rm_site_settings_tabs', array( 'general-settings', 'default-templates', 'audio-player-manager', 'block-defaults', 'integrations' ) ),
			'block_defaults'          => get_option( 'mds_rm_block_defaults', array() ),
			'template_defaults'       => \MDS\ReleaseManager\Frontend\TemplateDefaults::get_all(),
			'settings'                => array(
				'global_redirect' => '1' === (string) get_option( 'mds_rm_global_redirect', '0' ),
			),
		);

    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The template escapes values and renders trusted extension markup.
		echo \MDS\ReleaseManager\Core\TemplateLoader::render_admin_view( 'settings-page.php', $args );
	}

	/**
	 * Returns valid extension tabs for an administration settings screen.
	 *
	 * Each tab is keyed by a unique slug and has a label and a callable render
	 * function. The callback owns its forms, permissions, and save handlers.
	 *
	 * @param string   $hook     Filter providing the tabs.
	 * @param string[] $reserved Core tab slugs.
	 * @return array Valid tab definitions.
	 */
	public static function extension_tabs( string $hook, array $reserved ): array {
		if ( 'mds_rm_site_settings_tabs' === $hook ) {
			$tabs = apply_filters( 'mds_rm_site_settings_tabs', array() );
		} elseif ( 'mds_rm_network_settings_tabs' === $hook ) {
			$tabs = apply_filters( 'mds_rm_network_settings_tabs', array() );
		} else {
			\MDS\ReleaseManager\Core\Logger::report_failure( 'Unsupported settings tab hook: ' . $hook );
			return array();
		}
		if ( ! is_array( $tabs ) ) {
			\MDS\ReleaseManager\Core\Logger::report_failure( 'Invalid Release Manager settings tabs supplied to ' . $hook );
			return array();
		}
		$valid = array();
		foreach ( $tabs as $slug => $tab ) {
			if ( ! is_string( $slug ) || sanitize_key( $slug ) !== $slug || '' === $slug || in_array( $slug, $reserved, true )
				|| ! is_array( $tab ) || ! isset( $tab['label'], $tab['render'] )
				|| ! is_string( $tab['label'] ) || '' === trim( $tab['label'] ) || ! is_callable( $tab['render'] ) ) {
				\MDS\ReleaseManager\Core\Logger::report_failure( 'Invalid Release Manager settings tab supplied to ' . $hook );
				continue;
			}
			$valid[ $slug ] = $tab;
		}
		return $valid;
	}
}

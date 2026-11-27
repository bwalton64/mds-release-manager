<?php
/**
 * Registers the plugin's post types and taxonomies.
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin's post types and taxonomies.
 *
 * @since 0.9.0
 */
final class PostTypes {

	/**
	 * Registers post-type initialization.
	 *
	 * @since 0.9.0
	 */
	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'register_cpts' ) );
		add_action( 'update_option_' . \MDS\ReleaseManager\Frontend\TemplateDefaults::OPTION_NAME, array( self::class, 'maybe_flush_on_archive_page_change' ), 10, 2 );
		add_action( 'post_updated', array( self::class, 'maybe_flush_on_archive_page_slug_change' ), 10, 3 );
		add_filter( 'display_post_states', array( self::class, 'filter_archive_page_post_state' ), 10, 2 );
	}

	/**
	 * Flushes rewrite rules when the Designated Release Archive Page setting changes.
	 *
	 * @since 2.2.0
	 *
	 * @param mixed $old_value Previous option value.
	 * @param mixed $new_value Updated option value.
	 */
	public static function maybe_flush_on_archive_page_change( mixed $old_value, mixed $new_value ): void {
		$old_id = is_array( $old_value ) ? absint( $old_value['archive']['page_id'] ?? 0 ) : 0;
		$new_id = is_array( $new_value ) ? absint( $new_value['archive']['page_id'] ?? 0 ) : 0;
		if ( $old_id !== $new_id ) {
			self::register_cpts();
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Flushes rewrite rules when the Designated Release Archive Page's slug or status changes.
	 *
	 * @since 2.2.0
	 *
	 * @param int      $post_id     Updated post ID.
	 * @param \WP_Post $post_after  Post object after update.
	 * @param \WP_Post $post_before Post object before update.
	 */
	public static function maybe_flush_on_archive_page_slug_change( int $post_id, \WP_Post $post_after, \WP_Post $post_before ): void {
		$archive_page_id = \MDS\ReleaseManager\Frontend\TemplateDefaults::get_archive_page_id();
		if ( $archive_page_id <= 0 || $post_id !== $archive_page_id ) {
			return;
		}
		if ( $post_after->post_name !== $post_before->post_name || $post_after->post_status !== $post_before->post_status ) {
			self::register_cpts();
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Appends a "Release Archive Page" badge in Pages -> All Pages for the designated page.
	 *
	 * @since 2.2.0
	 *
	 * @param array<string, string> $post_states Existing post state labels.
	 * @param \WP_Post              $post        Current post object.
	 * @return array<string, string> Updated post state labels.
	 */
	public static function filter_archive_page_post_state( array $post_states, \WP_Post $post ): array {
		if ( 'page' !== $post->post_type ) {
			return $post_states;
		}
		$archive_page_id = \MDS\ReleaseManager\Frontend\TemplateDefaults::get_archive_page_id();
		if ( $archive_page_id > 0 && (int) $post->ID === $archive_page_id ) {
			$post_states['mds_rm_release_archive'] = __( 'Release Archive Page', 'mds-release-manager' );
		}
		return $post_states;
	}

	/**
	 * Registers the content types before refreshing rewrite rules on activation.
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 */
	public static function activate( $network_wide = false ): void {
		self::register_cpts();
		self::flush_rewrite_rules( $network_wide );
	}

	/**
	 * Removes the content types before refreshing rewrite rules on deactivation.
	 *
	 * @param bool $network_wide Whether the plugin is being network-deactivated.
	 */
	public static function deactivate( $network_wide = false ): void {
		foreach ( array( 'release_tag', 'release_type', 'release_media_format' ) as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				unregister_taxonomy( $taxonomy );
			}
		}

		foreach ( array( 'release', 'artist' ) as $post_type ) {
			if ( post_type_exists( $post_type ) ) {
				unregister_post_type( $post_type );
			}
		}

		self::flush_rewrite_rules( $network_wide );
	}

	/**
	 * Refreshes rewrite rules for the current site or every site on network changes.
	 *
	 * @param mixed $network_wide Whether the operation applies to the whole network.
	 */
	private static function flush_rewrite_rules( mixed $network_wide ): void {
		if ( ! is_multisite() || ! $network_wide ) {
			flush_rewrite_rules();
			return;
		}

		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			try {
				flush_rewrite_rules( false );
			} finally {
				restore_current_blog();
			}
		}

		flush_rewrite_rules();
	}

	/**
	 * Registers release and artist content types and taxonomies.
	 *
	 * @since 0.9.0
	 */
	public static function register_cpts(): void {
		// On spoke sites catalog items are read-only shadows managed by the hub.
		$spoke_caps = Sync::is_hub()
			? array()
			: array(
				'capabilities' => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap' => true,
			);

		$archive_slug    = true;
		$archive_page_id = \MDS\ReleaseManager\Frontend\TemplateDefaults::get_archive_page_id();
		if ( $archive_page_id > 0 && 'publish' === get_post_status( $archive_page_id ) ) {
			$page_uri = trim( (string) get_page_uri( $archive_page_id ), '/' );
			if ( '' !== $page_uri ) {
				$archive_slug = $page_uri;
			}
		}

		register_post_type(
			'release',
			array(
				'labels'            => array(
					'name'                  => 'Releases',
					'singular_name'         => 'Release',
					'add_new'               => 'Add Release',
					'add_new_item'          => 'Add New Release',
					'edit_item'             => 'Edit Release',
					'new_item'              => 'New Release',
					'all_items'             => 'All Releases',
					'menu_name'             => 'Releases',
					'featured_image'        => 'Cover Art',
					'set_featured_image'    => 'Set cover art',
					'remove_featured_image' => 'Remove cover art',
					'use_featured_image'    => 'Use as cover art',
				),
				'public'            => true,
				'has_archive'       => $archive_slug,
				'show_in_menu'      => false,
				'show_in_nav_menus' => true,
				'show_in_rest'      => true,
				'supports'          => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'menu_icon'         => BrandIcon::data_uri(),
				'rewrite'           => array( 'slug' => 'release' ),
			) + $spoke_caps
		);

		register_post_type(
			'artist',
			array(
				'labels'       => array(
					'name'                  => 'Artists',
					'singular_name'         => 'Artist',
					'add_new'               => 'Add Artist',
					'add_new_item'          => 'Add New Artist',
					'edit_item'             => 'Edit Artist',
					'new_item'              => 'New Artist',
					'all_items'             => 'All Artists',
					'menu_name'             => 'Artists',
					'featured_image'        => 'Artist Photo',
					'set_featured_image'    => 'Set artist photo',
					'remove_featured_image' => 'Remove artist photo',
					'use_featured_image'    => 'Use as artist photo',
				),
				'public'       => true,
				'has_archive'  => true,
				'show_in_rest' => true,
				'show_in_menu' => false,
				'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'menu_icon'    => 'dashicons-groups',
				'rewrite'      => array( 'slug' => 'artist' ),
			) + $spoke_caps
		);

		register_taxonomy(
			'release_tag',
			'release',
			array(
				'label'             => 'Release Tags',
				'hierarchical'      => false,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'meta_box_cb'       => false,
				'rewrite'           => array( 'slug' => 'release-tag' ),
			)
		);

		register_taxonomy(
			'release_type',
			'release',
			array(
				'labels'             => array(
					'name'          => __( 'Release Types', 'mds-release-manager' ),
					'singular_name' => __( 'Release Type', 'mds-release-manager' ),
					'menu_name'     => __( 'Release Types', 'mds-release-manager' ),
				),
				'hierarchical'       => true,
				'show_admin_column'  => true,
				'show_ui'            => true,
				'show_in_rest'       => true,
				'meta_box_cb'        => false,
				'show_in_quick_edit' => false,
				'rewrite'            => array( 'slug' => 'release-type' ),
			)
		);

		register_taxonomy(
			'release_media_format',
			'release',
			array(
				'labels'             => array(
					'name'          => __( 'Media Formats', 'mds-release-manager' ),
					'singular_name' => __( 'Media Format', 'mds-release-manager' ),
					'menu_name'     => __( 'Media Formats', 'mds-release-manager' ),
					'add_new_item'  => __( 'Add New Media Format', 'mds-release-manager' ),
					'edit_item'     => __( 'Edit Media Format', 'mds-release-manager' ),
				),
				'hierarchical'       => false,
				'show_admin_column'  => true,
				'show_ui'            => true,
				'show_in_rest'       => true,
				'meta_box_cb'        => false,
				'show_in_quick_edit' => false,
				'rewrite'            => array( 'slug' => 'media-format' ),
			)
		);
	}
}

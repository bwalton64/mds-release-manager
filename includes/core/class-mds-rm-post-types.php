<?php
/**
 * Registers the plugin's post types and taxonomies.
 *
 * @package MDS_Release_Manager
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin's post types and taxonomies.
 *
 * @since 0.9.0
 */
final class MDS_RM_Post_Types {

	/**
	 * Registers post-type initialization.
	 *
	 * @since 0.9.0
	 */
	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'register_cpts' ) );
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
		$spoke_caps = MDS_RM_Sync::is_hub()
			? array()
			: array(
				'capabilities' => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap' => true,
			);

		register_post_type(
			'release',
			array(
				'labels'       => array(
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
				'public'       => true,
				'has_archive'  => true,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'menu_icon'    => 'dashicons-album',
				'rewrite'      => array( 'slug' => 'release' ),
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
				'show_in_menu' => 'edit.php?post_type=release',
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

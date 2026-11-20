<?php
declare(strict_types=1);

/**
 * Registers the plugin's post types and taxonomies.
 *
 * @package MDS_Release_Manager
 */

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
	public static function register_hooks() {
		add_action( 'init', array( self::class, 'register_cpts' ) );
		register_activation_hook( MDS_RM_MAIN_FILE, array( self::class, 'activate' ) );
		register_deactivation_hook( MDS_RM_MAIN_FILE, array( self::class, 'deactivate' ) );
	}

	/**
	 * Registers the content types before refreshing rewrite rules on activation.
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 */
	public static function activate( $network_wide = false ) {
		self::register_cpts();
		self::flush_rewrite_rules( $network_wide );
	}

	/**
	 * Removes the content types before refreshing rewrite rules on deactivation.
	 *
	 * @param bool $network_wide Whether the plugin is being network-deactivated.
	 */
	public static function deactivate( $network_wide = false ) {
		foreach ( array( 'release_tag', 'release_format' ) as $taxonomy ) {
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
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 */
	private static function flush_rewrite_rules( $network_wide ) {
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
	public static function register_cpts() {
		register_post_type(
			'release',
			array(
				'labels'       => array(
					'name'          => 'Releases',
					'singular_name' => 'Release',
					'add_new'       => 'Add Release',
					'add_new_item'  => 'Add New Release',
					'edit_item'     => 'Edit Release',
					'new_item'      => 'New Release',
					'all_items'     => 'All Releases',
					'menu_name'     => 'Releases',
				),
				'public'       => true,
				'has_archive'  => true,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
				'menu_icon'    => 'dashicons-album',
				'rewrite'      => array( 'slug' => 'release' ),
			)
		);

		register_post_type(
			'artist',
			array(
				'labels'       => array(
					'name'          => 'Artists',
					'singular_name' => 'Artist',
					'add_new'       => 'Add Artist',
					'add_new_item'  => 'Add New Artist',
					'edit_item'     => 'Edit Artist',
					'new_item'      => 'New Artist',
					'all_items'     => 'All Artists',
					'menu_name'     => 'Artists',
				),
				'public'       => true,
				'has_archive'  => true,
				'show_in_rest' => true,
				'show_in_menu' => 'edit.php?post_type=release',
				'supports'     => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
				'menu_icon'    => 'dashicons-groups',
				'rewrite'      => array( 'slug' => 'artist' ),
			)
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
			'release_format',
			'release',
			array(
				'labels'            => array(
					'name'          => 'Release Formats',
					'singular_name' => 'Release Format',
					'menu_name'     => 'Release Formats',
				),
				'hierarchical'      => true,
				'show_admin_column' => true,
				'show_ui'           => true,
				'show_in_rest'      => true,
				'meta_box_cb'       => false,
				'show_in_quick_edit' => false,
				'rewrite'           => array( 'slug' => 'release-format' ),
			)
		);
	}
}

<?php
/**
 * Owns the classic edit-screen experience for Releases and Artists.
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Configures the custom editor for the plugin's post types.
 *
 * The block editor is disabled so Releases and Artists use the native
 * classic edit screen, which hosts the plugin's reorganized meta boxes
 * alongside third-party and extension meta boxes.
 *
 * @since 1.0.0
 */
final class Editor {

	/**
	 * Post types managed by the custom editor.
	 *
	 * @var string[]
	 */
	private const POST_TYPES = array( 'release', 'artist' );

	/**
	 * Registers the editor hooks.
	 *
	 * @since 1.0.0
	 */
	public static function register_hooks(): void {
		add_filter( 'use_block_editor_for_post_type', array( self::class, 'disable_block_editor' ), 10, 2 );
		add_action( 'add_meta_boxes', array( self::class, 'remove_slug_box' ), 20 );
		add_filter( 'map_meta_cap', array( self::class, 'deny_editing_on_spokes' ), 10, 4 );
		add_action( 'admin_notices', array( self::class, 'render_spoke_notice' ) );
		add_filter( 'bulk_actions-edit-release', array( self::class, 'remove_bulk_actions_on_spokes' ) );
		add_filter( 'bulk_actions-edit-artist', array( self::class, 'remove_bulk_actions_on_spokes' ) );
		add_filter( 'enter_title_here', array( self::class, 'title_placeholder' ), 10, 2 );
		add_filter( 'get_user_option_meta-box-order_release', array( self::class, 'default_release_box_order' ) );
		add_filter( 'get_user_option_meta-box-order_artist', array( self::class, 'default_artist_box_order' ) );
		add_filter( 'default_hidden_meta_boxes', array( self::class, 'show_excerpt_by_default' ), 10, 2 );
	}

	/**
	 * Uses the classic edit screen for Releases and Artists.
	 *
	 * @since 1.0.0
	 * @param bool   $use_block_editor Whether the post type uses the block editor.
	 * @param string $post_type        The post type being checked.
	 * @return bool The filtered decision.
	 */
	public static function disable_block_editor( $use_block_editor, $post_type ) {
		if ( in_array( $post_type, self::POST_TYPES, true ) ) {
			return false;
		}

		return $use_block_editor;
	}

	/**
	 * Removes the redundant Slug box; the permalink editor under the title covers it.
	 *
	 * @since 1.0.0
	 * @param string $post_type The post type whose boxes are being registered.
	 */
	public static function remove_slug_box( $post_type ): void {
		if ( in_array( $post_type, self::POST_TYPES, true ) ) {
			remove_meta_box( 'slugdiv', $post_type, 'normal' );
		}
	}

	/**
	 * Denies editing and deleting catalog items on spoke sites.
	 *
	 * Catalog items on spokes are read-only shadows that the hub overwrites
	 * on every broadcast. Denying the meta capabilities removes the editing
	 * UI (row actions, Quick Edit, bulk actions) and blocks REST writes, so
	 * spoke-local changes can no longer drift from the hub.
	 *
	 * @since 1.0.0
	 * @param string[] $caps    Primitive capabilities required.
	 * @param string   $cap     The meta capability being checked.
	 * @param int      $user_id The user ID.
	 * @param mixed[]  $args    Additional context, with the post ID first.
	 * @return string[] The filtered primitive capabilities.
	 */
	public static function deny_editing_on_spokes( $caps, $cap, $user_id, $args ) {
		if ( ! in_array( $cap, array( 'edit_post', 'delete_post', 'publish_post' ), true ) || \MDS\ReleaseManager\Core\Sync::is_hub() ) {
			return $caps;
		}

		$post = isset( $args[0] ) ? get_post( $args[0] ) : null;
		if ( $post && in_array( $post->post_type, self::POST_TYPES, true ) ) {
			return array( 'do_not_allow' );
		}

		return $caps;
	}

	/**
	 * Removes bulk actions for the read-only catalog on spoke sites.
	 *
	 * The capability checks already reject these actions per post; hiding
	 * them avoids offering actions that cannot succeed.
	 *
	 * @since 1.0.0
	 * @param array $actions The available bulk actions.
	 * @return array The filtered bulk actions.
	 */
	public static function remove_bulk_actions_on_spokes( $actions ) {
		return \MDS\ReleaseManager\Core\Sync::is_hub() ? $actions : array();
	}

	/**
	 * Explains the read-only catalog on spoke site list screens.
	 *
	 * @since 1.0.0
	 */
	public static function render_spoke_notice(): void {
		if ( \MDS\ReleaseManager\Core\Sync::is_hub() ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'edit' !== $screen->base || ! in_array( $screen->post_type, self::POST_TYPES, true ) ) {
			return;
		}

		$hub_url = get_admin_url( \MDS\ReleaseManager\Core\Sync::hub_id(), 'edit.php?post_type=' . $screen->post_type );
		printf(
			'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Releases and Artists are managed on the hub site and are read-only here.', 'mds-release-manager' ),
			esc_url( $hub_url ),
			esc_html__( 'Edit them on the hub site.', 'mds-release-manager' )
		);
	}

	/**
	 * Provides post-type-specific title placeholders.
	 *
	 * @since 1.0.0
	 * @param string   $placeholder The default placeholder.
	 * @param \WP_Post $post        The post being edited.
	 * @return string The filtered placeholder.
	 */
	public static function title_placeholder( $placeholder, $post ) {
		if ( 'release' === $post->post_type ) {
			return __( 'Release title', 'mds-release-manager' );
		}

		if ( 'artist' === $post->post_type ) {
			return __( 'Artist name', 'mds-release-manager' );
		}

		return $placeholder;
	}

	/**
	 * Orders the release meta boxes until the user saves their own order.
	 *
	 * Boxes missing from this list (extension and third-party boxes) render
	 * after the listed ones, so SEO managers and feature plugins stay below
	 * the catalog fields by default.
	 *
	 * @since 1.0.0
	 * @param mixed $order The saved order, or false when the user has none.
	 * @return mixed The default order, or the user's saved order.
	 */
	public static function default_release_box_order( $order ) {
		if ( ! empty( $order ) ) {
			return $order;
		}

		return array(
			'normal' => 'mds_release_identity,postexcerpt',
		);
	}

	/**
	 * Orders the artist meta boxes until the user saves their own order.
	 *
	 * @since 1.0.0
	 * @param mixed $order The saved order, or false when the user has none.
	 * @return mixed The default order, or the user's saved order.
	 */
	public static function default_artist_box_order( $order ) {
		if ( ! empty( $order ) ) {
			return $order;
		}

		return array(
			'normal' => 'mds_artist_settings,postexcerpt',
		);
	}

	/**
	 * Shows the excerpt box by default on the catalog edit screens.
	 *
	 * WordPress hides the classic excerpt box until the user enables it in
	 * Screen Options; the catalog types treat the excerpt as a primary field.
	 *
	 * @since 1.0.0
	 * @param string[]   $hidden Meta box IDs hidden by default.
	 * @param \WP_Screen $screen The current screen.
	 * @return string[] The filtered hidden meta box IDs.
	 */
	public static function show_excerpt_by_default( $hidden, $screen ) {
		if ( $screen instanceof \WP_Screen && in_array( $screen->post_type, self::POST_TYPES, true ) ) {
			$hidden = array_values( array_diff( (array) $hidden, array( 'postexcerpt' ) ) );
		}

		return $hidden;
	}
}

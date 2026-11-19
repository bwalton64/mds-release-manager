<?php
declare(strict_types=1);

/**
 * Registers and serves the plugin's frontend and admin assets.
 *
 * @package MDS_Release_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and serves the plugin's frontend and admin assets.
 *
 * @since 0.9.0
 */
final class MDS_RM_Assets {

	/**
	 * Registers asset and redirect hooks.
	 *
	 * @since 0.9.0
	 */
	public static function register_hooks() {
		add_action( 'wp_enqueue_scripts', array( self::class, 'mds_rm_enqueue_frontend_assets' ) );
		add_action( 'enqueue_block_assets', array( MDS_RM_Release_Meta::class, 'enqueue_assets' ) );
		add_action( 'enqueue_block_assets', array( MDS_RM_Release_Links::class, 'enqueue_assets' ) );
		add_action( 'template_redirect', array( self::class, 'mds_rm_handle_global_redirect' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'mds_rm_admin_assets' ) );
	}

	/**
	 * Enqueues the frontend styles.
	 *
	 * @since 0.9.0
	 */
	public static function mds_rm_enqueue_frontend_assets() {
		self::mds_rm_enqueue_font_awesome();
		MDS_RM_Release_Meta::enqueue_assets();

		MDS_RM_Core_Functions::mds_rm_enqueue_editable_style( 'mds-release-links-css', 'css/frontend.css' );
		if ( is_singular( 'release' ) ) {
			wp_add_inline_style(
				'mds-release-links-css',
				'.mds-title { color: ' . esc_attr( get_theme_mod( 'mds_rm_primary_color', '#2271b1' ) ) . '; }'
			);
		}
	}

	public static function mds_rm_enqueue_font_awesome() {
		$fa_support = get_option( 'mds_rm_fa_support', 'cdn' );
		if ( in_array( 'internal', (array) $fa_support, true ) || in_array( 'cdn', (array) $fa_support, true ) ) {
			wp_register_style(
				'mds-font-awesome',
				'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
				array(),
				'6.4.0'
			);
			wp_enqueue_style( 'mds-font-awesome' );
		}
	}

	/**
	 * Redirects release pages to their configured purchase URL.
	 *
	 * @since 0.9.0
	 */
	public static function mds_rm_handle_global_redirect() {
		if ( is_admin() || ! is_singular( 'release' ) ) {
			return;
		}
		if ( get_option( 'mds_rm_global_redirect', '0' ) !== '1' ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}

		$request = new WP_REST_Request( 'GET', '/mds/v1/releases/post/' . $post_id );
		$request->set_param( 'blog_id', get_current_blog_id() );
		$response = rest_do_request( $request );
		$release  = $response->is_error() ? array() : $response->get_data();

		if ( empty( $release['buy_now_link'] ) ) {
			return;
		}

		$target_url  = esc_url_raw( $release['buy_now_link'] );
		$current_url = home_url( add_query_arg( array(), $GLOBALS['wp']->request ?? '' ) );
		if ( empty( $target_url ) || untrailingslashit( $target_url ) === untrailingslashit( $current_url ) ) {
			return;
		}

		$target_host = wp_parse_url( $target_url, PHP_URL_HOST );
		if ( $target_host ) {
			add_filter(
				'allowed_redirect_hosts',
				static function ( $hosts ) use ( $target_host ) {
					$hosts[] = $target_host;
					return $hosts;
				}
			);
		}

		wp_safe_redirect( $target_url, 302 );
		exit;
	}

	/**
	 * Enqueues assets required by plugin admin screens.
	 *
	 * @since 0.9.0
	 */
	public static function mds_rm_admin_assets() {
		$screen            = get_current_screen();
		$is_content_screen = $screen
			&& in_array( $screen->base, array( 'post', 'edit' ), true )
			&& in_array( $screen->post_type, array( 'release', 'artist' ), true );

		if ( $is_content_screen ) {
			wp_enqueue_media();

			MDS_RM_Core_Functions::mds_rm_enqueue_editable_style( 'mds-admin-styles', 'css/admin-styles.css' );
			MDS_RM_Core_Functions::mds_rm_enqueue_editable_script(
				'mds-meta-boxes-js',
				'js/meta-boxes.js',
				array( 'jquery' ),
				true
			);

			if ( 'post' === $screen->base && 'release' === $screen->post_type ) {
				$is_block_editor = $screen->is_block_editor();
				$dependencies    = array( 'jquery', 'tags-box' );
				$dependencies    = array_merge( $dependencies, $is_block_editor ? array( 'wp-data', 'wp-dom-ready', 'wp-edit-post', 'wp-hooks' ) : array( 'post' ) );
				MDS_RM_Core_Functions::mds_rm_enqueue_editable_script(
					'mds-release-taxonomies',
					'js/release-taxonomies.js',
					$dependencies,
					true
				);
				wp_add_inline_script(
					'mds-release-taxonomies',
					'var mdsReleaseBlockEditor = ' . wp_json_encode( $is_block_editor ) . ';',
					'before'
				);
			}

		}

		if ( $screen && 'release_page_mds-release-settings' === $screen->id ) {
			MDS_RM_Core_Functions::mds_rm_enqueue_editable_style( 'mds-admin-styles', 'css/admin-styles.css' );
			wp_enqueue_style( 'wp-color-picker' );
			MDS_RM_Core_Functions::mds_rm_enqueue_editable_script(
				'mds-settings-js',
				'js/settings.js',
				array( 'jquery', 'wp-color-picker', 'jquery-ui-sortable' ),
				true
			);
			wp_localize_script(
				'mds-settings-js',
				'mds_rm_data',
				array(
					'rest_url' => esc_url_raw( rest_url( 'mds/v1/settings' ) ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
				)
			);
		}
	}
}

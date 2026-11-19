<?php
declare(strict_types=1);

/**
 * Detects builder-owned post content.
 *
 * @package MDS_Release_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Identifies builder-owned content so the fallback does not duplicate layouts.
 *
 * @since 0.9.0
 */
final class MDS_RM_Builder_Detection {

	/**
	 * Detects the builder responsible for a post, if any.
	 *
	 * Third-party integrations can return a builder slug with the
	 * mds_rm_detected_content_builder filter.
	 *
	 * @since 0.9.0
	 * @param int $post_id The post ID.
	 * @return string The detected builder slug, or an empty string.
	 */
	public static function mds_rm_detect_builder( $post_id ) {
		$post = get_post( absint( $post_id ) );
		if ( ! $post instanceof WP_Post ) {
			return '';
		}

		$builder = self::mds_rm_detect_elementor( $post );
		if ( '' === $builder ) {
			$builder = self::mds_rm_detect_divi( $post );
		}
		if ( '' === $builder ) {
			$builder = self::mds_rm_detect_bricks( $post );
		}
		if ( '' === $builder ) {
			$builder = self::mds_rm_detect_wpbakery( $post );
		}
		if ( '' === $builder ) {
			$builder = self::mds_rm_detect_beaver_builder( $post );
		}

		$builder = apply_filters( 'mds_rm_detected_content_builder', $builder, $post );
		return is_string( $builder ) ? sanitize_key( $builder ) : '';
	}

	/**
	 * Detects Elementor via its document API or persisted post metadata.
	 *
	 * @param WP_Post $post The post to inspect.
	 * @return string The builder slug, or an empty string.
	 */
	private static function mds_rm_detect_elementor( WP_Post $post ) {
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->documents ) ) {
			$document = \Elementor\Plugin::$instance->documents->get( $post->ID );
			if ( is_object( $document ) && is_callable( array( $document, 'is_built_with_elementor' ) ) && $document->is_built_with_elementor() ) {
				return 'elementor';
			}
		}

		if (
			'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true )
			|| ! empty( get_post_meta( $post->ID, '_elementor_data', true ) )
		) {
			return 'elementor';
		}

		return '';
	}

	/**
	 * Detects Divi 4 shortcodes and Divi 5 block content.
	 *
	 * @param WP_Post $post The post to inspect.
	 * @return string The builder slug, or an empty string.
	 */
	private static function mds_rm_detect_divi( WP_Post $post ) {
		if ( 'on' === get_post_meta( $post->ID, '_et_pb_use_builder', true ) ) {
			return 'divi';
		}

		if (
			has_shortcode( $post->post_content, 'et_pb_section' )
			|| preg_match( '/<!--\s*wp:divi\//', $post->post_content )
		) {
			return 'divi';
		}

		return '';
	}

	/**
	 * Detects Bricks content stored in post metadata.
	 *
	 * @param WP_Post $post The post to inspect.
	 * @return string The builder slug, or an empty string.
	 */
	private static function mds_rm_detect_bricks( WP_Post $post ) {
		if (
			'bricks' === get_post_meta( $post->ID, '_bricks_editor_mode', true )
			|| ! empty( get_post_meta( $post->ID, '_bricks_page_content_2', true ) )
		) {
			return 'bricks';
		}

		return '';
	}

	/**
	 * Detects WPBakery post metadata and row shortcodes.
	 *
	 * @param WP_Post $post The post to inspect.
	 * @return string The builder slug, or an empty string.
	 */
	private static function mds_rm_detect_wpbakery( WP_Post $post ) {
		$status = get_post_meta( $post->ID, '_wpb_vc_js_status', true );
		if (
			( is_scalar( $status ) && 'true' === (string) $status )
			|| has_shortcode( $post->post_content, 'vc_row' )
		) {
			return 'wpbakery';
		}

		return '';
	}

	/**
	 * Detects Beaver Builder posts.
	 *
	 * @param WP_Post $post The post to inspect.
	 * @return string The builder slug, or an empty string.
	 */
	private static function mds_rm_detect_beaver_builder( WP_Post $post ) {
		$enabled = get_post_meta( $post->ID, '_fl_builder_enabled', true );
		if ( is_scalar( $enabled ) && in_array( (string) $enabled, array( '1', 'true', 'yes', 'on' ), true ) ) {
			return 'beaver-builder';
		}

		return '';
	}
}

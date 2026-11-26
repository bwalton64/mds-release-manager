<?php
/**
 * Detects builder-owned post content.
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Core;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Identifies builder-owned content so the fallback does not duplicate layouts.
 *
 * @since 0.9.0
 */
final class BuilderDetection {

	/**
	 * Detects the builder responsible for a post, if any.
	 *
	 * Third-party integrations can return a builder slug with the
	 * mds_rm_detected_content_builder filter.
	 *
	 * @since 0.9.0
	 * @param mixed $post_id The post ID, normalized by absint().
	 * @return string The detected builder slug, or an empty string.
	 */
	public static function detect_builder( mixed $post_id ): string {
		$post = get_post( absint( $post_id ) );
		if ( ! $post instanceof WP_Post ) {
			return '';
		}

		$builder = self::detect_elementor( $post );
		if ( '' === $builder ) {
			$builder = self::detect_divi( $post );
		}
		if ( '' === $builder ) {
			$builder = self::detect_bricks( $post );
		}
		if ( '' === $builder ) {
			$builder = self::detect_wpbakery( $post );
		}
		if ( '' === $builder ) {
			$builder = self::detect_beaver_builder( $post );
		}
		if ( '' === $builder ) {
			$builder = self::detect_breakdance( $post );
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
	private static function detect_elementor( WP_Post $post ): string {
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
	private static function detect_divi( WP_Post $post ): string {
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
	private static function detect_bricks( WP_Post $post ): string {
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
	private static function detect_wpbakery( WP_Post $post ): string {
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
	private static function detect_beaver_builder( WP_Post $post ): string {
		$enabled = get_post_meta( $post->ID, '_fl_builder_enabled', true );
		if ( is_scalar( $enabled ) && in_array( (string) $enabled, array( '1', 'true', 'yes', 'on' ), true ) ) {
			return 'beaver-builder';
		}

		return '';
	}

	/**
	 * Detects Breakdance builder posts.
	 *
	 * @since 2.2.0
	 * @param WP_Post $post The post to inspect.
	 * @return string The builder slug, or an empty string.
	 */
	private static function detect_breakdance( WP_Post $post ): string {
		$bd_data = get_post_meta( $post->ID, '_breakdance_data', true );
		if ( ! empty( $bd_data ) ) {
			return 'breakdance';
		}

		return '';
	}
}

<?php
/**
 * Per-artist homepage routing.
 *
 * @package MDS_Release_Manager
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Redirects Artist permalinks to their configured homepages. */
final class MDS_RM_Artist_Routing {
	/** Registers the Artist homepage redirect. */
	public static function register_hooks(): void {
		add_action( 'template_redirect', array( self::class, 'redirect' ) );
	}

	/**
	 * Checks whether a homepage URL can serve as the Artist redirect target.
	 *
	 * @param mixed $url     Candidate homepage URL.
	 * @param int   $post_id Artist post ID.
	 * @return bool Whether the target is valid.
	 */
	public static function valid_url( mixed $url, int $post_id ): bool {
		if ( ! is_string( $url ) || '' === $url || esc_url_raw( $url, array( 'http', 'https' ) ) !== $url ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) || ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		$permalink = wp_parse_url( get_permalink( $post_id ) );
		if ( is_array( $permalink ) && isset( $permalink['host'] ) && strtolower( (string) $parts['host'] ) === strtolower( (string) $permalink['host'] ) && untrailingslashit( isset( $parts['path'] ) ? (string) $parts['path'] : '/' ) === untrailingslashit( isset( $permalink['path'] ) ? (string) $permalink['path'] : '/' ) ) {
			return false;
		}
		return true;
	}

	/** Redirects eligible Artist requests to their configured homepage. */
	public static function redirect(): void {
		if ( is_admin() || ! is_singular( 'artist' ) || is_preview() || is_customize_preview() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		$post_id = get_queried_object_id();
		$url     = get_post_meta( $post_id, 'artist_homepage_url', true );
		if ( '1' !== get_post_meta( $post_id, 'artist_homepage_routing', true ) || ! self::valid_url( $url, $post_id ) ) {
			return;
		}
		if ( wp_safe_redirect( $url, 302 ) ) {
			exit;
		}
	}
}

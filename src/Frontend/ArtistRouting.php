<?php
/**
 * Artist and per-release routing engine.
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves and executes 302 redirects for Artist and Release permalinks.
 *
 * Supports two-tier inheritance:
 * 1. Artist-level default policy (`artist_releases_routing_mode`)
 * 2. Per-release override (`release_routing_mode` + optional `release_routing_url`)
 *
 * Extensions (such as MDS WooCommerce Integration) can register additional routing
 * modes via `mds_rm_release_routing_modes`, resolve redirect URLs via
 * `mds_rm_resolve_release_redirect_url`, and customize table previews via
 * `mds_rm_routing_destination_preview`.
 */
final class ArtistRouting {

	/** Registers the Artist and Release redirect handler. */
	public static function register_hooks(): void {
		add_action( 'template_redirect', array( self::class, 'redirect' ) );
	}

	/**
	 * Returns supported Artist-level default release routing modes.
	 *
	 * @return array<string, string> Map of mode slug to human-readable label.
	 */
	public static function artist_release_modes(): array {
		$modes = array(
			'fallback'            => __( 'Fallback Page (Default)', 'mds-release-manager' ),
			'artist_website'      => __( 'Forward All Releases to Artist Website', 'mds-release-manager' ),
			'buy_now_or_artist'   => __( 'Primary Buy Now URL (fallback to Artist Website)', 'mds-release-manager' ),
			'buy_now_or_fallback' => __( 'Primary Buy Now URL (fallback to Release Page)', 'mds-release-manager' ),
		);

		/**
		 * Filters additional routing modes registered by extensions (e.g. `shop_product`).
		 *
		 * @since 1.1.0
		 * @param array<string, string> $extra_modes Extension modes (`slug => label`).
		 * @param string                $context     Context (`artist` or `release`).
		 */
		$extra = apply_filters( 'mds_rm_release_routing_modes', array(), 'artist' );
		if ( is_array( $extra ) ) {
			foreach ( $extra as $slug => $label ) {
				$clean_slug = sanitize_key( (string) $slug );
				if ( '' !== $clean_slug && ! isset( $modes[ $clean_slug ] ) && is_string( $label ) && '' !== $label ) {
					$modes[ $clean_slug ] = $label;
				}
			}
		}

		return $modes;
	}

	/**
	 * Returns supported per-release routing override modes.
	 *
	 * @return array<string, string> Map of mode slug to human-readable label.
	 */
	public static function release_modes(): array {
		$modes = array(
			'inherit'        => __( 'Inherit Artist Default', 'mds-release-manager' ),
			'fallback'       => __( 'Fallback Page (No Redirect)', 'mds-release-manager' ),
			'artist_website' => __( 'Artist Website', 'mds-release-manager' ),
			'buy_now'        => __( 'Primary Buy Now URL', 'mds-release-manager' ),
			'custom_url'     => __( 'Custom URL', 'mds-release-manager' ),
		);

		$extra = apply_filters( 'mds_rm_release_routing_modes', array(), 'release' );
		if ( is_array( $extra ) ) {
			foreach ( $extra as $slug => $label ) {
				$clean_slug = sanitize_key( (string) $slug );
				if ( '' !== $clean_slug && ! isset( $modes[ $clean_slug ] ) && is_string( $label ) && '' !== $label ) {
					$modes[ $clean_slug ] = $label;
				}
			}
		}

		return $modes;
	}

	/**
	 * Sanitizes an Artist-level release routing policy slug.
	 *
	 * @param mixed $mode Submitted mode.
	 * @return string Valid mode slug (defaults to `fallback`).
	 */
	public static function sanitize_artist_release_mode( mixed $mode ): string {
		$slug  = is_string( $mode ) ? sanitize_key( $mode ) : '';
		$modes = self::artist_release_modes();
		return isset( $modes[ $slug ] ) ? $slug : 'fallback';
	}

	/**
	 * Sanitizes a per-release routing mode slug.
	 *
	 * @param mixed $mode Submitted mode.
	 * @return string Valid mode slug (defaults to `inherit`).
	 */
	public static function sanitize_release_mode( mixed $mode ): string {
		$slug  = is_string( $mode ) ? sanitize_key( $mode ) : '';
		$modes = self::release_modes();
		return isset( $modes[ $slug ] ) ? $slug : 'inherit';
	}

	/**
	 * Resolves the linked Artist post ID for a Release via its `artist_id` UUID.
	 *
	 * @param int $release_id Release post ID.
	 * @return int Artist post ID, or 0 when unlinked.
	 */
	public static function get_release_artist_id( int $release_id ): int {
		$uuid = get_post_meta( $release_id, 'artist_id', true );
		if ( ! is_string( $uuid ) || '' === trim( $uuid ) ) {
			return 0;
		}
		$artists = get_posts(
			array(
				'post_type'              => 'artist',
				'post_status'            => array( 'publish', 'draft', 'private', 'pending', 'future' ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'meta_key'               => '_mds_artist_uuid', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Releases link to Artists by UUID.
				'meta_value'             => trim( $uuid ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		return ! empty( $artists[0] ) ? (int) $artists[0] : 0;
	}

	/**
	 * Checks whether a candidate URL can safely serve as a redirect target for a post.
	 *
	 * @param mixed $url     Candidate destination URL.
	 * @param int   $post_id Source Artist or Release post ID.
	 * @return bool Whether the target URL is valid and does not self-redirect.
	 */
	public static function valid_url( mixed $url, int $post_id ): bool {
		if ( ! is_string( $url ) || '' === $url || esc_url_raw( $url, array( 'http', 'https' ) ) !== $url ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) || ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		$permalink = wp_parse_url( (string) get_permalink( $post_id ) );
		if ( is_array( $permalink ) && isset( $permalink['host'] ) && strtolower( (string) $parts['host'] ) === strtolower( (string) $permalink['host'] ) && untrailingslashit( isset( $parts['path'] ) ? (string) $parts['path'] : '/' ) === untrailingslashit( isset( $permalink['path'] ) ? (string) $permalink['path'] : '/' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Resolves the final redirect URL for a Release post, or empty string when fallback should render.
	 *
	 * @param int      $release_id Release post ID.
	 * @param int|null $artist_id  Optional pre-resolved Artist post ID.
	 * @return string Valid destination URL, or empty string for fallback page.
	 */
	public static function resolve_release_redirect( int $release_id, ?int $artist_id = null ): string {
		$details = self::describe_release_destination( $release_id, $artist_id );
		return $details['url'];
	}

	/**
	 * Describes the effective routing mode, resolved destination URL, and UI status for a Release.
	 *
	 * @param int      $release_id Release post ID.
	 * @param int|null $artist_id  Optional pre-resolved Artist post ID.
	 * @return array{own_mode:string,effective_mode:string,url:string,label:string,is_warning:bool} Destination summary.
	 */
	public static function describe_release_destination( int $release_id, ?int $artist_id = null ): array {
		$own_mode       = self::sanitize_release_mode( get_post_meta( $release_id, 'release_routing_mode', true ) );
		$artist_id      = null === $artist_id ? self::get_release_artist_id( $release_id ) : $artist_id;
		$artist_mode    = $artist_id > 0
			? self::sanitize_artist_release_mode( get_post_meta( $artist_id, 'artist_releases_routing_mode', true ) )
			: 'fallback';
		$effective_mode = 'inherit' === $own_mode ? $artist_mode : $own_mode;
		$artist_url     = $artist_id > 0 ? (string) get_post_meta( $artist_id, 'artist_homepage_url', true ) : '';

		$candidate_url = '';
		$warning_label = '';

		switch ( $effective_mode ) {
			case 'fallback':
				return array(
					'own_mode'       => $own_mode,
					'effective_mode' => 'fallback',
					'url'            => '',
					'label'          => __( 'Fallback Page', 'mds-release-manager' ),
					'is_warning'     => false,
				);

			case 'artist_website':
				$candidate_url = $artist_url;
				if ( '' === $candidate_url ) {
					$warning_label = __( 'No Artist Website URL set — shows fallback', 'mds-release-manager' );
				}
				break;

			case 'buy_now':
			case 'buy_now_or_fallback':
				$candidate_url = class_exists( 'MDS\ReleaseManager\Core\ReleaseModel' )
					? \MDS\ReleaseManager\Core\ReleaseModel::get_primary_buy_now_url( $release_id )
					: (string) get_post_meta( $release_id, 'buy_now_link', true );
				if ( '' === $candidate_url ) {
					$warning_label = __( 'No Buy Now URL set — shows fallback', 'mds-release-manager' );
				}
				break;

			case 'buy_now_or_artist':
				$candidate_url = class_exists( 'MDS\ReleaseManager\Core\ReleaseModel' )
					? \MDS\ReleaseManager\Core\ReleaseModel::get_primary_buy_now_url( $release_id )
					: (string) get_post_meta( $release_id, 'buy_now_link', true );
				if ( '' === $candidate_url ) {
					$candidate_url = $artist_url;
				}
				if ( '' === $candidate_url ) {
					$warning_label = __( 'No Buy Now or Artist URL set — shows fallback', 'mds-release-manager' );
				}
				break;

			case 'custom_url':
				$candidate_url = (string) get_post_meta( $release_id, 'release_routing_url', true );
				if ( '' === $candidate_url ) {
					$warning_label = __( 'No Custom URL entered — shows fallback', 'mds-release-manager' );
				}
				break;

			default:
				/**
				 * Resolves the redirect URL for an extension-registered routing mode (e.g. `shop_product`).
				 *
				 * @since 1.1.0
				 * @param string $url            Resolved URL (default empty).
				 * @param string $effective_mode Effective routing mode slug.
				 * @param int    $release_id     Release post ID.
				 * @param int    $artist_id      Linked Artist post ID (or 0).
				 */
				$candidate_url = (string) apply_filters( 'mds_rm_resolve_release_redirect_url', '', $effective_mode, $release_id, $artist_id );
				if ( '' === $candidate_url ) {
					$warning_label = __( 'Extension target unavailable — shows fallback', 'mds-release-manager' );
				}
				break;
		}

		$is_valid = '' !== $candidate_url && self::valid_url( $candidate_url, $release_id );
		$result   = array(
			'own_mode'       => $own_mode,
			'effective_mode' => $effective_mode,
			'url'            => $is_valid ? $candidate_url : '',
			'label'          => $is_valid
				? $candidate_url
				: ( '' !== $warning_label ? $warning_label : __( 'Invalid or self-referencing URL — shows fallback', 'mds-release-manager' ) ),
			'is_warning'     => ! $is_valid,
		);

		/**
		 * Filters the admin routing table destination preview for a Release.
		 *
		 * @since 1.1.0
		 * @param array  $result         Destination summary array.
		 * @param int    $release_id     Release post ID.
		 * @param string $effective_mode Effective routing mode slug.
		 */
		$filtered = apply_filters( 'mds_rm_routing_destination_preview', $result, $release_id, $effective_mode );
		return is_array( $filtered ) ? $filtered : $result;
	}

	/**
	 * Executes a safe 302 redirect, whitelisting the validated external host.
	 *
	 * @param string $url Validated target URL.
	 * @return void
	 */
	private static function send_redirect( string $url ): void {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( is_string( $host ) && '' !== $host ) {
			add_filter(
				'allowed_redirect_hosts',
				static function ( array $hosts ) use ( $host ): array {
					$hosts[] = $host;
					return $hosts;
				}
			);
		}
		if ( wp_safe_redirect( $url, 302 ) ) {
			exit;
		}
	}

	/** Redirects eligible Artist or Release requests to their configured destination. */
	public static function redirect(): void {
		if ( is_admin() || is_preview() || is_customize_preview() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( is_singular( 'artist' ) ) {
			$post_id = get_queried_object_id();
			$url     = get_post_meta( $post_id, 'artist_homepage_url', true );
			if ( '1' === get_post_meta( $post_id, 'artist_homepage_routing', true ) && self::valid_url( $url, $post_id ) ) {
				self::send_redirect( (string) $url );
			}
			return;
		}

		if ( is_singular( 'release' ) ) {
			$post_id = get_queried_object_id();
			if ( ! $post_id ) {
				return;
			}
			$target_url = self::resolve_release_redirect( $post_id );
			if ( '' !== $target_url ) {
				self::send_redirect( $target_url );
			}
		}
	}
}

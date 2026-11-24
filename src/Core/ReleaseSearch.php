<?php
/**
 * Shared Release catalog search for editor pickers.
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Searches published Releases by title, Artist name, or exact UPC/EAN.
 *
 * This is the public PHP search surface for editor pickers. Extension
 * plugins (for example the Bandcamp integration) consume it so release
 * lookup semantics stay identical on both sides of the core/extension
 * boundary without extensions re-implementing catalog queries.
 *
 * @since 1.1.0
 */
final class ReleaseSearch {
	/**
	 * Maximum number of Releases loaded for matching.
	 *
	 * @var int
	 */
	private const CATALOG_LIMIT = 500;

	/**
	 * Default picker page size.
	 *
	 * @var int
	 */
	public const PER_PAGE = 20;

	/**
	 * Finds published Releases matching a picker search term.
	 *
	 * An empty term returns the catalog ordered by title. An all-digit term
	 * first tries an exact UPC/EAN match (leading zeroes significant) and
	 * falls back to title/Artist matching. Any other term matches the
	 * Release title or its Artist's name case-insensitively.
	 *
	 * @since 1.1.0
	 *
	 * @param string $term Raw picker search term.
	 * @return WP_Post[] Matching Releases ordered by title.
	 */
	public static function query( string $term ): array {
		$term     = trim( $term );
		$releases = get_posts(
			array(
				'post_type'           => 'release',
				'post_status'         => 'publish',
				'has_password'        => false,
				'posts_per_page'      => self::CATALOG_LIMIT,
				'orderby'             => 'title',
				'order'               => 'ASC',
				'ignore_sticky_posts' => true,
			)
		);
		if ( '' === $term ) {
			return $releases;
		}

		if ( ctype_digit( $term ) ) {
			$exact = array_values(
				array_filter(
					$releases,
					static function ( \WP_Post $post ) use ( $term ): bool {
						return get_post_meta( $post->ID, 'upc_ean', true ) === $term;
					}
				)
			);
			if ( $exact ) {
				return $exact;
			}
		}

		$names = self::artist_names( $releases );
		return array_values(
			array_filter(
				$releases,
				static function ( \WP_Post $post ) use ( $term, $names ): bool {
					if ( self::contains( get_the_title( $post ), $term ) ) {
						return true;
					}
					return isset( $names[ $post->ID ] ) && self::contains( $names[ $post->ID ], $term );
				}
			)
		);
	}

	/**
	 * Slices a result set into one picker page.
	 *
	 * @since 1.1.0
	 *
	 * @param WP_Post[] $posts    Matching Releases.
	 * @param int       $page     Requested page, starting at 1.
	 * @param int       $per_page Page size.
	 * @return array{posts: WP_Post[], pages: int} The page of posts and the page count.
	 */
	public static function paginate( array $posts, int $page, int $per_page = self::PER_PAGE ): array {
		$per_page = max( 1, $per_page );
		$page     = max( 1, $page );
		return array(
			'posts' => array_slice( $posts, ( $page - 1 ) * $per_page, $per_page ),
			'pages' => (int) ceil( count( $posts ) / $per_page ),
		);
	}

	/**
	 * Converts Releases to picker records with resolved Artist names.
	 *
	 * @since 1.1.0
	 *
	 * @param WP_Post[] $posts Releases.
	 * @return array[] Records with id, title, artist, and upc keys.
	 */
	public static function describe( array $posts ): array {
		$names = self::artist_names( $posts );
		return array_map(
			static function ( \WP_Post $post ) use ( $names ): array {
				$item = array(
					'id'     => $post->ID,
					'title'  => get_the_title( $post ),
					'artist' => $names[ $post->ID ] ?? '',
					'upc'    => (string) get_post_meta( $post->ID, 'upc_ean', true ),
				);
				if ( function_exists( 'apply_filters' ) ) {
					/**
					 * Filters a Release picker item record so extensions can attach custom metadata.
					 *
					 * @since 1.1.0
					 * @param array   $item Picker record with id, title, artist, and upc.
					 * @param WP_Post $post Release post object.
					 */
					$filtered = apply_filters( 'mds_rm_release_picker_item', $item, $post );
					if ( is_array( $filtered ) ) {
						$item = array_merge( $filtered, $item );
					}
				}
				return $item;
			},
			$posts
		);
	}

	/**
	 * Finds published Artists matching a picker search term.
	 *
	 * @since 1.1.0
	 * @param string $term Raw picker search term.
	 * @return WP_Post[] Matching Artists ordered by title.
	 */
	public static function query_artists( string $term = '' ): array {
		$term    = trim( $term );
		$artists = get_posts(
			array(
				'post_type'           => 'artist',
				'post_status'         => 'publish',
				'has_password'        => false,
				'posts_per_page'      => self::CATALOG_LIMIT,
				'orderby'             => 'title',
				'order'               => 'ASC',
				'ignore_sticky_posts' => true,
			)
		);
		if ( '' === $term ) {
			return $artists;
		}
		return array_values(
			array_filter(
				$artists,
				static function ( \WP_Post $artist ) use ( $term ): bool {
					return self::contains( get_the_title( $artist ), $term );
				}
			)
		);
	}

	/**
	 * Converts Artist posts to picker records and applies the extension enrichment filter.
	 *
	 * @since 1.1.0
	 * @param WP_Post[] $artists Artist posts.
	 * @return array<int, array<string, mixed>> Artist records with id, title, and uuid keys.
	 */
	public static function describe_artists( array $artists ): array {
		return array_map(
			static function ( \WP_Post $artist ): array {
				$item = array(
					'id'    => $artist->ID,
					'title' => get_the_title( $artist ),
					'uuid'  => (string) get_post_meta( $artist->ID, '_mds_artist_uuid', true ),
				);
				if ( function_exists( 'apply_filters' ) ) {
					/**
					 * Filters an Artist picker item record so extensions can attach custom metadata.
					 *
					 * @since 1.1.0
					 * @param array   $item   Picker record with id, title, and uuid.
					 * @param WP_Post $artist Artist post object.
					 */
					$filtered = apply_filters( 'mds_rm_artist_picker_item', $item, $artist );
					if ( is_array( $filtered ) ) {
						$item = array_merge( $filtered, $item );
					}
				}
				return $item;
			},
			$artists
		);
	}

	/**
	 * Builds the preloaded Release and Artist catalog payload for the Gutenberg block editor.
	 *
	 * @since 1.1.0
	 * @return array{
	 *     items: array<int, array<string, mixed>>,
	 *     truncated: bool,
	 *     artists: array<int, array<string, mixed>>,
	 *     artistsTruncated: bool
	 * } Editor catalog.
	 */
	public static function editor_catalog(): array {
		$posts   = self::query( '' );
		$artists = self::query_artists( '' );
		return array(
			'items'            => self::describe( $posts ),
			'truncated'        => count( $posts ) >= self::CATALOG_LIMIT,
			'artists'          => self::describe_artists( $artists ),
			'artistsTruncated' => count( $artists ) >= self::CATALOG_LIMIT,
		);
	}

	/**
	 * Resolves Artist display names for a set of Releases in one lookup.
	 *
	 * @param WP_Post[] $posts Releases.
	 * @return array<int, string> Artist name keyed by Release post ID.
	 */
	private static function artist_names( array $posts ): array {
		$uuids = array();
		foreach ( $posts as $post ) {
			$uuid = get_post_meta( $post->ID, 'artist_id', true );
			if ( is_string( $uuid ) && '' !== $uuid ) {
				$uuids[ $post->ID ] = $uuid;
			}
		}
		if ( ! $uuids ) {
			return array();
		}
		$titles  = array();
		$artists = get_posts(
			array(
				'post_type'      => 'artist',
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => -1,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Resolve Artist labels for a bounded Release set together.
					array(
						'key'     => '_mds_artist_uuid',
						'value'   => array_values( array_unique( $uuids ) ),
						'compare' => 'IN',
					),
				),
			)
		);
		foreach ( $artists as $artist ) {
			$titles[ get_post_meta( $artist->ID, '_mds_artist_uuid', true ) ] = get_the_title( $artist );
		}
		$names = array();
		foreach ( $uuids as $post_id => $uuid ) {
			if ( isset( $titles[ $uuid ] ) ) {
				$names[ $post_id ] = $titles[ $uuid ];
			}
		}
		return $names;
	}

	/**
	 * Case-insensitive, multibyte-safe substring check.
	 *
	 * @param string $haystack Text to search.
	 * @param string $needle   Search term.
	 * @return bool Whether the needle appears in the haystack.
	 */
	private static function contains( string $haystack, string $needle ): bool {
		if ( function_exists( 'mb_stripos' ) ) {
			return false !== mb_stripos( $haystack, $needle );
		}
		return false !== stripos( $haystack, $needle );
	}
}

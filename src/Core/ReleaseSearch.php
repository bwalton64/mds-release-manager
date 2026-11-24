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
	 * Maximum number of Releases loaded for fallback in-memory matching.
	 *
	 * @var int
	 */
	private const CATALOG_LIMIT = 500;

	/**
	 * Maximum catalog size preloaded client-side in the block editor.
	 * Larger catalogs automatically use on-demand SQL-backed REST search.
	 *
	 * @var int
	 */
	public const PRELOAD_LIMIT = 200;

	/**
	 * Default picker page size.
	 *
	 * @var int
	 */
	public const PER_PAGE = 20;

	/**
	 * Searches published Releases with SQL-backed pagination and optional extension meta filtering.
	 *
	 * Scales to 10,000+ Releases without loading the full catalog into PHP memory.
	 *
	 * @since 2.0.0
	 *
	 * @param array<string, mixed> $args {
	 *     Optional search arguments.
	 *
	 *     @type string $search                Search term (title, Artist name, or exact UPC/EAN).
	 *     @type int    $page                  Page number starting at 1.
	 *     @type int    $per_page              Items per page (1-100, default 20).
	 *     @type string $required_meta_key     Optional post meta key that must match.
	 *     @type string $required_meta_compare Optional comparison (`!=`, `=`, `EXISTS`). Default `!=`.
	 *     @type string $required_meta_value   Optional value to compare against. Default `''`.
	 * }
	 * @return array{items: array<int, array<string, mixed>>, pages: int, total: int} Paginated picker records.
	 */
	public static function search_paginated( array $args = array() ): array {
		global $wpdb;

		$term     = isset( $args['search'] ) && is_string( $args['search'] ) ? trim( $args['search'] ) : '';
		$page     = max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 );
		$per_page = min( self::PRELOAD_LIMIT, max( 1, isset( $args['per_page'] ) ? (int) $args['per_page'] : self::PER_PAGE ) );
		$req_key  = isset( $args['required_meta_key'] ) && is_string( $args['required_meta_key'] ) ? trim( $args['required_meta_key'] ) : '';
		$req_cmp  = isset( $args['required_meta_compare'] ) && is_string( $args['required_meta_compare'] ) ? strtoupper( trim( $args['required_meta_compare'] ) ) : '!=';
		$req_val  = isset( $args['required_meta_value'] ) && is_scalar( $args['required_meta_value'] ) ? (string) $args['required_meta_value'] : '';

		if ( ! in_array( $req_cmp, array( '!=', '=', 'EXISTS' ), true ) ) {
			$req_cmp = '!=';
		}

		// Standalone unit-test fallback when WordPress DB is not booted.
		if ( ! ( $wpdb instanceof \wpdb ) || ! class_exists( '\WP_Query' ) ) {
			$found = self::query( $term );
			if ( '' !== $req_key ) {
				$found = array_values(
					array_filter(
						$found,
						static function ( \WP_Post $post ) use ( $req_key, $req_cmp, $req_val ): bool {
							$val = (string) get_post_meta( $post->ID, $req_key, true );
							if ( '=' === $req_cmp ) {
								return $val === $req_val;
							}
							return $val !== $req_val;
						}
					)
				);
			}
			$paged = self::paginate( $found, $page, $per_page );
			return array(
				'items' => self::describe( $paged['posts'] ),
				'pages' => $paged['pages'],
				'total' => count( $found ),
			);
		}

		$offset   = ( $page - 1 ) * $per_page;
		$joins    = '';
		$wheres   = array(
			"p.post_type = 'release'",
			"p.post_status = 'publish'",
			"p.post_password = ''",
		);
		$bindings = array();

		if ( '' !== $req_key ) {
			$joins     .= " INNER JOIN {$wpdb->postmeta} req_pm ON (req_pm.post_id = p.ID AND req_pm.meta_key = %s)";
			$bindings[] = $req_key;
			if ( '=' === $req_cmp ) {
				$wheres[]   = 'req_pm.meta_value = %s';
				$bindings[] = $req_val;
			} elseif ( '!=' === $req_cmp ) {
				$wheres[]   = 'req_pm.meta_value != %s';
				$bindings[] = $req_val;
			}
		}

		// All-digit search tries exact UPC/EAN match first (leading zeroes significant).
		if ( '' !== $term && ctype_digit( $term ) ) {
			$upc_joins    = $joins . " INNER JOIN {$wpdb->postmeta} upc_pm ON (upc_pm.post_id = p.ID AND upc_pm.meta_key IN ('_mds_normalized_upc', 'upc_ean'))";
			$upc_wheres   = array_merge( $wheres, array( 'upc_pm.meta_value = %s' ) );
			$upc_bindings = array_merge( $bindings, array( $term ) );
			$upc_where    = implode( ' AND ', $upc_wheres );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			$upc_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.ID FROM {$wpdb->posts} p {$upc_joins} WHERE {$upc_where} ORDER BY p.post_title ASC, p.ID ASC LIMIT %d OFFSET %d", ...array_merge( $upc_bindings, array( $per_page, $offset ) ) ) );
			if ( ! empty( $upc_ids ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$upc_total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p {$upc_joins} WHERE {$upc_where}", ...$upc_bindings ) );
				$posts     = self::hydrate_posts_in_order( array_map( 'intval', $upc_ids ) );
				return array(
					'items' => self::describe( $posts ),
					'pages' => (int) ceil( $upc_total / $per_page ),
					'total' => $upc_total,
				);
			}
		}

		if ( '' !== $term ) {
			$like = '%' . $wpdb->esc_like( $term ) . '%';

			// Find matching Artist UUIDs in one indexed query so Artist-name searches scale cleanly.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$artist_uuids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT am.meta_value
					 FROM {$wpdb->posts} ap
					 INNER JOIN {$wpdb->postmeta} am ON (am.post_id = ap.ID AND am.meta_key = '_mds_artist_uuid')
					 WHERE ap.post_type = 'artist'
					   AND ap.post_status = 'publish'
					   AND ap.post_password = ''
					   AND ap.post_title LIKE %s
					   AND am.meta_value != ''
					 LIMIT 250",
					$like
				)
			);

			if ( ! empty( $artist_uuids ) ) {
				$uuid_placeholders = implode( ',', array_fill( 0, count( $artist_uuids ), '%s' ) );
				$joins            .= " LEFT JOIN {$wpdb->postmeta} art_pm ON (art_pm.post_id = p.ID AND art_pm.meta_key = 'artist_id')";
				$wheres[]          = "(p.post_title LIKE %s OR art_pm.meta_value IN ({$uuid_placeholders}))";
				$bindings[]        = $like;
				foreach ( $artist_uuids as $uuid ) {
					$bindings[] = (string) $uuid;
				}
			} else {
				$wheres[]   = 'p.post_title LIKE %s';
				$bindings[] = $like;
			}
		}

		$where_sql = implode( ' AND ', $wheres );
		$count_sql = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p {$joins} WHERE {$where_sql}";
		$ids_sql   = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p {$joins} WHERE {$where_sql} ORDER BY p.post_title ASC, p.ID ASC LIMIT %d OFFSET %d";

		if ( ! empty( $bindings ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$bindings ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$post_ids = $wpdb->get_col( $wpdb->prepare( $ids_sql, ...array_merge( $bindings, array( $per_page, $offset ) ) ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$total = (int) $wpdb->get_var( $count_sql );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$post_ids = $wpdb->get_col( $wpdb->prepare( $ids_sql, $per_page, $offset ) );
		}

		$posts = self::hydrate_posts_in_order( array_map( 'intval', (array) $post_ids ) );

		return array(
			'items' => self::describe( $posts ),
			'pages' => $total > 0 ? (int) ceil( $total / $per_page ) : 0,
			'total' => $total,
		);
	}

	/**
	 * Searches published Artists with SQL-backed pagination.
	 *
	 * @since 2.0.0
	 *
	 * @param array<string, mixed> $args {
	 *     Optional search arguments.
	 *
	 *     @type string $search   Search term (Artist title).
	 *     @type int    $page     Page number starting at 1.
	 *     @type int    $per_page Items per page (1-100, default 20).
	 * }
	 * @return array{items: array<int, array<string, mixed>>, pages: int, total: int} Paginated Artist records.
	 */
	public static function search_artists_paginated( array $args = array() ): array {
		global $wpdb;

		$term     = isset( $args['search'] ) && is_string( $args['search'] ) ? trim( $args['search'] ) : '';
		$page     = max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 );
		$per_page = min( self::PRELOAD_LIMIT, max( 1, isset( $args['per_page'] ) ? (int) $args['per_page'] : self::PER_PAGE ) );

		if ( ! ( $wpdb instanceof \wpdb ) || ! class_exists( '\WP_Query' ) ) {
			$found = self::query_artists( $term );
			$paged = self::paginate( $found, $page, $per_page );
			return array(
				'items' => self::describe_artists( $paged['posts'] ),
				'pages' => $paged['pages'],
				'total' => count( $found ),
			);
		}

		$offset   = ( $page - 1 ) * $per_page;
		$wheres   = array(
			"post_type = 'artist'",
			"post_status = 'publish'",
			"post_password = ''",
		);
		$bindings = array();
		if ( '' !== $term ) {
			$wheres[]   = 'post_title LIKE %s';
			$bindings[] = '%' . $wpdb->esc_like( $term ) . '%';
		}

		$where_sql = implode( ' AND ', $wheres );
		$count_sql = "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE {$where_sql}";
		$ids_sql   = "SELECT ID FROM {$wpdb->posts} WHERE {$where_sql} ORDER BY post_title ASC, ID ASC LIMIT %d OFFSET %d";

		if ( ! empty( $bindings ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$bindings ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$post_ids = $wpdb->get_col( $wpdb->prepare( $ids_sql, ...array_merge( $bindings, array( $per_page, $offset ) ) ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$total = (int) $wpdb->get_var( $count_sql );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$post_ids = $wpdb->get_col( $wpdb->prepare( $ids_sql, $per_page, $offset ) );
		}

		$posts = self::hydrate_posts_in_order( array_map( 'intval', (array) $post_ids ) );

		return array(
			'items' => self::describe_artists( $posts ),
			'pages' => $total > 0 ? (int) ceil( $total / $per_page ) : 0,
			'total' => $total,
		);
	}

	/**
	 * Hydrates WP_Post objects in a preserved ID order with primed meta caches.
	 *
	 * @param int[] $post_ids Ordered post IDs.
	 * @return \WP_Post[] Hydrated post objects.
	 */
	private static function hydrate_posts_in_order( array $post_ids ): array {
		$post_ids = array_values( array_unique( array_filter( $post_ids, static fn( int $id ): bool => $id > 0 ) ) );
		if ( empty( $post_ids ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'              => array( 'release', 'artist' ),
				'post_status'            => 'publish',
				'post__in'               => $post_ids,
				'posts_per_page'         => count( $post_ids ),
				'orderby'                => 'post__in',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		return is_array( $posts ) ? $posts : array();
	}

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
	 * @return \WP_Post[] Matching Releases ordered by title.
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
	 * @param \WP_Post[] $posts    Matching Releases.
	 * @param int        $page     Requested page, starting at 1.
	 * @param int        $per_page Page size.
	 * @return array{posts: \WP_Post[], pages: int} The page of posts and the page count.
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
	 * @param \WP_Post[] $posts Releases.
	 * @return array[] Records with id, title, artist, and upc keys.
	 */
	public static function describe( array $posts ): array {
		if ( empty( $posts ) ) {
			return array();
		}

		if ( function_exists( 'do_action' ) ) {
			$release_ids = array_values( array_map( static fn( \WP_Post $post ): int => (int) $post->ID, $posts ) );
			/**
			 * Fires before Release picker items are described so extensions can batch-prime lookup maps.
			 *
			 * @since 2.0.0
			 * @param int[] $release_ids Release post IDs about to be described.
			 */
			do_action( 'mds_rm_prime_release_picker_items', $release_ids );
		}

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
					 * @param \WP_Post $post Release post object.
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
	 * @return \WP_Post[] Matching Artists ordered by title.
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
	 * @param \WP_Post[] $artists Artist posts.
	 * @return array<int, array<string, mixed>> Artist records with id, title, and uuid keys.
	 */
	public static function describe_artists( array $artists ): array {
		if ( empty( $artists ) ) {
			return array();
		}

		if ( function_exists( 'do_action' ) ) {
			$artist_ids = array_values( array_map( static fn( \WP_Post $artist ): int => (int) $artist->ID, $artists ) );
			/**
			 * Fires before Artist picker items are described so extensions can batch-prime lookup maps.
			 *
			 * @since 2.0.0
			 * @param int[] $artist_ids Artist post IDs about to be described.
			 */
			do_action( 'mds_rm_prime_artist_picker_items', $artist_ids );
		}

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
					 * @param \WP_Post $artist Artist post object.
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
		global $wpdb;

		if ( $wpdb instanceof \wpdb ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$release_total = (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'release' AND post_status = 'publish' AND post_password = ''" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$artist_total = (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'artist' AND post_status = 'publish' AND post_password = ''" );

			$releases_truncated = $release_total > self::PRELOAD_LIMIT;
			$artists_truncated  = $artist_total > self::PRELOAD_LIMIT;

			$release_items = array();
			if ( ! $releases_truncated && $release_total > 0 ) {
				$release_page  = self::search_paginated(
					array(
						'page'     => 1,
						'per_page' => self::PRELOAD_LIMIT,
					)
				);
				$release_items = $release_page['items'];
			}

			$artist_items = array();
			if ( ! $artists_truncated && $artist_total > 0 ) {
				$artist_page  = self::search_artists_paginated(
					array(
						'page'     => 1,
						'per_page' => self::PRELOAD_LIMIT,
					)
				);
				$artist_items = $artist_page['items'];
			}

			return array(
				'items'            => $release_items,
				'truncated'        => $releases_truncated,
				'artists'          => $artist_items,
				'artistsTruncated' => $artists_truncated,
			);
		}

		$posts   = self::query( '' );
		$artists = self::query_artists( '' );
		return array(
			'items'            => self::describe( $posts ),
			'truncated'        => count( $posts ) > self::PRELOAD_LIMIT,
			'artists'          => self::describe_artists( $artists ),
			'artistsTruncated' => count( $artists ) > self::PRELOAD_LIMIT,
		);
	}

	/**
	 * Resolves Artist display names for a set of Releases in one lookup.
	 *
	 * @param \WP_Post[] $posts Releases.
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

<?php
/**
 * Public Catalog / Discography query engine and cached facet provider.
 *
 * Powers the `mds-rm/catalog-explorer` block, `[catalog-explorer]` shortcode,
 * and `GET /mds/v1/catalog` REST endpoint with indexed taxonomy/date filters,
 * Artist/UPC search, sorting, pagination, and cache-friendly transient facets.
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queries published releases with multi-faceted filtering, sorting, and pagination.
 *
 * @since 2.1.0
 */
final class CatalogQuery {

	/**
	 * Transient prefix for cached facet lists.
	 *
	 * @var string
	 */
	public const FACET_TRANSIENT_PREFIX = 'mds_rm_catalog_facets_';

	/**
	 * Allowed sort keys.
	 *
	 * @var string[]
	 */
	public const VALID_SORTS = array( 'date_desc', 'date_asc', 'title_asc', 'artist_asc' );

	/**
	 * Registers cache invalidation hooks for facet transients.
	 *
	 * @since 2.1.0
	 */
	public static function register_hooks(): void {
		add_action( 'save_post_release', array( self::class, 'clear_facet_cache' ) );
		add_action( 'save_post_artist', array( self::class, 'clear_facet_cache' ) );
		add_action( 'deleted_post', array( self::class, 'clear_facet_cache' ) );
		add_action( 'mds_rm_after_cache_purge', array( self::class, 'clear_facet_cache' ) );
	}

	/**
	 * Normalizes and sanitizes incoming catalog query parameters.
	 *
	 * @since 2.1.0
	 * @param array<string, mixed> $raw Raw query parameters (from REST, URL query, or block attrs).
	 * @return array{search:string,artist:string,type:string,year:int,tag:string,label:string,format:string,sort:string,page:int,per_page:int}
	 */
	public static function normalize_args( array $raw ): array {
		$search   = isset( $raw['search'] ) ? sanitize_text_field( (string) $raw['search'] ) : ( isset( $raw['q'] ) ? sanitize_text_field( (string) $raw['q'] ) : '' );
		$artist   = isset( $raw['artist'] ) ? sanitize_text_field( (string) $raw['artist'] ) : '';
		$type     = isset( $raw['type'] ) ? sanitize_title( (string) $raw['type'] ) : '';
		$year     = isset( $raw['year'] ) ? absint( $raw['year'] ) : 0;
		$tag      = isset( $raw['tag'] ) ? sanitize_title( (string) $raw['tag'] ) : '';
		$label    = isset( $raw['label'] ) ? sanitize_text_field( (string) $raw['label'] ) : '';
		$format   = isset( $raw['format'] ) ? sanitize_title( (string) $raw['format'] ) : '';
		$sort     = isset( $raw['sort'] ) ? sanitize_key( (string) $raw['sort'] ) : 'date_desc';
		$page     = max( 1, isset( $raw['page'] ) ? absint( $raw['page'] ) : ( isset( $raw['paged'] ) ? absint( $raw['paged'] ) : 1 ) );
		$per_page = isset( $raw['per_page'] ) ? absint( $raw['per_page'] ) : 12;

		if ( ! in_array( $sort, self::VALID_SORTS, true ) ) {
			$sort = 'date_desc';
		}
		if ( $year < 1900 || $year > 2100 ) {
			$year = 0;
		}
		$per_page = min( 48, max( 1, $per_page ) );

		return array(
			'search'   => trim( $search ),
			'artist'   => trim( $artist ),
			'type'     => $type,
			'year'     => $year,
			'tag'      => $tag,
			'label'    => trim( $label ),
			'format'   => $format,
			'sort'     => $sort,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Resolves an Artist filter value (numeric post ID or UUID string) to an `_mds_artist_uuid`.
	 *
	 * @since 2.1.0
	 * @param string $artist_input Artist post ID or UUID.
	 * @return string Artist UUID or empty string.
	 */
	public static function resolve_artist_uuid( string $artist_input ): string {
		$trimmed = trim( $artist_input );
		if ( '' === $trimmed ) {
			return '';
		}
		if ( ctype_digit( $trimmed ) && function_exists( 'get_post_meta' ) ) {
			$uuid = (string) get_post_meta( (int) $trimmed, '_mds_artist_uuid', true );
			return trim( $uuid );
		}
		return $trimmed;
	}

	/**
	 * Executes a filtered, sorted, paginated query of published releases.
	 *
	 * @since 2.1.0
	 * @param array<string, mixed> $raw_args Query arguments.
	 * @return array{items:array<int,array<string,mixed>>,total:int,pages:int,page:int,per_page:int,args:array<string,mixed>}
	 */
	public static function query( array $raw_args = array() ): array {
		global $wpdb;

		$args = self::normalize_args( $raw_args );

		if ( ! ( $wpdb instanceof \wpdb ) || ! class_exists( '\WP_Query' ) ) {
			return array(
				'items'    => array(),
				'total'    => 0,
				'pages'    => 0,
				'page'     => $args['page'],
				'per_page' => $args['per_page'],
				'args'     => $args,
			);
		}

		$joins    = '';
		$wheres   = array(
			"p.post_type = 'release'",
			"p.post_status = 'publish'",
			"p.post_password = ''",
		);
		$bindings = array();

		// 1. Artist filter (UUID or post ID).
		$artist_uuid = self::resolve_artist_uuid( $args['artist'] );
		if ( '' !== $artist_uuid ) {
			$joins     .= " INNER JOIN {$wpdb->postmeta} art_pm ON (art_pm.post_id = p.ID AND art_pm.meta_key = 'artist_id')";
			$wheres[]   = 'art_pm.meta_value = %s';
			$bindings[] = $artist_uuid;
		}

		// 2. Release Type taxonomy filter.
		if ( '' !== $args['type'] ) {
			$joins     .= " INNER JOIN {$wpdb->term_relationships} tr_type ON (tr_type.object_id = p.ID)";
			$joins     .= " INNER JOIN {$wpdb->term_taxonomy} tt_type ON (tt_type.term_taxonomy_id = tr_type.term_taxonomy_id AND tt_type.taxonomy = 'release_type')";
			$joins     .= " INNER JOIN {$wpdb->terms} t_type ON (t_type.term_id = tt_type.term_id)";
			$wheres[]   = 't_type.slug = %s';
			$bindings[] = $args['type'];
		}

		// 3. Release Tag taxonomy filter.
		if ( '' !== $args['tag'] ) {
			$joins     .= " INNER JOIN {$wpdb->term_relationships} tr_tag ON (tr_tag.object_id = p.ID)";
			$joins     .= " INNER JOIN {$wpdb->term_taxonomy} tt_tag ON (tt_tag.term_taxonomy_id = tr_tag.term_taxonomy_id AND tt_tag.taxonomy = 'release_tag')";
			$joins     .= " INNER JOIN {$wpdb->terms} t_tag ON (t_tag.term_id = tt_tag.term_id)";
			$wheres[]   = 't_tag.slug = %s';
			$bindings[] = $args['tag'];
		}

		// 4. Media Format taxonomy filter (or Buy Now format slug).
		if ( '' !== $args['format'] ) {
			$joins     .= " INNER JOIN {$wpdb->term_relationships} tr_fmt ON (tr_fmt.object_id = p.ID)";
			$joins     .= " INNER JOIN {$wpdb->term_taxonomy} tt_fmt ON (tt_fmt.term_taxonomy_id = tr_fmt.term_taxonomy_id AND tt_fmt.taxonomy = 'release_media_format')";
			$joins     .= " INNER JOIN {$wpdb->terms} t_fmt ON (t_fmt.term_id = tt_fmt.term_id)";
			$wheres[]   = 't_fmt.slug = %s';
			$bindings[] = $args['format'];
		}

		// 5. Release Year filter.
		if ( $args['year'] > 0 ) {
			$wheres[]   = 'YEAR(p.post_date) = %d';
			$bindings[] = $args['year'];
		}

		// 6. Record Label filter.
		if ( '' !== $args['label'] ) {
			$joins     .= " INNER JOIN {$wpdb->postmeta} lbl_pm ON (lbl_pm.post_id = p.ID AND lbl_pm.meta_key = 'record_label')";
			$wheres[]   = 'lbl_pm.meta_value = %s';
			$bindings[] = $args['label'];
		}

		// 7. Search (Title, Artist Name, or UPC/EAN).
		if ( '' !== $args['search'] ) {
			$term = $args['search'];
			$like = '%' . $wpdb->esc_like( $term ) . '%';

			// Match Artist UUIDs whose title matches the search query.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$matched_artist_uuids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT am.meta_value
					 FROM {$wpdb->posts} ap
					 INNER JOIN {$wpdb->postmeta} am ON (am.post_id = ap.ID AND am.meta_key = '_mds_artist_uuid')
					 WHERE ap.post_type = 'artist'
					   AND ap.post_status = 'publish'
					   AND ap.post_title LIKE %s",
					$like
				)
			);

			$search_clauses = array( 'p.post_title LIKE %s' );
			$bindings[]     = $like;

			if ( ctype_digit( $term ) ) {
				$joins           .= " LEFT JOIN {$wpdb->postmeta} upc_s_pm ON (upc_s_pm.post_id = p.ID AND upc_s_pm.meta_key IN ('_mds_normalized_upc', 'upc_ean'))";
				$search_clauses[] = 'upc_s_pm.meta_value LIKE %s';
				$bindings[]       = $like;
			}

			if ( ! empty( $matched_artist_uuids ) ) {
				$joins           .= " LEFT JOIN {$wpdb->postmeta} art_s_pm ON (art_s_pm.post_id = p.ID AND art_s_pm.meta_key = 'artist_id')";
				$placeholders     = implode( ', ', array_fill( 0, count( $matched_artist_uuids ), '%s' ) );
				$search_clauses[] = "art_s_pm.meta_value IN ({$placeholders})";
				foreach ( $matched_artist_uuids as $m_uuid ) {
					$bindings[] = (string) $m_uuid;
				}
			}

			$wheres[] = '(' . implode( ' OR ', $search_clauses ) . ')';
		}

		// 8. Sorting.
		$order_by = 'p.post_date DESC, p.ID DESC';
		if ( 'date_asc' === $args['sort'] ) {
			$order_by = 'p.post_date ASC, p.ID ASC';
		} elseif ( 'title_asc' === $args['sort'] ) {
			$order_by = 'p.post_title ASC, p.ID ASC';
		} elseif ( 'artist_asc' === $args['sort'] ) {
			$joins   .= " LEFT JOIN {$wpdb->postmeta} sort_art_pm ON (sort_art_pm.post_id = p.ID AND sort_art_pm.meta_key = 'artist_id')";
			$joins   .= " LEFT JOIN {$wpdb->postmeta} sort_ap_pm ON (sort_ap_pm.meta_key = '_mds_artist_uuid' AND sort_ap_pm.meta_value = sort_art_pm.meta_value)";
			$joins   .= " LEFT JOIN {$wpdb->posts} sort_ap ON (sort_ap.ID = sort_ap_pm.post_id AND sort_ap.post_type = 'artist')";
			$order_by = 'COALESCE(sort_ap.post_title, p.post_title) ASC, p.post_date DESC';
		}

		$where_sql = implode( ' AND ', $wheres );
		$offset    = ( $args['page'] - 1 ) * $args['per_page'];

		$count_sql = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p {$joins} WHERE {$where_sql}";
		if ( ! empty( $bindings ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$bindings ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$total = (int) $wpdb->get_var( $count_sql );
		}

		$pages = $total > 0 ? (int) ceil( $total / $args['per_page'] ) : 0;
		if ( 0 === $total ) {
			return array(
				'items'    => array(),
				'total'    => 0,
				'pages'    => 0,
				'page'     => $args['page'],
				'per_page' => $args['per_page'],
				'args'     => $args,
			);
		}

		$ids_sql      = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p {$joins} WHERE {$where_sql} ORDER BY {$order_by} LIMIT %d OFFSET %d";
		$ids_bindings = array_merge( $bindings, array( $args['per_page'], $offset ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $ids_sql, ...$ids_bindings ) ) );

		return array(
			'items'    => self::hydrate_cards( $ids ),
			'total'    => $total,
			'pages'    => $pages,
			'page'     => $args['page'],
			'per_page' => $args['per_page'],
			'args'     => $args,
		);
	}

	/**
	 * Hydrates an ordered list of Release post IDs into card payloads with batch artist & meta priming.
	 *
	 * @since 2.1.0
	 * @param int[] $post_ids Ordered release post IDs.
	 * @return array<int, array<string, mixed>>
	 */
	private static function hydrate_cards( array $post_ids ): array {
		if ( empty( $post_ids ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'      => 'release',
				'post_status'    => 'publish',
				'post__in'       => $post_ids,
				'orderby'        => 'post__in',
				'posts_per_page' => count( $post_ids ),
				'no_found_rows'  => true,
			)
		);

		$artist_uuids = array();
		foreach ( $posts as $post ) {
			$uuid = (string) get_post_meta( $post->ID, 'artist_id', true );
			if ( '' !== $uuid ) {
				$artist_uuids[] = $uuid;
			}
		}
		$artist_uuids = array_values( array_unique( $artist_uuids ) );

		$artists_by_uuid = array();
		if ( ! empty( $artist_uuids ) ) {
			$artist_posts = get_posts(
				array(
					'post_type'      => 'artist',
					'post_status'    => 'publish',
					'posts_per_page' => count( $artist_uuids ),
					'no_found_rows'  => true,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Batch-resolves artists for current catalog page.
						array(
							'key'     => '_mds_artist_uuid',
							'value'   => $artist_uuids,
							'compare' => 'IN',
						),
					),
				)
			);
			foreach ( $artist_posts as $artist_post ) {
				$a_uuid = (string) get_post_meta( $artist_post->ID, '_mds_artist_uuid', true );
				if ( '' !== $a_uuid ) {
					$artists_by_uuid[ $a_uuid ] = array(
						'name' => $artist_post->post_title,
						'url'  => (string) get_permalink( $artist_post->ID ),
					);
				}
			}
		}

		$cards = array();
		foreach ( $posts as $post ) {
			$post_id      = (int) $post->ID;
			$a_uuid       = (string) get_post_meta( $post_id, 'artist_id', true );
			$artist_info  = $artists_by_uuid[ $a_uuid ] ?? array(
				'name' => '',
				'url'  => '',
			);
			$type_name    = ReleaseModel::format_name( $post_id );
			$release_type = is_string( $type_name ) ? $type_name : '';

			$cover_url = (string) get_the_post_thumbnail_url( $post_id, 'medium_large' );
			if ( '' === $cover_url ) {
				$cover_url = (string) get_post_meta( $post_id, 'featured_image_url', true );
			}

			$routed_url  = \MDS\ReleaseManager\Frontend\ArtistRouting::resolve_release_redirect( $post_id );
			$release_url = '' !== $routed_url ? $routed_url : (string) get_permalink( $post_id );
			$is_external = '' !== $routed_url;

			$raw_links = (string) get_post_meta( $post_id, 'streaming_links', true );
			$decoded   = json_decode( $raw_links, true );
			$links_out = array();
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $prov => $url ) {
					$clean_u = esc_url_raw( trim( (string) $url ) );
					if ( '' !== $clean_u ) {
						$links_out[ sanitize_text_field( (string) $prov ) ] = $clean_u;
					}
				}
			}

			$buy_now_url = ReleaseModel::get_primary_buy_now_url( $post_id );
			$has_audio   = \MDS\ReleaseManager\Core\Audio\AudioApi::has_audio( $post_id );

			$cards[] = array(
				'id'             => $post_id,
				'title'          => $post->post_title,
				'url'            => $release_url,
				'isExternal'     => $is_external,
				'artistName'     => $artist_info['name'],
				'artistUrl'      => $artist_info['url'],
				'year'           => (string) mysql2date( 'Y', $post->post_date ),
				'releaseDate'    => (string) mysql2date( 'Y-m-d', $post->post_date ),
				'releaseType'    => $release_type,
				'recordLabel'    => (string) get_post_meta( $post_id, 'record_label', true ),
				'upc'            => (string) get_post_meta( $post_id, 'upc_ean', true ),
				'coverUrl'       => esc_url_raw( $cover_url ),
				'buyNowUrl'      => esc_url_raw( $buy_now_url ),
				'streamingLinks' => $links_out,
				'hasAudio'       => $has_audio,
			);
		}

		return $cards;
	}

	/**
	 * Returns transient-cached facet filter choices for the catalog explorer.
	 *
	 * @since 2.1.0
	 * @param string $scoped_artist_uuid Optional artist UUID when scoped to one artist.
	 * @return array{artists:array<int,array{value:string,label:string}>,types:array<int,array{value:string,label:string}>,years:int[],tags:array<int,array{value:string,label:string}>,labels:string[],formats:array<int,array{value:string,label:string}>}
	 */
	public static function get_facets( string $scoped_artist_uuid = '' ): array {
		global $wpdb;

		$cache_key = self::FACET_TRANSIENT_PREFIX . md5( $scoped_artist_uuid );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$artists = array();
		if ( '' === $scoped_artist_uuid ) {
			$artist_posts = get_posts(
				array(
					'post_type'      => 'artist',
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'orderby'        => 'title',
					'order'          => 'ASC',
					'no_found_rows'  => true,
				)
			);
			foreach ( $artist_posts as $ap ) {
				$uuid = (string) get_post_meta( $ap->ID, '_mds_artist_uuid', true );
				if ( '' !== $uuid ) {
					$artists[] = array(
						'value' => $uuid,
						'label' => $ap->post_title,
					);
				}
			}
		}

		$terms_to_options = static function ( string $taxonomy ): array {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => true,
					'orderby'    => 'name',
					'order'      => 'ASC',
				)
			);
			if ( ! is_array( $terms ) ) {
				return array();
			}
			$out = array();
			foreach ( $terms as $term ) {
				$out[] = array(
					'value' => (string) $term->slug,
					'label' => (string) $term->name,
				);
			}
			return $out;
		};

		$years  = array();
		$labels = array();
		if ( $wpdb instanceof \wpdb ) {
			if ( '' !== $scoped_artist_uuid ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$raw_years = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT YEAR(p.post_date) AS y
						 FROM {$wpdb->posts} p
						 INNER JOIN {$wpdb->postmeta} am ON (am.post_id = p.ID AND am.meta_key = 'artist_id' AND am.meta_value = %s)
						 WHERE p.post_type = 'release' AND p.post_status = 'publish' AND p.post_password = ''
						 ORDER BY y DESC",
						$scoped_artist_uuid
					)
				);
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$raw_labels = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT lm.meta_value
						 FROM {$wpdb->posts} p
						 INNER JOIN {$wpdb->postmeta} am ON (am.post_id = p.ID AND am.meta_key = 'artist_id' AND am.meta_value = %s)
						 INNER JOIN {$wpdb->postmeta} lm ON (lm.post_id = p.ID AND lm.meta_key = 'record_label')
						 WHERE p.post_type = 'release' AND p.post_status = 'publish' AND p.post_password = '' AND lm.meta_value != ''
						 ORDER BY lm.meta_value ASC",
						$scoped_artist_uuid
					)
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$raw_years = $wpdb->get_col(
					"SELECT DISTINCT YEAR(post_date) AS y
					 FROM {$wpdb->posts}
					 WHERE post_type = 'release' AND post_status = 'publish' AND post_password = ''
					 ORDER BY y DESC"
				);
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$raw_labels = $wpdb->get_col(
					"SELECT DISTINCT lm.meta_value
					 FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->postmeta} lm ON (lm.post_id = p.ID AND lm.meta_key = 'record_label')
					 WHERE p.post_type = 'release' AND p.post_status = 'publish' AND p.post_password = '' AND lm.meta_value != ''
					 ORDER BY lm.meta_value ASC"
				);
			}

			$years  = array_values( array_filter( array_map( 'intval', (array) $raw_years ) ) );
			$labels = array_values( array_filter( array_map( 'trim', array_map( 'strval', (array) $raw_labels ) ) ) );
		}

		$facets = array(
			'artists' => $artists,
			'types'   => $terms_to_options( 'release_type' ),
			'years'   => $years,
			'tags'    => $terms_to_options( 'release_tag' ),
			'labels'  => $labels,
			'formats' => $terms_to_options( 'release_media_format' ),
		);

		set_transient( $cache_key, $facets, 15 * MINUTE_IN_SECONDS );
		return $facets;
	}

	/**
	 * Clears cached catalog facet transients on the current site.
	 *
	 * @since 2.1.0
	 */
	public static function clear_facet_cache(): void {
		global $wpdb;
		if ( ! ( $wpdb instanceof \wpdb ) ) {
			return;
		}
		$prefix = $wpdb->esc_like( '_transient_' . self::FACET_TRANSIENT_PREFIX ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$option_names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$prefix
			)
		);
		foreach ( (array) $option_names as $option_name ) {
			$transient_name = substr( (string) $option_name, strlen( '_transient_' ) );
			if ( '' !== $transient_name ) {
				delete_transient( $transient_name );
			}
		}
	}
}

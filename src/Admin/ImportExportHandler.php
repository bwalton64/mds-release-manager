<?php
/**
 * Handles CSV import and export for release posts.
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles multi-format (CSV, JSON, XML) catalog import, export, and purge operations.
 *
 * @since 0.9.0
 */
final class ImportExportHandler {

	/**
	 * Registers the CSV page and form handlers.
	 *
	 * @since 0.9.0
	 */
	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'register_csv_page' ) );
		add_action( 'rest_api_init', array( self::class, 'register_rest_routes' ) );
	}

	/**
	 * Registers REST API endpoints for the interactive CSV import wizard and catalog purge.
	 *
	 * @since 2.0.2
	 */
	public static function register_rest_routes(): void {
		$permission_callback = array( self::class, 'rest_can_manage_csv' );

		register_rest_route(
			'mds/v1',
			'/csv/suggest-map',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'rest_suggest_map' ),
				'permission_callback' => $permission_callback,
			)
		);

		register_rest_route(
			'mds/v1',
			'/csv/ai-map',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'rest_ai_map' ),
				'permission_callback' => $permission_callback,
			)
		);

		register_rest_route(
			'mds/v1',
			'/csv/purge',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'rest_purge_catalog' ),
				'permission_callback' => $permission_callback,
			)
		);

		register_rest_route(
			'mds/v1',
			'/csv/import-batch',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'rest_import_batch' ),
				'permission_callback' => $permission_callback,
			)
		);

		register_rest_route(
			'mds/v1',
			'/csv/export',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'rest_export_catalog' ),
				'permission_callback' => $permission_callback,
			)
		);
	}

	/**
	 * Permission callback for CSV wizard REST routes (Hub-only, manage_options).
	 *
	 * @since 2.0.2
	 * @return bool|\WP_Error True when permitted, or WP_Error.
	 */
	public static function rest_can_manage_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'mds_csv_forbidden',
				__( 'You are not allowed to manage CSV data.', 'mds-release-manager' ),
				array( 'status' => 403 )
			);
		}

		if ( ! \MDS\ReleaseManager\Core\Sync::is_hub() ) {
			return new \WP_Error(
				'mds_csv_hub_only',
				__( 'CSV import and catalog purge are only available on the Hub site.', 'mds-release-manager' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Returns counts of existing releases and artists on the current site.
	 *
	 * @since 2.0.2
	 * @return array{releases:int,artists:int,spokes:int}
	 */
	public static function catalog_counts(): array {
		$count_post_type = static function ( string $post_type ): int {
			$counts = wp_count_posts( $post_type );
			if ( ! is_object( $counts ) ) {
				return 0;
			}
			$total = 0;
			foreach ( array( 'publish', 'draft', 'pending', 'future', 'private', 'trash' ) as $status ) {
				if ( isset( $counts->$status ) ) {
					$total += (int) $counts->$status;
				}
			}
			return $total;
		};

		return array(
			'releases' => $count_post_type( 'release' ),
			'artists'  => $count_post_type( 'artist' ),
			'spokes'   => count( \MDS\ReleaseManager\Core\Sync::base_spoke_sites() ),
		);
	}

	/**
	 * REST handler: returns heuristic column-mapping suggestions for CSV headers and sample rows.
	 *
	 * @since 2.0.2
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_suggest_map( \WP_REST_Request $request ) {
		$headers     = $request->get_param( 'headers' );
		$sample_rows = $request->get_param( 'sample_rows' );

		if ( ! is_array( $headers ) || empty( $headers ) ) {
			return new \WP_Error(
				'mds_csv_invalid_headers',
				__( 'Please provide at least one CSV header column.', 'mds-release-manager' ),
				array( 'status' => 400 )
			);
		}

		$clean_headers = array_values( array_map( 'strval', $headers ) );
		$clean_samples = is_array( $sample_rows ) ? array_slice( $sample_rows, 0, 5 ) : array();

		return rest_ensure_response(
			array(
				'suggestions'   => ImportFieldMapper::suggest_mapping( $clean_headers, $clean_samples ),
				'target_fields' => ImportFieldMapper::target_fields(),
				'ai_status'     => ImportFieldMapper::ai_status(),
			)
		);
	}

	/**
	 * REST handler: invokes optional AI-assisted column mapping.
	 *
	 * @since 2.0.2
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_ai_map( \WP_REST_Request $request ) {
		$headers     = $request->get_param( 'headers' );
		$sample_rows = $request->get_param( 'sample_rows' );

		if ( ! is_array( $headers ) || empty( $headers ) ) {
			return new \WP_Error(
				'mds_csv_invalid_headers',
				__( 'Please provide at least one CSV header column.', 'mds-release-manager' ),
				array( 'status' => 400 )
			);
		}

		$clean_headers = array_values( array_map( 'strval', $headers ) );
		$clean_samples = is_array( $sample_rows ) ? array_slice( $sample_rows, 0, 5 ) : array();
		$result        = ImportFieldMapper::ai_suggest_mapping( $clean_headers, $clean_samples );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'suggestions' => $result['mapping'] ?? array(),
				'provider'    => $result['provider'] ?? '',
				'ai_status'   => ImportFieldMapper::ai_status(),
			)
		);
	}

	/**
	 * REST handler: purges a bounded batch of release and artist posts on Hub and Spokes.
	 *
	 * @since 2.0.2
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_purge_catalog( \WP_REST_Request $request ) {
		$confirm = strtoupper( trim( (string) $request->get_param( 'confirm' ) ) );
		if ( 'PURGE' !== $confirm ) {
			return new \WP_Error(
				'mds_csv_purge_confirm_required',
				__( 'You must confirm the purge by typing PURGE.', 'mds-release-manager' ),
				array( 'status' => 400 )
			);
		}

		$batch_size = (int) $request->get_param( 'batch_size' );
		if ( $batch_size < 1 || $batch_size > 100 ) {
			$batch_size = 50;
		}

		return rest_ensure_response( self::purge_catalog_batch( $batch_size ) );
	}

	/**
	 * Deletes up to `$batch_size` release and artist posts on the Hub and each configured Spoke site.
	 *
	 * Strictly targets `release` and `artist` post types; never touches WooCommerce `product` posts.
	 *
	 * @since 2.0.2
	 * @param int $batch_size Maximum posts to delete per site in this call.
	 * @return array{deleted_hub:int,deleted_spokes:int,remaining_hub:int,remaining_spokes:int,done:bool,counts:array{releases:int,artists:int,spokes:int}}
	 */
	public static function purge_catalog_batch( int $batch_size = 50 ): array {
		$post_types     = array( 'release', 'artist' );
		$deleted_hub    = 0;
		$deleted_spokes = 0;

		$delete_batch_on_current_site = static function ( int $limit ) use ( $post_types ): int {
			$ids = get_posts(
				array(
					'post_type'              => $post_types,
					'post_status'            => array_keys( get_post_stati() ),
					'posts_per_page'         => $limit,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			$deleted = 0;
			foreach ( $ids as $post_id ) {
				if ( false !== wp_delete_post( (int) $post_id, true ) ) {
					++$deleted;
				}
			}
			return $deleted;
		};

		$count_remaining_on_current_site = static function () use ( $post_types ): int {
			$ids = get_posts(
				array(
					'post_type'              => $post_types,
					'post_status'            => array_keys( get_post_stati() ),
					'posts_per_page'         => 1,
					'fields'                 => 'ids',
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			return count( $ids );
		};

		// Temporarily pause per-post Hub delete fan-out so Spokes are purged cleanly in batches.
		$had_delete_hook = has_action( 'before_delete_post', array( \MDS\ReleaseManager\Core\Sync::class, 'on_hub_delete_post' ) );
		if ( false !== $had_delete_hook ) {
			remove_action( 'before_delete_post', array( \MDS\ReleaseManager\Core\Sync::class, 'on_hub_delete_post' ), 10 );
		}

		try {
			$deleted_hub = $delete_batch_on_current_site( $batch_size );
		} finally {
			if ( false !== $had_delete_hook ) {
				add_action( 'before_delete_post', array( \MDS\ReleaseManager\Core\Sync::class, 'on_hub_delete_post' ), 10, 1 );
			}
		}

		$remaining_hub    = $count_remaining_on_current_site();
		$remaining_spokes = 0;

		if ( is_multisite() ) {
			$spoke_sites = \MDS\ReleaseManager\Core\Sync::base_spoke_sites();
			foreach ( $spoke_sites as $spoke_blog_id ) {
				switch_to_blog( $spoke_blog_id );
				try {
					$deleted_spokes   += $delete_batch_on_current_site( $batch_size );
					$remaining_spokes += $count_remaining_on_current_site();
				} finally {
					restore_current_blog();
				}
			}
		}

		$done = ( 0 === $remaining_hub && 0 === $remaining_spokes );
		if ( $done ) {
			\MDS\ReleaseManager\Core\Sync::purge_cache( 'csv_purge' );
		}

		return array(
			'deleted_hub'      => $deleted_hub,
			'deleted_spokes'   => $deleted_spokes,
			'remaining_hub'    => $remaining_hub,
			'remaining_spokes' => $remaining_spokes,
			'done'             => $done,
			'counts'           => self::catalog_counts(),
		);
	}

	/**
	 * REST handler: imports a batch of pre-mapped rows from the CSV wizard.
	 *
	 * @since 2.0.2
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_import_batch( \WP_REST_Request $request ) {
		$rows = $request->get_param( 'rows' );
		if ( ! is_array( $rows ) ) {
			return new \WP_Error(
				'mds_csv_invalid_rows',
				__( 'Batch rows must be an array.', 'mds-release-manager' ),
				array( 'status' => 400 )
			);
		}

		if ( count( $rows ) > 50 ) {
			return new \WP_Error(
				'mds_csv_batch_too_large',
				__( 'Each import batch may contain at most 50 rows.', 'mds-release-manager' ),
				array( 'status' => 400 )
			);
		}

		$default_release_type = sanitize_text_field( (string) ( $request->get_param( 'default_release_type' ) ?? '' ) );
		$duplicate_mode       = sanitize_key( (string) ( $request->get_param( 'duplicate_mode' ) ?? 'skip_identical' ) );
		if ( ! in_array( $duplicate_mode, array( 'skip_identical', 'skip_existing', 'overwrite' ), true ) ) {
			$duplicate_mode = 'skip_identical';
		}
		$start_row_number = max( 1, (int) ( $request->get_param( 'start_row_number' ) ?? 2 ) );
		$is_last_batch    = (bool) $request->get_param( 'is_last_batch' );
		$backup_artists   = $request->get_param( 'artists' );
		$artists_list     = is_array( $backup_artists ) ? $backup_artists : array();

		$result           = self::import_mapped_rows( $rows, $default_release_type, $start_row_number, $is_last_batch, $duplicate_mode, $artists_list );
		$result['counts'] = self::catalog_counts();

		return rest_ensure_response( $result );
	}

	/**
	 * Imports a batch of canonically mapped release rows and broadcasts updates to Spokes.
	 *
	 * @since 2.0.2
	 * @param array<int, array<string, mixed>> $rows                 Canonically keyed rows.
	 * @param string                           $default_release_type Optional fallback Release Type name.
	 * @param int                              $start_row_number     1-based CSV row number for error reporting.
	 * @param bool                             $is_last_batch        Whether to purge caches after this batch.
	 * @param string                           $duplicate_mode       One of 'skip_identical', 'skip_existing', or 'overwrite'.
	 * @param array<int, array<string, mixed>> $backup_artists       Optional Artist records from JSON/XML backups to restore first.
	 * @return array{created:int,updated:int,unchanged:int,skipped_existing:int,skipped:int,artists_restored:int,errors:string[]}
	 */
	public static function import_mapped_rows(
		array $rows,
		string $default_release_type = '',
		int $start_row_number = 2,
		bool $is_last_batch = true,
		string $duplicate_mode = 'skip_identical',
		array $backup_artists = array()
	): array {
		$created             = 0;
		$updated             = 0;
		$unchanged           = 0;
		$skipped_existing    = 0;
		$skipped             = 0;
		$artists_restored    = 0;
		$errors              = array();
		$pending_sync_items  = array();
		$broadcasted_artists = array();

		if ( ! empty( $backup_artists ) ) {
			$artists_restored = self::upsert_backup_artists( $backup_artists, $pending_sync_items, $broadcasted_artists );
		}

		foreach ( array_values( $rows ) as $offset => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$row_number = isset( $row['_row_number'] ) && (int) $row['_row_number'] > 0
				? (int) $row['_row_number']
				: ( $start_row_number + $offset );
			$outcome    = self::upsert_mapped_row(
				$row,
				$row_number,
				$pending_sync_items,
				$broadcasted_artists,
				$default_release_type,
				$duplicate_mode
			);

			if ( 'created' === $outcome['status'] ) {
				++$created;
			} elseif ( 'updated' === $outcome['status'] ) {
				++$updated;
			} elseif ( 'unchanged' === $outcome['status'] ) {
				++$unchanged;
			} elseif ( 'skipped_existing' === $outcome['status'] ) {
				++$skipped_existing;
			} elseif ( 'skipped' === $outcome['status'] ) {
				++$skipped;
			} elseif ( '' !== $outcome['error'] ) {
				$errors[] = $outcome['error'];
			}

			if ( count( $pending_sync_items ) >= 25 ) {
				self::flush_pending_sync_items( $pending_sync_items, $errors );
			}
		}

		if ( ! empty( $pending_sync_items ) ) {
			self::flush_pending_sync_items( $pending_sync_items, $errors );
		}

		if ( $is_last_batch ) {
			\MDS\ReleaseManager\Core\Sync::purge_cache( 'csv_import' );
		}

		return array(
			'created'          => $created,
			'updated'          => $updated,
			'unchanged'        => $unchanged,
			'skipped_existing' => $skipped_existing,
			'skipped'          => $skipped,
			'artists_restored' => $artists_restored,
			'errors'           => $errors,
		);
	}

	/**
	 * Restores full Artist profiles (UUID, bio, website, portrait, routing policy) from JSON/XML backups.
	 *
	 * @since 2.0.2
	 * @param array<int, mixed>                             $artists             Artist records from backup.
	 * @param array<string, array{id:int,post_type:string}> $pending_sync_items  Sync queue accumulator.
	 * @param array<int, bool>                              $broadcasted_artists Artist IDs already queued for sync.
	 * @return int Count of artists created or updated.
	 */
	private static function upsert_backup_artists(
		array $artists,
		array &$pending_sync_items,
		array &$broadcasted_artists
	): int {
		$restored       = 0;
		$valid_policies = array( '', 'fallback', 'artist_website', 'buy_now_or_artist', 'buy_now_or_fallback' );

		foreach ( $artists as $artist ) {
			if ( ! is_array( $artist ) ) {
				continue;
			}
			$name = sanitize_text_field( (string) ( $artist['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			$uuid     = sanitize_text_field( (string) ( $artist['uuid'] ?? '' ) );
			$website  = esc_url_raw( (string) ( $artist['website'] ?? '' ) );
			$portrait = esc_url_raw( (string) ( $artist['portrait_url'] ?? '' ) );
			$bio      = isset( $artist['bio'] ) ? wp_kses_post( (string) $artist['bio'] ) : null;
			$policy   = sanitize_key( (string) ( $artist['routing_policy'] ?? '' ) );
			if ( ! in_array( $policy, $valid_policies, true ) ) {
				$policy = '';
			}

			$existing_id = 0;
			if ( '' !== $uuid ) {
				$by_uuid = get_posts(
					array(
						'post_type'      => 'artist',
						'post_status'    => 'any',
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'no_found_rows'  => true,
						'meta_key'       => '_mds_artist_uuid', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Matches restored artist by canonical UUID.
						'meta_value'     => $uuid, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					)
				);
				if ( ! empty( $by_uuid ) ) {
					$existing_id = (int) $by_uuid[0];
				}
			}

			if ( 0 === $existing_id ) {
				$by_title = get_posts(
					array(
						'post_type'   => 'artist',
						'title'       => $name,
						'post_status' => 'any',
						'numberposts' => 1,
						'fields'      => 'ids',
					)
				);
				if ( ! empty( $by_title ) ) {
					$existing_id = (int) $by_title[0];
				}
			}

			$post_args = array(
				'post_title'  => $name,
				'post_type'   => 'artist',
				'post_status' => 'publish',
			);
			if ( null !== $bio ) {
				$post_args['post_content'] = $bio;
			}

			if ( $existing_id > 0 ) {
				$post_args['ID'] = $existing_id;
				$artist_post_id  = (int) wp_update_post( $post_args );
			} else {
				$post_args['post_name'] = sanitize_title( (string) ( $artist['slug'] ?? $name ) );
				$artist_post_id         = (int) wp_insert_post( $post_args );
			}

			if ( $artist_post_id <= 0 ) {
				continue;
			}

			$stored_uuid = '' !== $uuid ? $uuid : (string) get_post_meta( $artist_post_id, '_mds_artist_uuid', true );
			if ( '' === $stored_uuid ) {
				$stored_uuid = wp_generate_uuid4();
			}
			update_post_meta( $artist_post_id, '_mds_artist_uuid', $stored_uuid );
			if ( '' !== $website ) {
				update_post_meta( $artist_post_id, 'artist_website', $website );
			}
			if ( '' !== $portrait ) {
				update_post_meta( $artist_post_id, 'artist_portrait_url', $portrait );
			}
			if ( '' !== $policy ) {
				update_post_meta( $artist_post_id, '_mds_artist_release_routing', $policy );
			}

			if ( ! isset( $broadcasted_artists[ $artist_post_id ] ) ) {
				$pending_sync_items[ 'artist:' . $artist_post_id ] = array(
					'id'        => $artist_post_id,
					'post_type' => 'artist',
				);
				$broadcasted_artists[ $artist_post_id ]            = true;
			}
			++$restored;
		}

		return $restored;
	}

	/**
	 * Safely parses an MDS XML catalog document into structured artists and releases arrays.
	 *
	 * Guards against XXE / entity-expansion payloads by rejecting DOCTYPE declarations and
	 * disabling network access via LIBXML_NONET.
	 *
	 * @since 2.0.2
	 * @param string $xml_string Raw XML document.
	 * @return array{artists:array<int,array<string,mixed>>,releases:array<int,array<string,mixed>>}|\WP_Error
	 */
	public static function parse_xml_catalog( string $xml_string ) {
		$trimmed = trim( $xml_string );
		if ( '' === $trimmed ) {
			return new \WP_Error(
				'mds_xml_empty',
				__( 'The XML file is empty.', 'mds-release-manager' ),
				array( 'status' => 400 )
			);
		}
		if ( preg_match( '/<!(?:DOCTYPE|ENTITY)\b/i', $trimmed ) ) {
			return new \WP_Error(
				'mds_xml_forbidden_dtd',
				__( 'XML files containing DOCTYPE or ENTITY declarations are not allowed.', 'mds-release-manager' ),
				array( 'status' => 400 )
			);
		}

		$prev_errors = libxml_use_internal_errors( true );
		try {
			$xml = simplexml_load_string( $trimmed, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $prev_errors );
		}

		if ( false === $xml ) {
			return new \WP_Error(
				'mds_xml_invalid',
				__( 'The XML file could not be parsed. Please ensure it is a valid MDS catalog XML export.', 'mds-release-manager' ),
				array( 'status' => 400 )
			);
		}

		$artists = array();
		if ( isset( $xml->artists->artist ) ) {
			foreach ( $xml->artists->artist as $artist_node ) {
				$artists[] = array(
					'uuid'           => trim( (string) $artist_node->uuid ),
					'name'           => trim( (string) $artist_node->name ),
					'slug'           => trim( (string) $artist_node->slug ),
					'status'         => trim( (string) $artist_node->status ),
					'website'        => trim( (string) $artist_node->website ),
					'portrait_url'   => trim( (string) $artist_node->portrait_url ),
					'routing_policy' => trim( (string) $artist_node->routing_policy ),
					'bio'            => (string) $artist_node->bio,
				);
			}
		}

		$releases = array();
		if ( isset( $xml->releases->release ) ) {
			foreach ( $xml->releases->release as $rel_node ) {
				$buy_now_links = array();
				if ( isset( $rel_node->buy_now_links->link ) ) {
					foreach ( $rel_node->buy_now_links->link as $b_node ) {
						$url = trim( (string) $b_node );
						if ( '' !== $url ) {
							$buy_now_links[] = array(
								'format' => trim( (string) $b_node['format'] ),
								'label'  => trim( (string) $b_node['label'] ),
								'url'    => $url,
							);
						}
					}
				}

				$streaming_links = array();
				if ( isset( $rel_node->streaming_links->link ) ) {
					foreach ( $rel_node->streaming_links->link as $s_node ) {
						$provider = trim( (string) $s_node['provider'] );
						$url      = trim( (string) $s_node );
						if ( '' !== $provider && '' !== $url ) {
							$streaming_links[ $provider ] = $url;
						}
					}
				}

				$releases[] = array(
					'upc_ean'            => trim( (string) $rel_node->upc_ean ),
					'title'              => trim( (string) $rel_node->title ),
					'artist_uuid'        => trim( (string) $rel_node->artist_uuid ),
					'artist'             => trim( (string) $rel_node->artist ),
					'release_date'       => trim( (string) $rel_node->release_date ),
					'release_type'       => trim( (string) $rel_node->release_type ),
					'status'             => trim( (string) $rel_node->status ),
					'record_label'       => trim( (string) $rel_node->record_label ),
					'distributor'        => trim( (string) $rel_node->distributor ),
					'buy_now_link'       => trim( (string) $rel_node->buy_now_link ),
					'buy_now_links'      => $buy_now_links,
					'tags'               => trim( (string) $rel_node->tags ),
					'featured_image_url' => trim( (string) $rel_node->featured_image_url ),
					'audio_preview_url'  => trim( (string) $rel_node->audio_preview_url ),
					'about'              => (string) $rel_node->about,
					'streaming_links'    => $streaming_links,
				);
			}
		}

		return array(
			'artists'  => $artists,
			'releases' => $releases,
		);
	}

	/**
	 * Compares an existing release snapshot against an incoming CSV row to detect identical records.
	 *
	 * Only compares optional fields (`featured_image_url`, `audio_preview_url`, `about`, `release_date`)
	 * when the incoming CSV row explicitly provides them.
	 *
	 * @since 2.0.2
	 * @param array<string, mixed> $existing Existing release field snapshot.
	 * @param array<string, mixed> $incoming Incoming normalized release field snapshot.
	 * @return bool True when all compared fields match and writing/syncing can be skipped.
	 */
	public static function is_release_identical( array $existing, array $incoming ): bool {
		$normalize_str = static function ( mixed $value ): string {
			return trim( (string) $value );
		};

		$normalize_tags = static function ( mixed $raw ): array {
			$parts = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
			$clean = array();
			foreach ( $parts as $tag ) {
				$t = strtolower( trim( (string) $tag ) );
				if ( '' !== $t ) {
					$clean[ $t ] = true;
				}
			}
			ksort( $clean );
			return array_keys( $clean );
		};

		$normalize_links = static function ( mixed $links ): array {
			if ( is_string( $links ) ) {
				$decoded = json_decode( $links, true );
				$links   = is_array( $decoded ) ? $decoded : array();
			}
			if ( ! is_array( $links ) ) {
				return array();
			}
			$clean = array();
			foreach ( $links as $provider => $url ) {
				$p = strtolower( trim( (string) $provider ) );
				$u = trim( (string) $url );
				if ( '' !== $p && '' !== $u ) {
					$clean[ $p ] = $u;
				}
			}
			ksort( $clean );
			return $clean;
		};

		if ( $normalize_str( $existing['title'] ?? '' ) !== $normalize_str( $incoming['title'] ?? '' ) ) {
			return false;
		}
		if ( strtolower( $normalize_str( $existing['artist_name'] ?? '' ) ) !== strtolower( $normalize_str( $incoming['artist_name'] ?? '' ) ) ) {
			return false;
		}
		if ( (int) ( $existing['release_type_id'] ?? 0 ) !== (int) ( $incoming['release_type_id'] ?? 0 ) ) {
			return false;
		}
		if ( $normalize_str( $existing['record_label'] ?? '' ) !== $normalize_str( $incoming['record_label'] ?? '' ) ) {
			return false;
		}
		if ( $normalize_str( $existing['distributor'] ?? '' ) !== $normalize_str( $incoming['distributor'] ?? '' ) ) {
			return false;
		}
		if ( $normalize_str( $existing['buy_now_link'] ?? '' ) !== $normalize_str( $incoming['buy_now_link'] ?? '' ) ) {
			return false;
		}
		if ( $normalize_tags( $existing['tags'] ?? '' ) !== $normalize_tags( $incoming['tags'] ?? '' ) ) {
			return false;
		}
		if ( $normalize_links( $existing['streaming_links'] ?? array() ) !== $normalize_links( $incoming['streaming_links'] ?? array() ) ) {
			return false;
		}

		// Optional date comparison: only compare when incoming CSV supplies a valid date.
		$incoming_date = $normalize_str( $incoming['release_date'] ?? '' );
		if ( '' !== $incoming_date ) {
			$existing_date = $normalize_str( $existing['release_date'] ?? '' );
			if ( substr( $existing_date, 0, 10 ) !== substr( $incoming_date, 0, 10 ) ) {
				return false;
			}
		}

		// Optional media/content columns: only compare when present in the CSV mapping.
		if ( array_key_exists( 'featured_image_url', $incoming ) && null !== $incoming['featured_image_url'] ) {
			if ( $normalize_str( $existing['featured_image_url'] ?? '' ) !== $normalize_str( $incoming['featured_image_url'] ) ) {
				return false;
			}
		}
		if ( array_key_exists( 'audio_preview_url', $incoming ) && null !== $incoming['audio_preview_url'] ) {
			if ( $normalize_str( $existing['audio_preview_url'] ?? '' ) !== $normalize_str( $incoming['audio_preview_url'] ) ) {
				return false;
			}
		}
		if ( array_key_exists( 'about', $incoming ) && null !== $incoming['about'] ) {
			if ( $normalize_str( $existing['about'] ?? '' ) !== $normalize_str( $incoming['about'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Builds a normalized snapshot of an existing Release post for identical-record comparison.
	 *
	 * @since 2.0.2
	 * @param int $release_post_id Existing Release post ID.
	 * @return array<string, mixed>
	 */
	private static function snapshot_existing_release( int $release_post_id ): array {
		$post            = get_post( $release_post_id );
		$artist_uuid     = (string) get_post_meta( $release_post_id, 'artist_id', true );
		$artist_name     = '';
		$release_type_id = 0;

		if ( '' !== $artist_uuid ) {
			$artists = get_posts(
				array(
					'post_type'      => 'artist',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'no_found_rows'  => true,
					'meta_key'       => '_mds_artist_uuid', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Resolves existing artist name by UUID during identical-record comparison.
					'meta_value'     => $artist_uuid, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
			if ( ! empty( $artists ) && $artists[0] instanceof \WP_Post ) {
				$artist_name = $artists[0]->post_title;
			}
		}

		$terms = wp_get_post_terms( $release_post_id, 'release_type', array( 'fields' => 'ids' ) );
		if ( is_array( $terms ) && ! empty( $terms ) ) {
			$release_type_id = (int) $terms[0];
		}

		$tag_terms = wp_get_post_terms( $release_post_id, 'release_tag', array( 'fields' => 'names' ) );
		$tags_list = is_array( $tag_terms ) ? $tag_terms : array();

		return array(
			'title'              => $post instanceof \WP_Post ? $post->post_title : '',
			'artist_name'        => $artist_name,
			'release_date'       => $post instanceof \WP_Post ? $post->post_date : '',
			'release_type_id'    => $release_type_id,
			'record_label'       => (string) get_post_meta( $release_post_id, 'record_label', true ),
			'distributor'        => (string) get_post_meta( $release_post_id, 'distributor', true ),
			'buy_now_link'       => (string) get_post_meta( $release_post_id, 'buy_now_link', true ),
			'tags'               => $tags_list,
			'featured_image_url' => (string) get_post_meta( $release_post_id, 'featured_image_url', true ),
			'audio_preview_url'  => (string) get_post_meta( $release_post_id, 'audio_preview_url', true ),
			'about'              => $post instanceof \WP_Post ? $post->post_content : '',
			'streaming_links'    => (string) get_post_meta( $release_post_id, 'streaming_links', true ),
		);
	}

	/**
	 * Broadcasts queued Artist/Release sync items to Spokes and records any sync errors.
	 *
	 * @since 2.0.2
	 * @param array<string, array{id:int,post_type:string}> $pending_sync_items Queued sync records (cleared on flush).
	 * @param string[]                                      $errors             Accumulated error messages.
	 */
	private static function flush_pending_sync_items( array &$pending_sync_items, array &$errors ): void {
		$batch_results = \MDS\ReleaseManager\Core\Sync::broadcast_batch_to_spokes( array_values( $pending_sync_items ) );
		foreach ( $batch_results as $sync_key => $sync_res ) {
			if ( is_wp_error( $sync_res ) ) {
				/* translators: %1$s: Record key. %2$s: Error message. */
				$errors[] = sprintf( __( '%1$s saved but synchronization failed: %2$s', 'mds-release-manager' ), $sync_key, $sync_res->get_error_message() );
			}
		}
		$pending_sync_items = array();
	}

	/**
	 * Upserts a single canonically mapped release row (and its Artist CPT if needed).
	 *
	 * @since 2.0.2
	 * @param array<string, mixed>                          $mapped               Canonical field map (upc_ean, release_title, artist, provider:*, etc.).
	 * @param int                                           $row_number           1-based CSV row number for error messages.
	 * @param array<string, array{id:int,post_type:string}> $pending_sync_items   Sync queue accumulator.
	 * @param array<int, bool>                              $broadcasted_artists  Artist IDs already queued for sync.
	 * @param string                                        $default_release_type Optional fallback Release Type name.
	 * @param string                                        $duplicate_mode       Duplicate handling mode ('skip_identical', 'skip_existing', 'overwrite').
	 * @return array{status:string,error:string}
	 */
	public static function upsert_mapped_row(
		array $mapped,
		int $row_number,
		array &$pending_sync_items,
		array &$broadcasted_artists,
		string $default_release_type = '',
		string $duplicate_mode = 'skip_identical'
	): array {
		$artist_name  = sanitize_text_field( (string) ( $mapped['artist'] ?? '' ) );
		$artist_name  = '' !== $artist_name ? $artist_name : 'Unknown Artist';
		$upc_raw      = sanitize_text_field( (string) ( $mapped['upc_ean'] ?? '' ) );
		$upc          = (string) preg_replace( '/[^0-9]/', '', $upc_raw );
		$title        = sanitize_text_field( (string) ( $mapped['title'] ?? ( $mapped['release_title'] ?? '' ) ) );
		$release_date = sanitize_text_field( (string) ( $mapped['release_date'] ?? '' ) );
		$format       = sanitize_text_field( (string) ( $mapped['release_type'] ?? '' ) );
		if ( '' === $format && '' !== $default_release_type ) {
			$format = sanitize_text_field( $default_release_type );
		}
		$label        = sanitize_text_field( (string) ( $mapped['record_label'] ?? '' ) );
		$distributor  = sanitize_text_field( (string) ( $mapped['distributor'] ?? '' ) );
		$buy_link     = esc_url_raw( (string) ( $mapped['buy_now_link'] ?? '' ) );
		$tags_raw     = sanitize_text_field( (string) ( $mapped['tags'] ?? '' ) );
		$featured_img = array_key_exists( 'featured_image_url', $mapped ) ? esc_url_raw( (string) $mapped['featured_image_url'] ) : null;
		$audio_url    = array_key_exists( 'audio_preview_url', $mapped ) ? esc_url_raw( (string) $mapped['audio_preview_url'] ) : null;
		$about_raw    = array_key_exists( 'about', $mapped ) ? wp_kses_post( (string) $mapped['about'] ) : null;

		// Skip completely empty rows.
		if ( '' === $title && '' === $upc ) {
			return array(
				'status' => 'skipped',
				'error'  => '',
			);
		}

		if ( '' === $title ) {
			$title = 'Untitled';
		}

		$format_id       = \MDS\ReleaseManager\Core\ReleaseModel::format_id( $format );
		$release_found   = '' === $upc ? array() : get_posts(
			array(
				'post_type'              => 'release',
				'meta_key'               => 'upc_ean', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Match the stored UPC to update a release or reject ambiguous matches.
				'meta_value'             => $upc, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'post_status'            => array_keys( get_post_stati() ),
				'posts_per_page'         => 2,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		$release_post_id = 1 === count( $release_found ) ? (int) $release_found[0] : 0;
		$validation      = \MDS\ReleaseManager\Core\ReleaseModel::validate( $release_post_id, $upc, $format_id ? array( $format_id ) : array() );
		if ( count( $release_found ) > 1 || is_wp_error( $validation ) ) {
			$message = count( $release_found ) > 1
				? __( 'More than one release has this UPC/EAN.', 'mds-release-manager' )
				: $validation->get_error_message();
			return array(
				'status' => 'error',
				/* translators: %1$d: CSV row number. %2$s: Error message. */
				'error'  => sprintf( __( 'Row %1$d: %2$s', 'mds-release-manager' ), $row_number, $message ),
			);
		}

		// Collect Streaming Provider Links early so identical-record checks run before any DB writes.
		$streaming_links = array();
		$raw_json_links  = $mapped['streaming_links_json'] ?? ( $mapped['streaming_links'] ?? '' );
		if ( is_string( $raw_json_links ) && '' !== trim( $raw_json_links ) ) {
			$decoded_links = json_decode( $raw_json_links, true );
			if ( is_array( $decoded_links ) ) {
				foreach ( $decoded_links as $provider_name => $provider_url ) {
					$clean_provider = sanitize_text_field( (string) $provider_name );
					$clean_url      = esc_url_raw( trim( (string) $provider_url ) );
					if ( '' !== $clean_provider && '' !== $clean_url ) {
						$streaming_links[ $clean_provider ] = $clean_url;
					}
				}
			}
		}

		foreach ( $mapped as $field_key => $field_val ) {
			if ( ! is_string( $field_key ) || ! str_starts_with( $field_key, 'provider:' ) ) {
				continue;
			}
			$provider_name = sanitize_text_field( substr( $field_key, 9 ) );
			$clean_url     = esc_url_raw( trim( (string) $field_val ) );
			if ( '' !== $provider_name && '' !== $clean_url ) {
				$streaming_links[ $provider_name ] = $clean_url;
			}
		}
		$streaming_json = (string) wp_json_encode( $streaming_links );

		$parsed_date = '';
		if ( '' !== $release_date ) {
			$timestamp = strtotime( $release_date );
			if ( false !== $timestamp ) {
				$parsed_date = gmdate( 'Y-m-d H:i:s', $timestamp );
			}
		}

		$is_update = 0 !== $release_post_id;
		if ( $is_update ) {
			if ( 'skip_existing' === $duplicate_mode ) {
				\MDS\ReleaseManager\Core\ReleaseModel::release_upc_lock( $upc );
				return array(
					'status' => 'skipped_existing',
					'error'  => '',
				);
			}

			if ( 'skip_identical' === $duplicate_mode ) {
				$existing_snapshot = self::snapshot_existing_release( $release_post_id );
				$incoming_snapshot = array(
					'title'              => $title,
					'artist_name'        => $artist_name,
					'release_date'       => $parsed_date,
					'release_type_id'    => (int) $format_id,
					'record_label'       => $label,
					'distributor'        => $distributor,
					'buy_now_link'       => $buy_link,
					'tags'               => $tags_raw,
					'featured_image_url' => $featured_img,
					'audio_preview_url'  => $audio_url,
					'about'              => $about_raw,
					'streaming_links'    => $streaming_links,
				);
				if ( self::is_release_identical( $existing_snapshot, $incoming_snapshot ) ) {
					\MDS\ReleaseManager\Core\ReleaseModel::release_upc_lock( $upc );
					return array(
						'status' => 'unchanged',
						'error'  => '',
					);
				}
			}
		}

		// 1. Resolve Artist CPT natively (prefer canonical artist_uuid when restoring from JSON/XML backups).
		$incoming_artist_uuid = sanitize_text_field( (string) ( $mapped['artist_uuid'] ?? '' ) );
		$artist_found         = array();
		if ( '' !== $incoming_artist_uuid ) {
			$artist_found = get_posts(
				array(
					'post_type'      => 'artist',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'no_found_rows'  => true,
					'meta_key'       => '_mds_artist_uuid', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Resolves Artist by canonical UUID during JSON/XML restore.
					'meta_value'     => $incoming_artist_uuid, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
		}
		if ( empty( $artist_found ) ) {
			$artist_found = get_posts(
				array(
					'post_type'   => 'artist',
					'title'       => $artist_name,
					'post_status' => 'any',
					'numberposts' => 1,
				)
			);
		}
		if ( ! empty( $artist_found ) ) {
			$artist_post_id = (int) $artist_found[0]->ID;
			$artist_uuid    = (string) get_post_meta( $artist_post_id, '_mds_artist_uuid', true );
			if ( '' === $artist_uuid ) {
				$artist_uuid = '' !== $incoming_artist_uuid ? $incoming_artist_uuid : wp_generate_uuid4();
				update_post_meta( $artist_post_id, '_mds_artist_uuid', $artist_uuid );
			}
		} else {
			$artist_post_id = (int) wp_insert_post(
				array(
					'post_title'  => $artist_name,
					'post_name'   => sanitize_title( $artist_name ),
					'post_type'   => 'artist',
					'post_status' => 'publish',
				)
			);
			$artist_uuid    = '' !== $incoming_artist_uuid ? $incoming_artist_uuid : wp_generate_uuid4();
			update_post_meta( $artist_post_id, '_mds_artist_uuid', $artist_uuid );
		}

		if ( $artist_post_id > 0 && ! isset( $broadcasted_artists[ $artist_post_id ] ) ) {
			$pending_sync_items[ 'artist:' . $artist_post_id ] = array(
				'id'        => $artist_post_id,
				'post_type' => 'artist',
			);
			$broadcasted_artists[ $artist_post_id ]            = true;
		}

		// 2. Upsert Release CPT natively by UPC.
		$post_args = array(
			'post_title'   => $title,
			'post_content' => $about_raw ?? '',
			'post_type'    => 'release',
			'post_status'  => 'publish',
		);
		if ( '' !== $parsed_date ) {
			$post_args['post_date']     = $parsed_date;
			$post_args['post_date_gmt'] = get_gmt_from_date( $parsed_date );
		}

		if ( $is_update ) {
			$post_args['ID'] = $release_post_id;
			$release_post_id = wp_update_post( $post_args, true );
		} else {
			$release_post_id = wp_insert_post( $post_args, true );
		}

		if ( is_wp_error( $release_post_id ) || ! $release_post_id ) {
			\MDS\ReleaseManager\Core\ReleaseModel::release_upc_lock( $upc );
			return array(
				'status' => 'error',
				/* translators: %d: CSV row number. */
				'error'  => sprintf( __( 'Row %d: could not save the release.', 'mds-release-manager' ), $row_number ),
			);
		}

		$release_post_id = (int) $release_post_id;

		if ( null !== $featured_img ) {
			update_post_meta( $release_post_id, 'featured_image_url', $featured_img );
		}
		if ( null !== $audio_url ) {
			update_post_meta( $release_post_id, 'audio_preview_url', $audio_url );
		}

		// 4. Bind Meta & Taxonomies.
		update_post_meta( $release_post_id, 'upc_ean', $upc );
		update_post_meta( $release_post_id, 'artist_id', $artist_uuid );
		update_post_meta( $release_post_id, 'record_label', $label );
		update_post_meta( $release_post_id, 'distributor', $distributor );
		update_post_meta( $release_post_id, 'buy_now_link', $buy_link );
		if ( ! empty( $mapped['buy_now_links'] ) && is_array( $mapped['buy_now_links'] ) ) {
			$clean_buy_now_links = array();
			foreach ( $mapped['buy_now_links'] as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$entry_url = esc_url_raw( trim( (string) ( $entry['url'] ?? '' ) ) );
				if ( '' === $entry_url ) {
					continue;
				}
				$clean_buy_now_links[] = array(
					'format' => sanitize_text_field( (string) ( $entry['format'] ?? '' ) ),
					'label'  => sanitize_text_field( (string) ( $entry['label'] ?? '' ) ),
					'url'    => $entry_url,
				);
			}
			if ( ! empty( $clean_buy_now_links ) ) {
				update_post_meta( $release_post_id, 'buy_now_links', $clean_buy_now_links );
			}
		}
		update_post_meta( $release_post_id, 'streaming_links', wp_slash( $streaming_json ) );

		$result = wp_set_post_terms( $release_post_id, array( $format_id ), 'release_type', false );
		if ( is_wp_error( $result ) ) {
			\MDS\ReleaseManager\Core\ReleaseModel::release_upc_lock( $upc );
			return array(
				'status' => 'error',
				/* translators: %1$d: CSV row number. %2$s: Error message. */
				'error'  => sprintf( __( 'Row %1$d: %2$s', 'mds-release-manager' ), $row_number, $result->get_error_message() ),
			);
		}
		delete_post_meta( $release_post_id, 'release_format' );

		if ( '' !== $tags_raw ) {
			$tag_array = array_filter( array_map( 'trim', explode( ',', $tags_raw ) ) );
			wp_set_post_terms( $release_post_id, $tag_array, 'release_tag', false );
			update_post_meta( $release_post_id, 'release_tags', implode( ', ', $tag_array ) );
		} else {
			wp_set_post_terms( $release_post_id, array(), 'release_tag', false );
		}

		\MDS\ReleaseManager\Core\ReleaseModel::release_upc_lock( $upc );
		$pending_sync_items[ 'release:' . $release_post_id ] = array(
			'id'        => $release_post_id,
			'post_type' => 'release',
		);

		return array(
			'status' => $is_update ? 'updated' : 'created',
			'error'  => '',
		);
	}

	/**
	 * Adds the CSV import and export submenu page.
	 *
	 * @since 0.9.0
	 */
	public static function register_csv_page(): void {
		add_submenu_page(
			Dashboard::MENU_SLUG,
			__( 'Import/Export', 'mds-release-manager' ),
			__( 'Import/Export', 'mds-release-manager' ),
			'manage_options',
			'mds-release-csv',
			array( self::class, 'csv_page' )
		);
	}

	/**
	 * Verifies the user's permission and CSV request nonce.
	 *
	 * @since 0.9.0
	 */
	public static function verify_csv_request(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to manage CSV data.', 'mds-release-manager' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( 'mds_csv_action', 'mds_csv_nonce' );
	}

	/**
	 * Streams catalog data as a CSV, JSON, or XML download (or a blank CSV template).
	 *
	 * @since 0.9.0
	 */
	/**
	 * REST API endpoint: builds and returns CSV/JSON/XML export payload for zero-postback AJAX downloads.
	 *
	 * @since 2.2.1
	 *
	 * @param \WP_REST_Request $request REST request containing export options.
	 * @return \WP_REST_Response
	 */
	public static function rest_export_catalog( \WP_REST_Request $request ): \WP_REST_Response {
		$params           = $request->get_json_params();
		$params           = is_array( $params ) ? $params : $request->get_params();
		$export_action    = sanitize_key( (string) ( $params['export_action'] ?? 'export' ) );
		$export_format    = sanitize_key( (string) ( $params['export_format'] ?? 'csv' ) );
		$streaming_format = sanitize_key( (string) ( $params['streaming_format'] ?? 'columns' ) );
		$filter_artist    = sanitize_text_field( (string) ( $params['filter_artist'] ?? '' ) );
		$filter_type      = absint( $params['filter_type'] ?? 0 );
		$filter_status    = sanitize_key( (string) ( $params['filter_status'] ?? 'any' ) );
		$include_bom      = isset( $params['include_bom'] ) ? (bool) $params['include_bom'] : true;

		if ( ! in_array( $export_format, array( 'csv', 'json', 'xml' ), true ) ) {
			$export_format = 'csv';
		}
		if ( ! in_array( $streaming_format, array( 'columns', 'json', 'both' ), true ) ) {
			$streaming_format = 'columns';
		}
		if ( ! in_array( $filter_status, array( 'any', 'publish' ), true ) ) {
			$filter_status = 'any';
		}

		$provider_names = ImportFieldMapper::provider_names();

		if ( 'template' === $export_action ) {
			return new \WP_REST_Response(
				array(
					'ok'       => true,
					'filename' => 'mds-releases-template.csv',
					'mime'     => 'text/csv;charset=utf-8',
					'content'  => self::build_csv_template( $provider_names, $include_bom ),
				),
				200
			);
		}

		$catalog = self::collect_export_catalog(
			array(
				'artist_uuid' => $filter_artist,
				'type_id'     => $filter_type,
				'status'      => $filter_status,
			)
		);

		$date_stamp    = gmdate( 'Y-m-d' );
		$filename_base = 'mds-releases';
		if ( '' !== $filter_artist && ! empty( $catalog['artists'] ) ) {
			$artist_slug = sanitize_title( (string) ( $catalog['artists'][0]['name'] ?? '' ) );
			if ( '' !== $artist_slug ) {
				$filename_base .= '-' . $artist_slug;
			}
		}
		$filename_base .= '-' . $date_stamp;

		if ( 'json' === $export_format ) {
			return new \WP_REST_Response(
				array(
					'ok'       => true,
					'filename' => $filename_base . '.json',
					'mime'     => 'application/json;charset=utf-8',
					'content'  => self::build_json_export( $catalog ),
				),
				200
			);
		}

		if ( 'xml' === $export_format ) {
			return new \WP_REST_Response(
				array(
					'ok'       => true,
					'filename' => $filename_base . '.xml',
					'mime'     => 'application/xml;charset=utf-8',
					'content'  => self::build_xml_export( $catalog ),
				),
				200
			);
		}

		// Build CSV string in memory using existing helpers.
		$headers = array( 'UPC/EAN', 'Release Title', 'Artist', 'Release Date', 'Release Type', 'Record Label', 'Distributor', 'Buy Now URL', 'Audio Preview URL', 'Cover Art URL', 'Description', 'Excerpt', 'Media Formats', 'Release Tags' );
		if ( in_array( $streaming_format, array( 'columns', 'both' ), true ) ) {
			foreach ( $provider_names as $provider_name ) {
				$headers[] = 'Streaming: ' . $provider_name;
			}
		}
		if ( in_array( $streaming_format, array( 'json', 'both' ), true ) ) {
			$headers[] = 'Streaming Links (JSON)';
		}

		$fh = fopen( 'php://temp', 'r+' );
		if ( false === $fh ) {
			return new \WP_REST_Response( array( 'ok' => false, 'error' => __( 'Failed to initialize export stream.', 'mds-release-manager' ) ), 500 );
		}
		if ( $include_bom ) {
			fwrite( $fh, "\xEF\xBB\xBF" );
		}
		fputcsv( $fh, $headers );

		foreach ( (array) ( $catalog['releases'] ?? array() ) as $rel ) {
			$links = isset( $rel['streaming_links'] ) && is_array( $rel['streaming_links'] ) ? $rel['streaming_links'] : array();
			$row   = array(
				(string) ( $rel['upc_ean'] ?? '' ),
				(string) ( $rel['title'] ?? '' ),
				(string) ( $rel['artist_name'] ?? '' ),
				(string) ( $rel['release_date'] ?? '' ),
				(string) ( $rel['release_type'] ?? '' ),
				(string) ( $rel['record_label'] ?? '' ),
				(string) ( $rel['distributor'] ?? '' ),
				(string) ( $rel['buy_now_link'] ?? '' ),
				(string) ( $rel['audio_preview_url'] ?? '' ),
				(string) ( $rel['cover_art_url'] ?? '' ),
				(string) ( $rel['description'] ?? '' ),
				(string) ( $rel['excerpt'] ?? '' ),
				implode( ', ', (array) ( $rel['media_formats'] ?? array() ) ),
				implode( ', ', (array) ( $rel['release_tags'] ?? array() ) ),
			);
			if ( in_array( $streaming_format, array( 'columns', 'both' ), true ) ) {
				foreach ( $provider_names as $provider_name ) {
					$row[] = (string) ( $links[ $provider_name ] ?? '' );
				}
			}
			if ( in_array( $streaming_format, array( 'json', 'both' ), true ) ) {
				$row[] = ! empty( $links ) ? (string) wp_json_encode( $links ) : '';
			}
			fputcsv( $fh, $row );
		}

		rewind( $fh );
		$csv_content = (string) stream_get_contents( $fh );
		fclose( $fh );

		return new \WP_REST_Response(
			array(
				'ok'       => true,
				'filename' => $filename_base . '.csv',
				'mime'     => 'text/csv;charset=utf-8',
				'content'  => $csv_content,
			),
			200
		);
	}

	/**
	 * Exports releases to CSV, JSON, or XML.
	 *
	 * @since 0.9.0
	 */
	public static function export_csv(): void {
		self::verify_csv_request();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce is verified by self::verify_csv_request() above.
		$export_action    = sanitize_key( (string) wp_unslash( $_POST['export_action'] ?? 'export' ) );
		$export_format    = sanitize_key( (string) wp_unslash( $_POST['export_format'] ?? 'csv' ) );
		$streaming_format = sanitize_key( (string) wp_unslash( $_POST['streaming_format'] ?? 'columns' ) );
		$filter_artist    = sanitize_text_field( (string) wp_unslash( $_POST['filter_artist'] ?? '' ) );
		$filter_type      = absint( wp_unslash( $_POST['filter_type'] ?? 0 ) );
		$filter_status    = sanitize_key( (string) wp_unslash( $_POST['filter_status'] ?? 'any' ) );
		$include_bom      = isset( $_POST['include_bom'] ) ? '1' === sanitize_key( (string) wp_unslash( $_POST['include_bom'] ) ) : true;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! in_array( $export_format, array( 'csv', 'json', 'xml' ), true ) ) {
			$export_format = 'csv';
		}
		if ( ! in_array( $streaming_format, array( 'columns', 'json', 'both' ), true ) ) {
			$streaming_format = 'columns';
		}
		if ( ! in_array( $filter_status, array( 'any', 'publish' ), true ) ) {
			$filter_status = 'any';
		}

		$provider_names = ImportFieldMapper::provider_names();

		if ( 'template' === $export_action ) {
			$template_csv = self::build_csv_template( $provider_names, $include_bom );
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="mds-releases-template.csv"' );
			echo $template_csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download stream.
			exit;
		}

		$catalog = self::collect_export_catalog(
			array(
				'artist_uuid' => $filter_artist,
				'type_id'     => $filter_type,
				'status'      => $filter_status,
			)
		);

		$date_stamp    = gmdate( 'Y-m-d' );
		$filename_base = 'mds-releases';
		if ( '' !== $filter_artist && ! empty( $catalog['artists'] ) ) {
			$artist_slug = sanitize_title( (string) ( $catalog['artists'][0]['name'] ?? '' ) );
			if ( '' !== $artist_slug ) {
				$filename_base .= '-' . $artist_slug;
			}
		}
		$filename_base .= '-' . $date_stamp;

		if ( 'json' === $export_format ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $filename_base . '.json"' );
			echo self::build_json_export( $catalog ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download stream.
			exit;
		}

		if ( 'xml' === $export_format ) {
			header( 'Content-Type: application/xml; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $filename_base . '.xml"' );
			echo self::build_xml_export( $catalog ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML download stream.
			exit;
		}

		// CSV export.
		$headers = array(
			'UPC/EAN',
			'Release Title',
			'Artist',
			'Release Date',
			'Release Type',
			'Record Label',
			'Distributor',
			'Buy Now Link',
			'Tags',
			'Featured Image URL',
			'Audio Preview URL',
			'About',
		);
		if ( 'columns' === $streaming_format || 'both' === $streaming_format ) {
			foreach ( $provider_names as $provider_name ) {
				$headers[] = $provider_name . ' Link';
			}
		}
		if ( 'json' === $streaming_format || 'both' === $streaming_format ) {
			$headers[] = 'Streaming Links';
		}

		$stream = new \SplTempFileObject();
		if ( empty( $catalog['releases'] ) ) {
			if ( false === $stream->fputcsv( array( 'No data found in WordPress posts.' ) ) ) {
				wp_die( esc_html__( 'Could not create the CSV export.', 'mds-release-manager' ) );
			}
		} else {
			if ( false === $stream->fputcsv( $headers ) ) {
				wp_die( esc_html__( 'Could not create the CSV export.', 'mds-release-manager' ) );
			}
			foreach ( $catalog['releases'] as $rel ) {
				$row = array(
					$rel['upc_ean'],
					$rel['title'],
					$rel['artist'],
					$rel['release_date'],
					$rel['release_type'],
					$rel['record_label'],
					$rel['distributor'],
					$rel['buy_now_link'],
					$rel['tags'],
					$rel['featured_image_url'],
					$rel['audio_preview_url'],
					$rel['about'],
				);
				if ( 'columns' === $streaming_format || 'both' === $streaming_format ) {
					$links = is_array( $rel['streaming_links'] ) ? $rel['streaming_links'] : array();
					foreach ( $provider_names as $provider_name ) {
						$row[] = (string) ( $links[ $provider_name ] ?? '' );
					}
				}
				if ( 'json' === $streaming_format || 'both' === $streaming_format ) {
					$row[] = (string) wp_json_encode( $rel['streaming_links'] );
				}
				if ( false === $stream->fputcsv( $row ) ) {
					wp_die( esc_html__( 'Could not create the CSV export.', 'mds-release-manager' ) );
				}
			}
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename_base . '.csv"' );
		if ( $include_bom ) {
			echo "\xEF\xBB\xBF"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- UTF-8 BOM for Excel compatibility.
		}
		$stream->rewind();
		while ( ! $stream->eof() ) {
			$line = $stream->fgets();
			if ( false === $line ) {
				break;
			}
			echo $line; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV data is emitted as a download.
		}

		exit;
	}

	/**
	 * Collects filtered artists and releases into a structured catalog array for export.
	 *
	 * @since 2.0.2
	 * @param array{artist_uuid?:string,type_id?:int,status?:string} $filters Optional export filters.
	 * @return array{schema_version:string,exported_at:string,site_url:string,artists:array<int,array<string,mixed>>,releases:array<int,array<string,mixed>>}
	 */
	public static function collect_export_catalog( array $filters = array() ): array {
		$artist_uuid_filter = trim( (string) ( $filters['artist_uuid'] ?? '' ) );
		$type_id_filter     = (int) ( $filters['type_id'] ?? 0 );
		$status_filter      = (string) ( $filters['status'] ?? 'any' );

		$batch_size           = 100;
		$page                 = 1;
		$releases_out         = array();
		$artists_by_uuid      = array();
		$artist_names_by_uuid = array();

		do {
			$query_args = array(
				'post_type'      => 'release',
				'post_status'    => $status_filter,
				'posts_per_page' => $batch_size,
				'paged'          => $page,
				'orderby'        => array(
					'date' => 'DESC',
					'ID'   => 'DESC',
				),
				'no_found_rows'  => true,
			);

			if ( '' !== $artist_uuid_filter ) {
				$query_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Filters exported releases by selected Artist UUID.
					array(
						'key'   => 'artist_id',
						'value' => $artist_uuid_filter,
					),
				);
			}
			if ( $type_id_filter > 0 ) {
				$query_args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Filters exported releases by selected Release Type term.
					array(
						'taxonomy' => 'release_type',
						'field'    => 'term_id',
						'terms'    => array( $type_id_filter ),
					),
				);
			}

			$releases = get_posts( $query_args );
			if ( ! $releases ) {
				break;
			}

			$needed_uuids = array();
			foreach ( $releases as $release ) {
				$a_uuid = (string) get_post_meta( $release->ID, 'artist_id', true );
				if ( '' !== $a_uuid && ! isset( $artists_by_uuid[ $a_uuid ] ) ) {
					$needed_uuids[] = $a_uuid;
				}
			}
			$needed_uuids = array_values( array_unique( $needed_uuids ) );

			if ( ! empty( $needed_uuids ) ) {
				$artist_posts = get_posts(
					array(
						'post_type'      => 'artist',
						'post_status'    => 'any',
						'posts_per_page' => count( $needed_uuids ),
						'no_found_rows'  => true,
						'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Resolves artist records by UUID batch during export.
							array(
								'key'     => '_mds_artist_uuid',
								'value'   => $needed_uuids,
								'compare' => 'IN',
							),
						),
					)
				);
				foreach ( $artist_posts as $artist ) {
					$a_uuid = (string) get_post_meta( $artist->ID, '_mds_artist_uuid', true );
					if ( '' === $a_uuid ) {
						continue;
					}
					$artist_names_by_uuid[ $a_uuid ] = $artist->post_title;
					$artists_by_uuid[ $a_uuid ]      = array(
						'uuid'           => $a_uuid,
						'name'           => $artist->post_title,
						'slug'           => $artist->post_name,
						'status'         => $artist->post_status,
						'website'        => (string) get_post_meta( $artist->ID, 'artist_website', true ),
						'portrait_url'   => (string) get_post_meta( $artist->ID, 'artist_portrait_url', true ),
						'bio'            => $artist->post_content,
						'routing_policy' => (string) get_post_meta( $artist->ID, '_mds_artist_release_routing', true ),
					);
				}
			}

			foreach ( $releases as $release ) {
				$format = \MDS\ReleaseManager\Core\ReleaseModel::format_name( $release->ID );
				if ( \MDS\ReleaseManager\Core\ReleaseModel::has_legacy_format_conflict( $release->ID ) ) {
					/* translators: %d: Release post ID. */
					wp_die( esc_html( sprintf( __( 'Cannot export release %d: its format conflicts with legacy data. Resolve the conflict first.', 'mds-release-manager' ), $release->ID ) ) );
				}
				if ( is_wp_error( $format ) ) {
					/* translators: %1$d: Release post ID. %2$s: Error message. */
					wp_die( esc_html( sprintf( __( 'Cannot export release %1$d: %2$s', 'mds-release-manager' ), $release->ID, $format->get_error_message() ) ) );
				}

				$a_uuid          = (string) get_post_meta( $release->ID, 'artist_id', true );
				$raw_links       = (string) get_post_meta( $release->ID, 'streaming_links', true );
				$decoded_links   = json_decode( $raw_links, true );
				$streaming_links = is_array( $decoded_links ) ? $decoded_links : array();
				$buy_now_links   = get_post_meta( $release->ID, 'buy_now_links', true );

				$releases_out[] = array(
					'upc_ean'            => (string) get_post_meta( $release->ID, 'upc_ean', true ),
					'title'              => $release->post_title,
					'artist_uuid'        => $a_uuid,
					'artist'             => $artist_names_by_uuid[ $a_uuid ] ?? 'Unknown Artist',
					'release_date'       => $release->post_date,
					'release_type'       => (string) $format,
					'status'             => $release->post_status,
					'record_label'       => (string) get_post_meta( $release->ID, 'record_label', true ),
					'distributor'        => (string) get_post_meta( $release->ID, 'distributor', true ),
					'buy_now_link'       => (string) get_post_meta( $release->ID, 'buy_now_link', true ),
					'buy_now_links'      => is_array( $buy_now_links ) ? $buy_now_links : array(),
					'tags'               => (string) get_post_meta( $release->ID, 'release_tags', true ),
					'featured_image_url' => (string) get_post_meta( $release->ID, 'featured_image_url', true ),
					'audio_preview_url'  => (string) get_post_meta( $release->ID, 'audio_preview_url', true ),
					'about'              => $release->post_content,
					'streaming_links'    => $streaming_links,
				);
			}

			++$page;
			$releases_count = count( $releases );
		} while ( $releases_count === $batch_size );

		return array(
			'schema_version' => '2.1',
			'exported_at'    => gmdate( 'c' ),
			'site_url'       => function_exists( 'home_url' ) ? home_url( '/' ) : '',
			'artists'        => array_values( $artists_by_uuid ),
			'releases'       => $releases_out,
		);
	}

	/**
	 * Serializes a collected catalog array into pretty-printed JSON.
	 *
	 * @since 2.0.2
	 * @param array<string, mixed> $catalog Collected catalog data.
	 * @return string JSON string.
	 */
	public static function build_json_export( array $catalog ): string {
		$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		if ( function_exists( 'wp_json_encode' ) ) {
			$json = wp_json_encode( $catalog, $flags );
		} else {
			$json = json_encode( $catalog, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Fallback for standalone unit tests outside WordPress.
		}
		return is_string( $json ) ? $json . "\n" : "{}\n";
	}

	/**
	 * Serializes a collected catalog array into structured XML (`<mds_catalog>`).
	 *
	 * @since 2.0.2
	 * @param array<string, mixed> $catalog Collected catalog data.
	 * @return string XML document string.
	 */
	public static function build_xml_export( array $catalog ): string {
		$writer = new \XMLWriter();
		$writer->openMemory();
		$writer->setIndent( true );
		$writer->setIndentString( '  ' );
		$writer->startDocument( '1.0', 'UTF-8' );
		$writer->startElement( 'mds_catalog' );
		$writer->writeAttribute( 'version', (string) ( $catalog['schema_version'] ?? '2.1' ) );
		$writer->writeAttribute( 'exported_at', (string) ( $catalog['exported_at'] ?? '' ) );
		if ( ! empty( $catalog['site_url'] ) ) {
			$writer->writeAttribute( 'site_url', (string) $catalog['site_url'] );
		}

		$writer->startElement( 'artists' );
		foreach ( (array) ( $catalog['artists'] ?? array() ) as $artist ) {
			if ( ! is_array( $artist ) ) {
				continue;
			}
			$writer->startElement( 'artist' );
			foreach ( array( 'uuid', 'name', 'slug', 'status', 'website', 'portrait_url', 'routing_policy', 'bio' ) as $field ) {
				$writer->writeElement( $field, (string) ( $artist[ $field ] ?? '' ) );
			}
			$writer->endElement();
		}
		$writer->endElement();

		$writer->startElement( 'releases' );
		foreach ( (array) ( $catalog['releases'] ?? array() ) as $rel ) {
			if ( ! is_array( $rel ) ) {
				continue;
			}
			$writer->startElement( 'release' );
			foreach (
				array(
					'upc_ean',
					'title',
					'artist_uuid',
					'artist',
					'release_date',
					'release_type',
					'status',
					'record_label',
					'distributor',
					'buy_now_link',
					'tags',
					'featured_image_url',
					'audio_preview_url',
					'about',
				) as $field
			) {
				$writer->writeElement( $field, (string) ( $rel[ $field ] ?? '' ) );
			}

			$writer->startElement( 'buy_now_links' );
			foreach ( (array) ( $rel['buy_now_links'] ?? array() ) as $b_link ) {
				if ( ! is_array( $b_link ) ) {
					continue;
				}
				$writer->startElement( 'link' );
				$writer->writeAttribute( 'format', (string) ( $b_link['format'] ?? '' ) );
				$writer->writeAttribute( 'label', (string) ( $b_link['label'] ?? '' ) );
				$writer->text( (string) ( $b_link['url'] ?? '' ) );
				$writer->endElement();
			}
			$writer->endElement();

			$writer->startElement( 'streaming_links' );
			foreach ( (array) ( $rel['streaming_links'] ?? array() ) as $provider => $url ) {
				$writer->startElement( 'link' );
				$writer->writeAttribute( 'provider', (string) $provider );
				$writer->text( (string) $url );
				$writer->endElement();
			}
			$writer->endElement();

			$writer->endElement();
		}
		$writer->endElement();

		$writer->endElement();
		$writer->endDocument();

		return $writer->outputMemory();
	}

	/**
	 * Builds a blank CSV import template with configured streaming provider columns and 1 example row.
	 *
	 * @since 2.0.2
	 * @param string[] $provider_names Configured streaming provider names.
	 * @param bool     $include_bom    Whether to prepend the UTF-8 BOM for Excel.
	 * @return string CSV content.
	 */
	public static function build_csv_template( array $provider_names, bool $include_bom = true ): string {
		$headers = array(
			'UPC/EAN',
			'Release Title',
			'Artist',
			'Release Date',
			'Release Type',
			'Record Label',
			'Distributor',
			'Buy Now Link',
			'Tags',
			'Featured Image URL',
			'Audio Preview URL',
			'About',
		);
		$example = array(
			'0088123456789',
			'Example Single',
			'Example Artist',
			gmdate( 'Y-m-d' ),
			'Single',
			'Example Records',
			'DistroKid',
			'https://shop.example.com/example-single',
			'Indie, Electronic',
			'https://cdn.example.com/cover.jpg',
			'https://cdn.example.com/preview.mp3',
			'Short release description.',
		);

		foreach ( $provider_names as $provider_name ) {
			$headers[] = $provider_name . ' Link';
			$example[] = '';
		}

		$stream = new \SplTempFileObject();
		$stream->fputcsv( $headers );
		$stream->fputcsv( $example );
		$stream->rewind();

		$out = $include_bom ? "\xEF\xBB\xBF" : '';
		while ( ! $stream->eof() ) {
			$line = $stream->fgets();
			if ( false === $line ) {
				break;
			}
			$out .= $line;
		}
		return $out;
	}

	/**
	 * Imports release data from the uploaded CSV file.
	 *
	 * @since 0.9.0
	 */
	public static function import_csv(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to manage CSV data.', 'mds-release-manager' ),
				'',
				array( 'response' => 403 )
			);
		}

		if ( ! \MDS\ReleaseManager\Core\Sync::is_hub() ) {
			wp_die(
				esc_html__( 'CSV import is only available on the Hub site.', 'mds-release-manager' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( 'mds_csv_action', 'mds_csv_nonce' );

		// PHP supplies this path; preserve it verbatim and verify its upload provenance below.
		$tmp_name = $_FILES['mds_csv_file']['tmp_name'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( empty( $tmp_name ) ) {
			self::csv_redirect_with_message(
				'mds_csv_missing_file',
				__( 'Please select a CSV file to import.', 'mds-release-manager' ),
				'error'
			);
		}

		if ( ! is_string( $tmp_name ) || ! is_uploaded_file( $tmp_name ) ) {
			self::csv_redirect_with_message(
				'mds_csv_file_error',
				__( 'The uploaded CSV file could not be opened.', 'mds-release-manager' ),
				'error'
			);
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! WP_Filesystem() ) {
			self::csv_redirect_with_message(
				'mds_csv_file_error',
				__( 'The uploaded CSV file could not be opened.', 'mds-release-manager' ),
				'error'
			);
		}

		global $wp_filesystem;
		$csv_content = $wp_filesystem->get_contents( $tmp_name );
		if ( false === $csv_content ) {
			self::csv_redirect_with_message(
				'mds_csv_file_error',
				__( 'The uploaded CSV file could not be opened.', 'mds-release-manager' ),
				'error'
			);
		}

		// Check for UTF-8 BOM and strip it to prevent header matching errors.
		if ( 0 === strpos( $csv_content, "\xEF\xBB\xBF" ) ) {
			$csv_content = substr( $csv_content, 3 );
		}

		$csv_stream = new \SplTempFileObject();
		$csv_stream->fwrite( $csv_content );
		$csv_stream->rewind();

		$headers             = null;
		$imported            = 0;
		$updated             = 0;
		$errors              = array();
		$row_number          = 0;
		$pending_sync_items  = array();
		$broadcasted_artists = array();

		// Build dynamic list of streaming provider names (configured + legacy defaults).
		$provider_names       = array( 'Spotify', 'Apple Music', 'Amazon Music', 'YouTube Music', 'Soundcloud' );
		$configured_providers = get_option( 'mds_rm_providers', array() );
		if ( is_array( $configured_providers ) ) {
			foreach ( $configured_providers as $configured_provider ) {
				if (
					! empty( $configured_provider['name'] )
					&& ! in_array( $configured_provider['name'], $provider_names, true )
				) {
					$provider_names[] = $configured_provider['name'];
				}
			}
		}

		while ( true ) {
			$row = $csv_stream->fgetcsv();
			if ( false === $row ) {
				break;
			}
			++$row_number;
			$nonempty = array_filter(
				$row,
				static function ( $value ) {
					return null !== $value && '' !== trim( (string) $value );
				}
			);
			if ( ! $nonempty ) {
				continue;
			}
			// Locate header row containing 'UPC/EAN'.
			if ( ! $headers ) {
				$trimmed_row = array_map( 'trim', $row );
				if ( in_array( 'UPC/EAN', $trimmed_row, true ) ) {
					$headers = $trimmed_row;
					if ( ! in_array( 'Release Type', $headers, true ) ) {
						self::csv_redirect_with_message( 'mds_csv_missing_format', __( 'The CSV must include a Release Type column.', 'mds-release-manager' ), 'error' );
					}
				}
				continue;
			}

			if ( count( $headers ) !== count( $row ) ) {
				/* translators: %d: CSV row number. */
				$errors[] = sprintf( __( 'Row %d: incorrect number of columns.', 'mds-release-manager' ), $row_number );
				continue;
			}
			$data   = array_combine( $headers, $row );
			$mapped = array(
				'artist'        => $data['Artist'] ?? 'Unknown Artist',
				'upc_ean'       => $data['UPC/EAN'] ?? '',
				'release_title' => $data['Release Title'] ?? '',
				'release_date'  => $data['Release Date'] ?? '',
				'release_type'  => $data['Release Type'] ?? '',
				'record_label'  => $data['Record Label'] ?? '',
				'distributor'   => $data['Distributor'] ?? '',
				'buy_now_link'  => $data['Buy Now Link'] ?? '',
				'tags'          => $data['Tags'] ?? '',
			);
			if ( isset( $data['Featured Image URL'] ) ) {
				$mapped['featured_image_url'] = $data['Featured Image URL'];
			}
			if ( isset( $data['Audio Preview URL'] ) ) {
				$mapped['audio_preview_url'] = $data['Audio Preview URL'];
			}
			if ( isset( $data['About'] ) ) {
				$mapped['about'] = $data['About'];
			}
			if ( isset( $data['Streaming Links'] ) ) {
				$mapped['streaming_links'] = $data['Streaming Links'];
			}
			foreach ( $provider_names as $provider_name ) {
				$column_key = $provider_name . ' Link';
				if ( ! empty( $data[ $column_key ] ) ) {
					$mapped[ 'provider:' . $provider_name ] = $data[ $column_key ];
				}
			}

			$outcome = self::upsert_mapped_row( $mapped, $row_number, $pending_sync_items, $broadcasted_artists );
			if ( 'created' === $outcome['status'] ) {
				++$imported;
			} elseif ( 'updated' === $outcome['status'] ) {
				++$updated;
			} elseif ( '' !== $outcome['error'] ) {
				$errors[] = $outcome['error'];
			}

			if ( count( $pending_sync_items ) >= 25 ) {
				self::flush_pending_sync_items( $pending_sync_items, $errors );
			}
		}

		if ( ! empty( $pending_sync_items ) ) {
			self::flush_pending_sync_items( $pending_sync_items, $errors );
		}

		if ( ! $headers ) {
			self::csv_redirect_with_message( 'mds_csv_missing_header', __( 'The CSV must include UPC/EAN and Release Type columns.', 'mds-release-manager' ), 'error' );
		}
		\MDS\ReleaseManager\Core\Sync::purge_cache( 'csv_import' );
		self::csv_redirect_with_message(
			'mds_csv_import_complete',
			sprintf( 'CSV import complete: %1$d created, %2$d updated.', $imported, $updated ) . ( $errors ? ' ' . implode( ' ', $errors ) : '' ),
			$errors ? 'warning' : 'success'
		);
	}

	/**
	 * Stores an import or export notice and redirects to the CSV page.
	 *
	 * @since 0.9.0
	 * @param string $code    The settings error code.
	 * @param string $message The notice message.
	 * @param string $type    The notice type.
	 */
	public static function csv_redirect_with_message( $code, $message, $type ): void {
		add_settings_error( 'mds_csv_msg', $code, $message, $type );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect(
			add_query_arg(
				'settings-updated',
				'true',
				admin_url( 'admin.php?page=mds-release-csv' )
			)
		);
		exit;
	}

	/**
	 * Renders the CSV import and export forms.
	 *
	 * @since 0.9.0
	 */
	public static function csv_page(): void {
		settings_errors( 'mds_csv_msg' );

		$artist_options = array();
		$artist_posts   = get_posts(
			array(
				'post_type'      => 'artist',
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		foreach ( $artist_posts as $artist_post ) {
			$uuid = (string) get_post_meta( $artist_post->ID, '_mds_artist_uuid', true );
			if ( '' !== $uuid ) {
				$artist_options[] = array(
					'uuid' => $uuid,
					'name' => $artist_post->post_title,
				);
			}
		}

		$type_options = array();
		$type_terms   = get_terms(
			array(
				'taxonomy'   => 'release_type',
				'hide_empty' => false,
			)
		);
		if ( is_array( $type_terms ) ) {
			foreach ( $type_terms as $term ) {
				$type_options[] = array(
					'id'   => (int) $term->term_id,
					'name' => (string) $term->name,
				);
			}
		}

		$args = array(
			'rest_export_url' => esc_url_raw( rest_url( 'mds/v1/csv/export' ) ),
			'rest_nonce'      => wp_create_nonce( 'wp_rest' ),
			'artists'         => $artist_options,
			'release_types'   => $type_options,
		);

    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The template escapes the URL and emits the nonce field.
		echo \MDS\ReleaseManager\Core\TemplateLoader::render_admin_view( 'csv-page.php', $args );
	}
}

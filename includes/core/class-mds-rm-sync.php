<?php
/**
 * Synchronization helpers for the MDS Release Manager plugin.
 *
 * @package MDS_Release_Manager
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Synchronizes release and artist data across the network.
 */
final class MDS_RM_Sync {

	/**
	 * Maximum catalog records processed by one manual-sync request.
	 *
	 * @var int
	 */
	private const NETWORK_SYNC_BATCH_SIZE = 10;

	/**
	 * Maximum number of detailed errors retained for a resumable sync job.
	 *
	 * @var int
	 */
	private const NETWORK_SYNC_MAX_ERRORS = 100;

	/**
	 * Private or managed meta keys that should not be blindly copied from hub to spoke.
	 *
	 * @var string[]
	 */
	private const EXCLUDED_SYNC_META_KEYS = array(
		'_edit_lock',
		'_edit_last',
		'_thumbnail_id',
		'_mds_hub_release_key',
		'_mds_normalized_upc',
		'_wp_old_slug',
		'_wp_old_date',
	);

	/**
	 * Returns the hub (master) site ID for the network.
	 *
	 * @since 1.0.0
	 * @return int The hub site ID, or the current site ID outside multisite.
	 */
	public static function hub_id(): int {
		if ( ! is_multisite() ) {
			return (int) get_current_blog_id();
		}

		return (int) get_site_option( 'mds_rm_master_blog_id', get_main_site_id() );
	}

	/**
	 * Determines whether the current site is the hub.
	 *
	 * Single-site installations always count as the hub.
	 *
	 * @since 1.0.0
	 * @return bool True when catalog editing is allowed on this site.
	 */
	public static function is_hub(): bool {
		return ! is_multisite() || get_current_blog_id() === self::hub_id();
	}

	/**
	 * Purges the LiteSpeed cache when its purge hook is available.
	 *
	 * @since 0.9.0
	 */
	public static function purge_cache(): void {
		if ( defined( 'LSCWP_V' ) || has_action( 'litespeed_purge_all' ) ) {
			do_action( 'litespeed_purge_all' );
		}
	}

	/**
	 * Processes one bounded batch of a resumable full-network synchronization.
	 *
	 * A missing job ID starts a sync or resumes the currently running job. The
	 * returned job ID can be passed to subsequent calls after an interruption.
	 *
	 * @param string $job_id Existing job ID, or an empty string to start/resume.
	 * @return array<string, mixed>|WP_Error Current job progress or an error.
	 */
	public static function run_network_sync_batch( string $job_id = '' ): array|WP_Error {
		if ( ! self::is_hub() ) {
			return new WP_Error( 'not_master', __( 'Network synchronization must run from the hub site.', 'mds-release-manager' ), array( 'status' => 403 ) );
		}

		$lock_key   = 'mds_rm_network_sync_lock';
		$lock_token = wp_generate_uuid4();
		$lock_value = array(
			'token'      => $lock_token,
			'created_at' => time(),
		);

		if ( ! add_option( $lock_key, $lock_value, '', 'no' ) ) {
			$existing_lock = get_option( $lock_key );
			if ( is_array( $existing_lock ) && isset( $existing_lock['created_at'] ) && (int) $existing_lock['created_at'] < time() - 900 ) {
				delete_option( $lock_key );
			}
			if ( ! add_option( $lock_key, $lock_value, '', 'no' ) ) {
				return new WP_Error( 'mds_sync_busy', __( 'A network synchronization batch is already running. Please retry shortly.', 'mds-release-manager' ), array( 'status' => 409 ) );
			}
		}

		try {
			$job = get_option( 'mds_rm_network_sync_job' );
			if ( ! is_array( $job ) || empty( $job['job_id'] ) || 'running' !== ( $job['status'] ?? '' ) ) {
				$job = array();
			}

			if ( '' !== $job_id ) {
				$stored_job = get_option( 'mds_rm_network_sync_job' );
				if ( ! is_array( $stored_job ) || empty( $stored_job['job_id'] ) || ! hash_equals( (string) $stored_job['job_id'], $job_id ) ) {
					return new WP_Error( 'mds_sync_job_not_found', __( 'The requested synchronization job was not found.', 'mds-release-manager' ), array( 'status' => 404 ) );
				}
				$job = $stored_job;
			} elseif ( ! $job ) {
				$job = array(
					'job_id'          => wp_generate_uuid4(),
					'status'          => 'running',
					'phase'           => 'artists',
					'last_id'         => 0,
					'max_id'          => self::network_sync_max_post_id(),
					'artists_synced'  => 0,
					'releases_synced' => 0,
					'error_count'     => 0,
					'errors'          => array(),
				);
				update_option( 'mds_rm_network_sync_job', $job, false );
			}

			if ( 'complete' === ( $job['status'] ?? '' ) ) {
				return self::network_sync_result( $job );
			}

			$remaining = self::NETWORK_SYNC_BATCH_SIZE;
			$items     = array();

			while ( $remaining > 0 && 'running' === $job['status'] ) {
				$post_type = 'artists' === $job['phase'] ? 'artist' : 'release';
				$ids       = self::network_sync_batch_ids( $post_type, (int) $job['last_id'], (int) $job['max_id'], $remaining );

				foreach ( $ids as $id ) {
					$items[]        = array(
						'id'        => $id,
						'post_type' => $post_type,
					);
					$job['last_id'] = $id;
				}
				$remaining -= count( $ids );

				if ( count( $ids ) < ( $remaining + count( $ids ) ) ) {
					// Current phase has been exhausted; advance immediately without wasting an empty HTTP round-trip.
					if ( 'artists' === $job['phase'] ) {
						$job['phase']   = 'releases';
						$job['last_id'] = 0;
					} else {
						$job['phase']  = 'complete';
						$job['status'] = 'complete';
						break;
					}
				}
			}

			if ( $items ) {
				$batch_results = self::broadcast_batch_to_spokes( $items );
				foreach ( $items as $item ) {
					$key    = $item['post_type'] . ':' . $item['id'];
					$result = $batch_results[ $key ] ?? null;
					if ( is_wp_error( $result ) ) {
						++$job['error_count'];
						if ( count( $job['errors'] ) < self::NETWORK_SYNC_MAX_ERRORS ) {
							$job['errors'][] = sprintf( '%1$s %2$d: %3$s', ucfirst( $item['post_type'] ), $item['id'], $result->get_error_message() );
						}
					} else {
						$count_key = 'artist' === $item['post_type'] ? 'artists_synced' : 'releases_synced';
						++$job[ $count_key ];
					}
				}
			}

			if ( 'complete' === $job['status'] ) {
				self::purge_cache();
			}

			$job['updated_at'] = time();
			update_option( 'mds_rm_network_sync_job', $job, false );

			return self::network_sync_result( $job );
		} finally {
			$current_lock = get_option( $lock_key );
			if ( is_array( $current_lock ) && isset( $current_lock['token'] ) && hash_equals( $lock_token, (string) $current_lock['token'] ) ) {
				delete_option( $lock_key );
			}
		}
	}

	/**
	 * Fetches one ascending-ID batch without loading the complete catalog.
	 *
	 * @param string $post_type Post type to query.
	 * @param int    $after_id  Exclusive ID cursor.
	 * @param int    $max_id    Maximum ID captured when the job started.
	 * @param int    $limit     Maximum rows to fetch.
	 * @return int[] Post IDs.
	 */
	private static function network_sync_batch_ids( string $post_type, int $after_id, int $max_id, int $limit = self::NETWORK_SYNC_BATCH_SIZE ): array {
		global $wpdb;

		$statuses = array_values( array_diff( get_post_stati(), array( 'auto-draft', 'inherit', 'trash' ) ) );
		if ( ! $statuses || $limit <= 0 ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$sql          = $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ({$placeholders}) AND ID > %d AND ID <= %d ORDER BY ID ASC LIMIT %d",
			array_merge( array( $post_type ), $statuses, array( $after_id, $max_id, $limit ) )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Keyset pagination requires an ID cursor and avoids loading the full catalog.
		return array_map( 'intval', $wpdb->get_col( $sql ) );
	}

	/**
	 * Captures a high-water post ID so each sync job processes a finite snapshot.
	 *
	 * @return int Maximum post ID at job start.
	 */
	private static function network_sync_max_post_id(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Snapshot boundary for cursor-based network synchronization.
		return (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" );
	}

	/**
	 * Formats a sync job's current state for REST and Abilities API clients.
	 *
	 * @param array<string, mixed> $job Stored job state.
	 * @return array<string, mixed> Progress response.
	 */
	private static function network_sync_result( array $job ): array {
		$done     = 'complete' === ( $job['status'] ?? '' );
		$phase    = (string) ( $job['phase'] ?? 'artists' );
		$artists  = (int) ( $job['artists_synced'] ?? 0 );
		$releases = (int) ( $job['releases_synced'] ?? 0 );
		$errors   = (int) ( $job['error_count'] ?? 0 );
		$message  = $done
			? sprintf(
				/* translators: %1$d: Release count. %2$d: Artist count. */
				__( 'Sync completed: %1$d releases and %2$d artists processed.', 'mds-release-manager' ),
				$releases,
				$artists
			)
			: sprintf(
				/* translators: %1$s: Current sync phase. %2$d: Release count. %3$d: Artist count. */
				__( 'Sync in progress (%1$s): %2$d releases and %3$d artists processed.', 'mds-release-manager' ),
				$phase,
				$releases,
				$artists
			);

		if ( $errors ) {
			$message .= ' ' . sprintf(
				/* translators: %d: Number of errors. */
				__( '%d synchronization errors occurred.', 'mds-release-manager' ),
				$errors
			);
		}

		return array(
			'success'         => 0 === $errors,
			'done'            => $done,
			'job_id'          => (string) ( $job['job_id'] ?? '' ),
			'phase'           => $phase,
			'message'         => $message,
			'artists_synced'  => $artists,
			'releases_synced' => $releases,
			'error_count'     => $errors,
			'errors'          => is_array( $job['errors'] ?? null ) ? $job['errors'] : array(),
		);
	}

	/**
	 * Ensures a taxonomy term exists on the current blog.
	 *
	 * Used during CSV import and broadcast synchronization.
	 *
	 * @since 0.9.0
	 * @param string $term_name The term name.
	 * @param string $taxonomy  The taxonomy name.
	 * @return int|null The term ID, or null if creation failed.
	 */
	public static function ensure_term_exists( $term_name, $taxonomy ): ?int {
		if ( empty( $term_name ) ) {
			return null;
		}
		$term = term_exists( $term_name, $taxonomy );
		if ( ! $term ) {
			$term = wp_insert_term( $term_name, $taxonomy );
		}
		if ( is_wp_error( $term ) ) {
			return null;
		}
		return is_array( $term ) ? (int) $term['term_id'] : (int) $term;
	}

	/**
	 * Synchronizes a release or artist post to configured spoke sites.
	 *
	 * @since 0.9.0
	 * @param int    $post_id   The source post ID.
	 * @param string $post_type The source post type.
	 * @return true|WP_Error|null True on success, an error on failure, or null when synchronization is not applicable.
	 */
	public static function broadcast_to_spokes( $post_id, $post_type ): bool|WP_Error|null {
		$results = self::broadcast_batch_to_spokes(
			array(
				array(
					'id'        => (int) $post_id,
					'post_type' => (string) $post_type,
				),
			)
		);
		$key = $post_type . ':' . (int) $post_id;
		return $results[ $key ] ?? null;
	}

	/**
	 * Synchronizes a batch of Hub posts to their configured spoke sites, switching to each spoke once per batch.
	 *
	 * @param array<int, array{id:int,post_type:string}> $items List of Hub post IDs and post types.
	 * @return array<string, bool|WP_Error|null> Per-record results keyed by `"{post_type}:{id}"`.
	 */
	public static function broadcast_batch_to_spokes( array $items ): array {
		$results = array();
		if ( ! is_multisite() || ! $items ) {
			return $results;
		}

		// Prevent infinite loops if saving happens inside a switched context.
		static $is_broadcasting = false;
		if ( $is_broadcasting ) {
			return $results;
		}

		$master_hub_id   = self::hub_id();
		$current_blog_id = get_current_blog_id();

		// Only broadcast if we are saving on the Master Hub.
		if ( $current_blog_id !== $master_hub_id ) {
			return $results;
		}

		$payloads_by_spoke = array();
		$record_errors     = array();
		$record_applicable = array();

		foreach ( $items as $item ) {
			$post_id   = (int) $item['id'];
			$post_type = (string) $item['post_type'];
			$rec_key   = $post_type . ':' . $post_id;

			$payload = self::collect_hub_payload( $post_id, $post_type, $master_hub_id );
			if ( null === $payload ) {
				$results[ $rec_key ] = null;
				continue;
			}
			if ( is_wp_error( $payload ) ) {
				$results[ $rec_key ] = $payload;
				continue;
			}

			$record_applicable[ $rec_key ] = true;
			$record_errors[ $rec_key ]     = array();
			foreach ( $payload['spokes'] as $spoke_id ) {
				if ( $spoke_id === $master_hub_id ) {
					continue;
				}
				$payloads_by_spoke[ $spoke_id ][] = array(
					'key'     => $rec_key,
					'payload' => $payload,
				);
			}
		}

		if ( ! $payloads_by_spoke ) {
			foreach ( array_keys( $record_applicable ) as $rec_key ) {
				$results[ $rec_key ] = true;
			}
			return $results;
		}

		$is_broadcasting = true;
		try {
			foreach ( $payloads_by_spoke as $spoke_id => $spoke_items ) {
				switch_to_blog( (int) $spoke_id );
				try {
					foreach ( $spoke_items as $entry ) {
						$err = self::apply_payload_to_current_spoke( $entry['payload'], (int) $spoke_id );
						if ( null !== $err ) {
							$record_errors[ $entry['key'] ][] = $err;
						}
					}
				} finally {
					restore_current_blog();
				}
			}
		} finally {
			$is_broadcasting = false;
		}

		foreach ( array_keys( $record_applicable ) as $rec_key ) {
			if ( ! empty( $record_errors[ $rec_key ] ) ) {
				$results[ $rec_key ] = new WP_Error( 'mds_sync_failed', implode( ' ', $record_errors[ $rec_key ] ) );
			} else {
				$results[ $rec_key ] = true;
			}
		}

		return $results;
	}

	/**
	 * Collects all Hub post, meta, taxonomy, and thumbnail metadata prior to blog switching.
	 *
	 * @param int    $post_id       Hub post ID.
	 * @param string $post_type     Post type (`release` or `artist`).
	 * @param int    $master_hub_id Hub blog ID.
	 * @return array<string, mixed>|WP_Error|null Payload array, WP_Error on validation failure, or null if not applicable.
	 */
	private static function collect_hub_payload( int $post_id, string $post_type, int $master_hub_id ): array|WP_Error|null {
		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post_type, array( 'release', 'artist' ), true ) ) {
			return null;
		}

		$spokes = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', (array) apply_filters( 'mds_rm_sync_target_sites', array(), $post_id, $post_type ) ),
					static fn( int $id ): bool => $id > 0 && $id !== $master_hub_id
				)
			)
		);
		if ( empty( $spokes ) ) {
			return null;
		}

		$uuid = get_post_meta( $post_id, 'artist' === $post_type ? '_mds_artist_uuid' : 'upc_ean', true );
		if ( '' === (string) $uuid ) {
			return new WP_Error( 'mds_sync_missing_identity', __( 'Cannot synchronize a record without its identifier.', 'mds-release-manager' ) );
		}
		if ( 'release' === $post_type && ( is_wp_error( MDS_RM_Release_Model::format_name( $post_id ) ) || MDS_RM_Release_Model::has_legacy_format_conflict( $post_id ) ) ) {
			return new WP_Error( 'mds_sync_invalid_format', __( 'Cannot synchronize a release with an invalid or conflicting format.', 'mds-release-manager' ) );
		}

		$raw_meta = get_post_meta( $post_id );
		$meta     = array();
		foreach ( $raw_meta as $key => $values ) {
			if ( in_array( $key, self::EXCLUDED_SYNC_META_KEYS, true ) ) {
				continue;
			}
			if ( 'artist' === $post_type && 'artist_featured_image' === $key ) {
				continue;
			}
			$meta[ $key ] = $values;
		}

		$previous_upcs = json_decode( (string) get_post_meta( $post_id, '_mds_previous_upcs', true ), true );
		$previous_upcs = is_array( $previous_upcs ) ? $previous_upcs : array();

		$taxonomies       = get_object_taxonomies( $post_type );
		$terms_data       = array();
		$media_icon_cache = array();
		foreach ( $taxonomies as $tax ) {
			$terms = wp_get_post_terms( $post_id, $tax, array( 'fields' => 'names' ) );
			if ( is_wp_error( $terms ) ) {
				return $terms;
			}
			$terms_data[ $tax ] = $terms;
			if ( 'release_media_format' === $tax && ! empty( $terms ) ) {
				$term_objs = wp_get_post_terms( $post_id, $tax );
				if ( ! is_wp_error( $term_objs ) ) {
					foreach ( $term_objs as $term_obj ) {
						$media_icon_cache[ $term_obj->name ] = MDS_RM_Release_Model::get_media_format_icon( (int) $term_obj->term_id, $term_obj->name );
					}
				}
			}
		}

		$thumbnail_id = (int) get_post_thumbnail_id( $post_id );
		$hub_img_url  = $thumbnail_id ? (string) wp_get_attachment_url( $thumbnail_id ) : '';
		$media_info   = array( 'hub_blog_id' => $master_hub_id );
		if ( $thumbnail_id && '' !== $hub_img_url ) {
			$att_meta = wp_get_attachment_metadata( $thumbnail_id );
			if ( is_array( $att_meta ) ) {
				if ( ! empty( $att_meta['width'] ) ) {
					$media_info['width'] = absint( $att_meta['width'] );
				}
				if ( ! empty( $att_meta['height'] ) ) {
					$media_info['height'] = absint( $att_meta['height'] );
				}
			}
			$mime_type = get_post_mime_type( $thumbnail_id );
			if ( is_string( $mime_type ) && '' !== $mime_type ) {
				$media_info['mime_type'] = $mime_type;
			}
		}

		return array(
			'post'            => $post,
			'post_id'         => $post_id,
			'post_type'       => $post_type,
			'spokes'          => $spokes,
			'uuid'            => (string) $uuid,
			'hub_release_key' => $master_hub_id . ':' . $post_id,
			'previous_upcs'   => $previous_upcs,
			'meta'            => $meta,
			'terms_data'      => $terms_data,
			'media_icons'     => $media_icon_cache,
			'thumbnail_id'    => $thumbnail_id,
			'hub_img_url'     => $hub_img_url,
			'media_info'      => $media_info,
		);
	}

	/**
	 * Applies a collected Hub payload to the currently switched spoke site.
	 *
	 * @param array<string, mixed> $payload  Collected Hub payload.
	 * @param int                  $spoke_id Current spoke blog ID.
	 * @return string|null Error message on failure, or null on success.
	 */
	private static function apply_payload_to_current_spoke( array $payload, int $spoke_id ): ?string {
		$post            = $payload['post'];
		$post_id         = (int) $payload['post_id'];
		$post_type       = (string) $payload['post_type'];
		$uuid            = (string) $payload['uuid'];
		$hub_release_key = (string) $payload['hub_release_key'];
		$previous_upcs   = (array) $payload['previous_upcs'];
		$meta            = (array) $payload['meta'];
		$terms_data      = (array) $payload['terms_data'];
		$thumbnail_id    = (int) $payload['thumbnail_id'];
		$hub_img_url     = (string) $payload['hub_img_url'];
		$media_info      = (array) $payload['media_info'];

		// Prefer a stable hub identity; legacy shadows are adopted by their current or former UPC.
		$shadow_post_id = 0;
		$sync_conflict  = false;
		$has_stable_key = false;

		if ( 'artist' === $post_type ) {
			$shadow_posts = get_posts(
				array(
					'post_type'              => $post_type,
					'post_status'            => array_keys( get_post_stati() ),
					'posts_per_page'         => 2,
					'fields'                 => 'ids',
					'meta_key'               => '_mds_artist_uuid', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Artist shadows match their stable UUID.
					'meta_value'             => $uuid, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'orderby'                => array(
						'date' => 'DESC',
						'ID'   => 'DESC',
					),
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			if ( count( $shadow_posts ) > 1 ) {
				$sync_conflict = true;
			} elseif ( $shadow_posts ) {
				$shadow_post_id = (int) $shadow_posts[0];
			}
		} else {
			$stable_posts = get_posts(
				array(
					'post_type'              => $post_type,
					'post_status'            => array_keys( get_post_stati() ),
					'posts_per_page'         => 2,
					'fields'                 => 'ids',
					'meta_key'               => '_mds_hub_release_key', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Stable hub identity takes precedence over legacy UPC lookup.
					'meta_value'             => $hub_release_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			if ( count( $stable_posts ) > 1 ) {
				$sync_conflict = true;
			} elseif ( $stable_posts ) {
				$shadow_post_id = (int) $stable_posts[0];
				$has_stable_key = true;
			}

			if ( ! $sync_conflict && ! $has_stable_key ) {
				$upc_identifiers = array_values( array_unique( array_filter( array_merge( array( $uuid ), array_filter( $previous_upcs, 'is_string' ) ) ) ) );
				if ( $upc_identifiers ) {
					$shadow_posts = get_posts(
						array(
							'post_type'              => $post_type,
							'post_status'            => array_keys( get_post_stati() ),
							'posts_per_page'         => 2,
							'fields'                 => 'ids',
							'meta_key'               => 'upc_ean', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One bounded lookup covers current and former UPCs.
							'meta_value'             => $upc_identifiers, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
							'meta_compare'           => 'IN',
							'no_found_rows'          => true,
							'update_post_meta_cache' => false,
							'update_post_term_cache' => false,
						)
					);
					if ( count( $shadow_posts ) > 1 ) {
						$sync_conflict = true;
					} elseif ( $shadow_posts ) {
						$candidate  = (int) $shadow_posts[0];
						$linked_key = get_post_meta( $candidate, '_mds_hub_release_key', true );
						if ( $linked_key && $linked_key !== $hub_release_key ) {
							$sync_conflict = true;
						} else {
							$shadow_post_id = $candidate;
						}
					}
				}
			}
		}

		if ( 'release' === $post_type && $has_stable_key && ! $sync_conflict ) {
			$conflicts     = get_posts(
				array(
					'post_type'              => 'release',
					'post_status'            => array_keys( get_post_stati() ),
					'posts_per_page'         => 1,
					'fields'                 => 'ids',
					'post__not_in'           => $shadow_post_id ? array( $shadow_post_id ) : array(), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- The matched shadow is not a conflict.
					'meta_key'               => 'upc_ean', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Reject a competing spoke release with the same stored UPC.
					'meta_value'             => $uuid, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			$sync_conflict = ! empty( $conflicts );
		}

		if ( $sync_conflict ) {
			/* translators: %d: Spoke site ID. */
			return sprintf( __( 'Site %d has conflicting identifiers.', 'mds-release-manager' ), $spoke_id );
		}

		// Prepare core post data and skip wp_update_post when unchanged.
		$post_data = array(
			'post_title'    => $post->post_title,
			'post_content'  => $post->post_content,
			'post_excerpt'  => $post->post_excerpt,
			'post_status'   => $post->post_status,
			'post_type'     => $post_type,
			'post_name'     => $post->post_name,
			'post_date'     => $post->post_date,
			'post_date_gmt' => $post->post_date_gmt,
		);

		if ( $shadow_post_id ) {
			$existing_post = get_post( $shadow_post_id );
			$needs_update  = ! $existing_post
				|| $existing_post->post_title !== $post_data['post_title']
				|| $existing_post->post_content !== $post_data['post_content']
				|| $existing_post->post_excerpt !== $post_data['post_excerpt']
				|| $existing_post->post_status !== $post_data['post_status']
				|| $existing_post->post_name !== $post_data['post_name']
				|| $existing_post->post_date !== $post_data['post_date'];
			if ( $needs_update ) {
				$post_data['ID'] = $shadow_post_id;
				$shadow_post_id  = wp_update_post( $post_data, true );
			}
		} else {
			$shadow_post_id = wp_insert_post( $post_data, true );
		}

		if ( ! $shadow_post_id || is_wp_error( $shadow_post_id ) ) {
			/* translators: %d: Spoke site ID. */
			return sprintf( __( 'Could not save the shadow record on site %d.', 'mds-release-manager' ), $spoke_id );
		}

		$shadow_post_id = (int) $shadow_post_id;

		// Diff-based meta sync: only delete removed keys and update changed keys.
		self::sync_shadow_meta( $shadow_post_id, $meta );

		if ( 'release' === $post_type && get_post_meta( $shadow_post_id, '_mds_hub_release_key', true ) !== $hub_release_key ) {
			update_post_meta( $shadow_post_id, '_mds_hub_release_key', $hub_release_key );
		}

		// Sync thumbnail via virtual attachment only when changed.
		$virt_id       = $thumbnail_id && '' !== $hub_img_url
			? (int) MDS_RM_Virtual_Media::get_or_create_virtual_attachment( $hub_img_url, $thumbnail_id, $media_info )
			: 0;
		$current_thumb = (int) get_post_thumbnail_id( $shadow_post_id );
		if ( $virt_id > 0 ) {
			if ( $current_thumb !== $virt_id ) {
				set_post_thumbnail( $shadow_post_id, $virt_id );
			}
		} elseif ( $current_thumb > 0 ) {
			delete_post_thumbnail( $shadow_post_id );
		}

		do_action( 'mds_rm_after_shadow_meta_sync', $shadow_post_id, $spoke_id, $post_id, $meta );

		// Sync taxonomies only when terms differ.
		$errors      = array();
		$media_icons = is_array( $payload['media_icons'] ?? null ) ? $payload['media_icons'] : array();
		foreach ( $terms_data as $tax => $names ) {
			$existing_terms = wp_get_post_terms( $shadow_post_id, $tax, array( 'fields' => 'names' ) );
			$terms_match    = false;
			if ( ! is_wp_error( $existing_terms ) ) {
				$desired_sorted  = array_values( $names );
				$existing_sorted = array_values( $existing_terms );
				sort( $desired_sorted );
				sort( $existing_sorted );
				$terms_match = ( $desired_sorted === $existing_sorted );
			}

			if ( ! $terms_match ) {
				$is_hierarchical = is_taxonomy_hierarchical( $tax );
				if ( $is_hierarchical ) {
					$term_ids = array();
					foreach ( $names as $name ) {
						$tid = self::ensure_term_exists( $name, $tax );
						if ( $tid ) {
							$term_ids[] = $tid;
						} else {
							/* translators: %d: Spoke site ID. */
							$errors[] = sprintf( __( 'Could not synchronize a taxonomy term on site %d.', 'mds-release-manager' ), $spoke_id );
						}
					}
					if ( count( $term_ids ) !== count( $names ) ) {
						continue;
					}
					$result = wp_set_post_terms( $shadow_post_id, $term_ids, $tax, false );
				} else {
					$result = wp_set_post_terms( $shadow_post_id, $names, $tax, false );
				}
				if ( is_wp_error( $result ) ) {
					/* translators: %1$d: Spoke site ID. %2$s: Error message. */
					$errors[] = sprintf( __( 'Could not synchronize taxonomy on site %1$d: %2$s', 'mds-release-manager' ), $spoke_id, $result->get_error_message() );
				}
			}

			if ( 'release_media_format' === $tax && $media_icons ) {
				foreach ( $media_icons as $term_name => $icon_val ) {
					$spoke_term = get_term_by( 'name', $term_name, 'release_media_format' );
					if ( $spoke_term && ! is_wp_error( $spoke_term ) && get_term_meta( (int) $spoke_term->term_id, MDS_RM_Release_Model::MEDIA_FORMAT_ICON_META_KEY, true ) !== $icon_val ) {
						update_term_meta( (int) $spoke_term->term_id, MDS_RM_Release_Model::MEDIA_FORMAT_ICON_META_KEY, $icon_val );
					}
				}
			}
		}

		return $errors ? implode( ' ', $errors ) : null;
	}

	/**
	 * Synchronizes post meta onto a shadow post using a diff so unchanged keys are not deleted and re-inserted.
	 *
	 * @param int                         $shadow_post_id Spoke shadow post ID.
	 * @param array<string, array<mixed>> $hub_meta       Filtered hub post meta array (`key => list of raw meta strings`).
	 * @return void
	 */
	private static function sync_shadow_meta( int $shadow_post_id, array $hub_meta ): void {
		$existing_meta = get_post_meta( $shadow_post_id );
		if ( ! is_array( $existing_meta ) ) {
			$existing_meta = array();
		}

		// Delete keys present on the spoke that were removed on the hub (excluding managed/excluded keys).
		foreach ( array_keys( $existing_meta ) as $existing_key ) {
			if ( in_array( $existing_key, self::EXCLUDED_SYNC_META_KEYS, true ) ) {
				continue;
			}
			if ( ! array_key_exists( $existing_key, $hub_meta ) ) {
				delete_post_meta( $shadow_post_id, $existing_key );
			}
		}

		// Update only keys whose raw values actually differ.
		foreach ( $hub_meta as $key => $values ) {
			$existing_values = $existing_meta[ $key ] ?? null;
			if ( is_array( $existing_values ) && $existing_values === $values ) {
				continue;
			}

			if ( 1 === count( $values ) && ( null === $existing_values || 1 === count( $existing_values ) ) ) {
				update_post_meta( $shadow_post_id, $key, wp_slash( maybe_unserialize( $values[0] ) ) );
			} else {
				delete_post_meta( $shadow_post_id, $key );
				foreach ( $values as $val ) {
					add_post_meta( $shadow_post_id, $key, wp_slash( maybe_unserialize( $val ) ) );
				}
			}
		}
	}
}

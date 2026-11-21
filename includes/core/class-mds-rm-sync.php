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

			$post_type = 'artists' === $job['phase'] ? 'artist' : 'release';
			$ids       = self::network_sync_batch_ids( $post_type, (int) $job['last_id'], (int) $job['max_id'] );

			if ( ! $ids ) {
				if ( 'artists' === $job['phase'] ) {
					$job['phase']   = 'releases';
					$job['last_id'] = 0;
				} else {
					$job['phase']  = 'complete';
					$job['status'] = 'complete';
					self::purge_cache();
				}
			} else {
				foreach ( $ids as $id ) {
					$result = self::broadcast_to_spokes( $id, $post_type );
					if ( is_wp_error( $result ) ) {
						++$job['error_count'];
						if ( count( $job['errors'] ) < self::NETWORK_SYNC_MAX_ERRORS ) {
							$job['errors'][] = sprintf( '%1$s %2$d: %3$s', ucfirst( $post_type ), $id, $result->get_error_message() );
						}
					} else {
						$count_key = 'artist' === $post_type ? 'artists_synced' : 'releases_synced';
						++$job[ $count_key ];
					}
					$job['last_id'] = $id;
				}
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
	 * @param int    $after_id Exclusive ID cursor.
	 * @param int    $max_id Maximum ID captured when the job started.
	 * @return int[] Post IDs.
	 */
	private static function network_sync_batch_ids( string $post_type, int $after_id, int $max_id ): array {
		global $wpdb;

		$statuses = array_values( array_diff( get_post_stati(), array( 'auto-draft', 'inherit', 'trash' ) ) );
		if ( ! $statuses ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$sql          = $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ({$placeholders}) AND ID > %d AND ID <= %d ORDER BY ID ASC LIMIT %d",
			array_merge( array( $post_type ), $statuses, array( $after_id, $max_id, self::NETWORK_SYNC_BATCH_SIZE ) )
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
		$done    = 'complete' === ( $job['status'] ?? '' );
		$phase   = (string) ( $job['phase'] ?? 'artists' );
		$artists = (int) ( $job['artists_synced'] ?? 0 );
		$releases = (int) ( $job['releases_synced'] ?? 0 );
		$errors  = (int) ( $job['error_count'] ?? 0 );
		$message = $done
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
		if ( ! is_multisite() ) {
			return null;
		}

		// Prevent infinite loops if saving happens inside a switched context.
		static $is_broadcasting = false;
		if ( $is_broadcasting ) {
			return null;
		}

		$master_hub_id   = self::hub_id();
		$current_blog_id = get_current_blog_id();

		// Only broadcast if we are saving on the Master Hub.
		if ( $current_blog_id !== $master_hub_id ) {
			return null;
		}

		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post_type, array( 'release', 'artist' ), true ) ) {
			return null;
		}

		$spokes = array_map( 'intval', (array) apply_filters( 'mds_rm_sync_target_sites', array(), $post_id, $post_type ) );
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

		$meta = get_post_meta( $post_id );
		if ( 'artist' === $post_type ) {
			unset( $meta['artist_featured_image'] );
		}
		$hub_release_key = $master_hub_id . ':' . $post_id;
		$previous_upcs   = json_decode( get_post_meta( $post_id, '_mds_previous_upcs', true ), true );
		$previous_upcs   = is_array( $previous_upcs ) ? $previous_upcs : array();
		$taxonomies      = get_object_taxonomies( $post_type );
		$terms_data      = array();
		foreach ( $taxonomies as $tax ) {
			$terms = wp_get_post_terms( $post_id, $tax, array( 'fields' => 'names' ) );
			if ( is_wp_error( $terms ) ) {
				return $terms;
			}
			$terms_data[ $tax ] = $terms;
		}

		$thumbnail_id = get_post_thumbnail_id( $post_id );
		$hub_img_url  = $thumbnail_id ? wp_get_attachment_url( $thumbnail_id ) : '';

		$is_broadcasting = true;
		$errors          = array();

		foreach ( $spokes as $spoke_id ) {
			if ( $spoke_id === $master_hub_id ) {
				continue;
			}

			switch_to_blog( $spoke_id );

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
					$upc_identifiers = array_values( array_unique( array_filter( array_merge( array( (string) $uuid ), array_filter( $previous_upcs, 'is_string' ) ) ) ) );
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
						'post_type'      => 'release',
						'post_status'    => array_keys( get_post_stati() ),
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'post__not_in'   => $shadow_post_id ? array( $shadow_post_id ) : array(), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- The matched shadow is not a conflict.
						'meta_key'       => 'upc_ean', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Reject a competing spoke release with the same stored UPC.
						'meta_value'     => $uuid, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
						'no_found_rows'  => true,
					)
				);
				$sync_conflict = ! empty( $conflicts );
			}
			if ( $sync_conflict ) {
				/* translators: %d: Spoke site ID. */
				$errors[] = sprintf( __( 'Site %d has conflicting identifiers.', 'mds-release-manager' ), $spoke_id );
				restore_current_blog();
				continue;
			}

			// Prepare core post data.
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
				$post_data['ID'] = $shadow_post_id;
				$shadow_post_id  = wp_update_post( $post_data, true );
			} else {
				$shadow_post_id = wp_insert_post( $post_data, true );
			}

			if ( ! $shadow_post_id || is_wp_error( $shadow_post_id ) ) {
				/* translators: %d: Spoke site ID. */
				$errors[] = sprintf( __( 'Could not save the shadow record on site %d.', 'mds-release-manager' ), $spoke_id );
				restore_current_blog();
				continue;
			}
			if ( $shadow_post_id && ! is_wp_error( $shadow_post_id ) ) {
				// Clean old meta.
				foreach ( array_keys( get_post_meta( $shadow_post_id ) ) as $ek ) {
					delete_post_meta( $shadow_post_id, $ek );
				}

				// Sync meta.
				foreach ( $meta as $key => $values ) {
					foreach ( $values as $val ) {
						add_post_meta( $shadow_post_id, $key, wp_slash( maybe_unserialize( $val ) ) );
					}
				}
				if ( 'release' === $post_type ) {
					update_post_meta( $shadow_post_id, '_mds_hub_release_key', $hub_release_key );
				}

				// Sync thumbnail via virtual attachment.
				$virt_id = $thumbnail_id
					? MDS_RM_Virtual_Media::get_or_create_virtual_attachment( $hub_img_url, $thumbnail_id )
					: 0;
				if ( $virt_id ) {
					set_post_thumbnail( $shadow_post_id, $virt_id );
				} else {
					delete_post_thumbnail( $shadow_post_id );
				}

				do_action( 'mds_rm_after_shadow_meta_sync', $shadow_post_id, $spoke_id, $post_id, $meta );

				// Sync taxonomies securely.
				foreach ( $terms_data as $tax => $names ) {
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
			}

			restore_current_blog();
		}

		$is_broadcasting = false;
		if ( $errors ) {
			return new WP_Error( 'mds_sync_failed', implode( ' ', $errors ) );
		}
		return true;
	}
}

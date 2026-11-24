<?php
/**
 * Transport-agnostic Hub-and-Spoke synchronization coordinator for MDS Release Manager.
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Core;

use MDS\ReleaseManager\Core\Sync\MultisiteTransport;
use MDS\ReleaseManager\Core\Sync\SyncQueue;
use MDS\ReleaseManager\Core\Sync\SyncTransportInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates Hub-to-Spoke catalog upserts, lifecycle tombstones (`trash`, `untrash`, `delete`),
 * and resumable / background full-network synchronization across pluggable transports.
 */
final class Sync {

	/**
	 * Maximum catalog records processed by one network-sync batch.
	 *
	 * @var int
	 */
	private const NETWORK_SYNC_BATCH_SIZE = 25;

	/**
	 * Maximum number of detailed errors retained for a resumable sync job.
	 *
	 * @var int
	 */
	private const NETWORK_SYNC_MAX_ERRORS = 100;

	/**
	 * MySQL advisory lock name for full-network sync mutual exclusion.
	 *
	 * @var string
	 */
	private const NETWORK_SYNC_MYSQL_LOCK = 'mds_rm_network_sync';

	/**
	 * Private or managed meta keys that should not be blindly copied from hub to spoke.
	 *
	 * @var string[]
	 */
	public const EXCLUDED_SYNC_META_KEYS = array(
		'_edit_lock',
		'_edit_last',
		'_thumbnail_id',
		'_mds_hub_release_key',
		'_mds_normalized_upc',
		'_wp_old_slug',
		'_wp_old_date',
	);

	/**
	 * Re-entrancy guard preventing recursive broadcasts during spoke writes.
	 *
	 * @var bool
	 */
	private static bool $is_broadcasting = false;

	/**
	 * Registers Hub-to-Spoke lifecycle hooks and the background sync queue worker.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'wp_trash_post', array( self::class, 'on_hub_trash_post' ), 10, 1 );
		add_action( 'untrash_post', array( self::class, 'on_hub_untrash_post' ), 10, 1 );
		add_action( 'before_delete_post', array( self::class, 'on_hub_delete_post' ), 10, 1 );
		SyncQueue::register_hooks();
	}

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
	 * Returns the configured Network Admin base Spoke site IDs (excluding the Hub).
	 *
	 * @since 2.1.0
	 *
	 * @return int[] Validated Spoke blog IDs.
	 */
	public static function base_spoke_sites(): array {
		if ( ! is_multisite() ) {
			return array();
		}

		$stored = get_site_option( 'mds_rm_spoke_sites', null );
		if ( null === $stored ) {
			$stored = get_site_option( 'spoke_sites', array() );
		}

		$master_hub_id = self::hub_id();
		return array_values(
			array_unique(
				array_filter(
					array_map( 'intval', (array) $stored ),
					static fn( int $id ): bool => $id > 0 && $id !== $master_hub_id
				)
			)
		);
	}

	/**
	 * Resolves the final target Spoke site IDs for a Hub record.
	 *
	 * Starts with Network Admin's `mds_rm_spoke_sites` and passes them through
	 * `mds_rm_sync_target_sites`, allowing extensions (such as WooCommerce Option B
	 * scoped stores) to add or filter target sites per Artist/Release.
	 *
	 * @since 2.1.0
	 *
	 * @param int    $post_id   Hub post ID.
	 * @param string $post_type Post type (`release` or `artist`).
	 * @return int[] Deduplicated target blog IDs (excluding Hub).
	 */
	public static function resolve_target_sites( int $post_id, string $post_type ): array {
		$master_hub_id = self::hub_id();
		$base_spokes   = self::base_spoke_sites();

		/**
		 * Filters the target Multisite Spoke blog IDs for a Hub Release or Artist broadcast.
		 *
		 * Core passes the Network Admin's configured `mds_rm_spoke_sites` as the base list.
		 * Extensions implementing scoped targets (e.g., per-Artist WooCommerce stores) may
		 * filter out unassigned store IDs and merge in assigned store IDs.
		 *
		 * @since 1.0.0
		 *
		 * @param int[]  $spokes    Base target blog IDs from Network Admin settings.
		 * @param int    $post_id   Hub post ID.
		 * @param string $post_type Post type (`release` or `artist`).
		 */
		$filtered = apply_filters( 'mds_rm_sync_target_sites', $base_spokes, $post_id, $post_type );

		return array_values(
			array_unique(
				array_filter(
					array_map( 'intval', (array) $filtered ),
					static fn( int $id ): bool => $id > 0 && $id !== $master_hub_id
				)
			)
		);
	}

	/**
	 * Returns all registered synchronization transports.
	 *
	 * Future remote WordPress installs can register a `RestWebhookTransport`
	 * implementing `SyncTransportInterface` via `mds_rm_register_sync_transports`.
	 *
	 * @since 2.1.0
	 *
	 * @return SyncTransportInterface[] Valid registered transports.
	 */
	public static function transports(): array {
		$builtin = array(
			new MultisiteTransport(),
		);

		/**
		 * Filters the registered Hub-to-Spoke synchronization transports.
		 *
		 * @since 2.1.0
		 *
		 * @param SyncTransportInterface[] $transports Registered transport instances.
		 */
		$filtered = apply_filters( 'mds_rm_register_sync_transports', $builtin );
		if ( ! is_array( $filtered ) ) {
			return $builtin;
		}

		$valid = array();
		foreach ( $filtered as $transport ) {
			if ( $transport instanceof SyncTransportInterface ) {
				$valid[] = $transport;
			}
		}

		return $valid ? $valid : $builtin;
	}

	/**
	 * Requests a cache purge through the centralized cache manager.
	 *
	 * @since 0.9.0
	 * @param string               $context Trigger context ('general', 'post_save', 'settings', 'sync', 'manual').
	 * @param array<string, mixed> $args    Optional purge arguments (`immediate`, `force`, `blog_id`).
	 */
	public static function purge_cache( string $context = 'general', array $args = array() ): void {
		if ( class_exists( Cache::class ) ) {
			Cache::purge( $context, $args );
			return;
		}
		if ( defined( 'LSCWP_V' ) || has_action( 'litespeed_purge_all' ) ) {
			do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party LiteSpeed Cache hook.
		}
	}

	/**
	 * Mirrors a Hub `wp_trash_post` event onto target Spokes.
	 *
	 * @since 2.1.0
	 *
	 * @param int $post_id Hub post ID being trashed.
	 * @return void
	 */
	public static function on_hub_trash_post( int $post_id ): void {
		self::broadcast_lifecycle_event( $post_id, 'trash' );
	}

	/**
	 * Mirrors a Hub `untrash_post` event onto target Spokes.
	 *
	 * @since 2.1.0
	 *
	 * @param int $post_id Hub post ID being untrashed.
	 * @return void
	 */
	public static function on_hub_untrash_post( int $post_id ): void {
		self::broadcast_lifecycle_event( $post_id, 'untrash' );
	}

	/**
	 * Mirrors a Hub `before_delete_post` event onto target Spokes.
	 *
	 * @since 2.1.0
	 *
	 * @param int $post_id Hub post ID being permanently deleted.
	 * @return void
	 */
	public static function on_hub_delete_post( int $post_id ): void {
		self::broadcast_lifecycle_event( $post_id, 'delete' );
	}

	/**
	 * Dispatches a lifecycle tombstone (`trash`, `untrash`, `delete`) to all targets for a Hub post.
	 *
	 * @since 2.1.0
	 *
	 * @param int    $post_id Hub post ID.
	 * @param string $action  Lifecycle action (`trash`, `untrash`, `delete`).
	 * @return void
	 */
	public static function broadcast_lifecycle_event( int $post_id, string $action ): void {
		if ( self::$is_broadcasting || ! self::is_hub() || ! in_array( $action, array( 'trash', 'untrash', 'delete' ), true ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, array( 'release', 'artist' ), true ) ) {
			return;
		}

		$post_type     = $post->post_type;
		$master_hub_id = self::hub_id();
		$targets       = self::resolve_targets_for_post( $post_id, $post_type );
		if ( empty( $targets ) ) {
			return;
		}

		$uuid = (string) get_post_meta( $post_id, 'artist' === $post_type ? '_mds_artist_uuid' : 'upc_ean', true );

		$tombstone = array(
			'key'             => $post_type . ':' . $post_id,
			'action'          => $action,
			'post_id'         => $post_id,
			'post_type'       => $post_type,
			'uuid'            => $uuid,
			'hub_release_key' => $master_hub_id . ':' . $post_id,
		);

		$transports            = self::transports();
		self::$is_broadcasting = true;
		try {
			foreach ( $targets as $target ) {
				foreach ( $transports as $transport ) {
					if ( $transport->supports( $target ) ) {
						$transport->delete_batch( $target, array( $tombstone ) );
						break;
					}
				}
			}
			if ( class_exists( Cache::class ) ) {
				Cache::queue_blog( $master_hub_id );
			}
		} finally {
			self::$is_broadcasting = false;
		}
	}

	/**
	 * Returns the current resumable/background network sync status for UI polling.
	 *
	 * @since 2.1.0
	 *
	 * @return array<string, mixed> Current job state and queue driver.
	 */
	public static function get_network_sync_status(): array {
		$job = get_option( 'mds_rm_network_sync_job' );
		if ( ! is_array( $job ) || empty( $job['job_id'] ) ) {
			return array(
				'success'         => true,
				'done'            => true,
				'status'          => 'idle',
				'job_id'          => '',
				'phase'           => 'idle',
				'driver'          => SyncQueue::driver(),
				'artists_synced'  => 0,
				'releases_synced' => 0,
				'error_count'     => 0,
				'errors'          => array(),
				'message'         => __( 'No synchronization job is currently running.', 'mds-release-manager' ),
			);
		}

		return self::network_sync_result( $job );
	}

	/**
	 * Processes one bounded batch of a resumable full-network synchronization.
	 *
	 * Uses a MySQL advisory lock (`GET_LOCK`) when available to avoid object-cache
	 * mutex races under Redis/Memcached.
	 *
	 * @param string $job_id Existing job ID, or an empty string to start/resume.
	 * @return array<string, mixed>|\WP_Error Current job progress or an error.
	 */
	public static function run_network_sync_batch( string $job_id = '' ): array|\WP_Error {
		if ( ! self::is_hub() ) {
			return new \WP_Error( 'not_master', __( 'Network synchronization must run from the hub site.', 'mds-release-manager' ), array( 'status' => 403 ) );
		}

		$lock = self::acquire_sync_lock();
		if ( false === $lock ) {
			return new \WP_Error( 'mds_sync_busy', __( 'A network synchronization batch is already running. Please retry shortly.', 'mds-release-manager' ), array( 'status' => 409 ) );
		}

		try {
			$job = get_option( 'mds_rm_network_sync_job' );
			if ( ! is_array( $job ) || empty( $job['job_id'] ) || 'running' !== ( $job['status'] ?? '' ) ) {
				$job = array();
			}

			if ( '' !== $job_id ) {
				$stored_job = get_option( 'mds_rm_network_sync_job' );
				if ( ! is_array( $stored_job ) || empty( $stored_job['job_id'] ) || ! hash_equals( (string) $stored_job['job_id'], $job_id ) ) {
					return new \WP_Error( 'mds_sync_job_not_found', __( 'The requested synchronization job was not found.', 'mds-release-manager' ), array( 'status' => 404 ) );
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
				self::purge_cache( 'sync' );
			}

			$job['updated_at'] = time();
			update_option( 'mds_rm_network_sync_job', $job, false );

			return self::network_sync_result( $job );
		} finally {
			self::release_sync_lock( $lock );
		}
	}

	/**
	 * Acquires a mutual-exclusion lock for network sync (MySQL `GET_LOCK`, with option fallback).
	 *
	 * @return string|false Lock token/type on success, or false if busy.
	 */
	private static function acquire_sync_lock(): string|false {
		global $wpdb;

		if ( $wpdb instanceof \wpdb ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$acquired = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::NETWORK_SYNC_MYSQL_LOCK ) );
			return 1 === $acquired ? 'mysql' : false;
		}

		$lock_key   = 'mds_rm_network_sync_lock';
		$lock_token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'mds_', true );
		$lock_value = array(
			'token'      => $lock_token,
			'created_at' => time(),
		);
		if ( ! add_option( $lock_key, $lock_value, '', 'no' ) ) {
			$existing = get_option( $lock_key );
			if ( is_array( $existing ) && isset( $existing['created_at'] ) && (int) $existing['created_at'] < time() - 900 ) {
				delete_option( $lock_key );
			}
			if ( ! add_option( $lock_key, $lock_value, '', 'no' ) ) {
				return false;
			}
		}

		return $lock_token;
	}

	/**
	 * Releases the acquired network sync lock.
	 *
	 * @param string $lock Lock token/type returned by `acquire_sync_lock()`.
	 * @return void
	 */
	private static function release_sync_lock( string $lock ): void {
		global $wpdb;

		if ( 'mysql' === $lock && $wpdb instanceof \wpdb ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::NETWORK_SYNC_MYSQL_LOCK ) );
			return;
		}

		$lock_key     = 'mds_rm_network_sync_lock';
		$current_lock = get_option( $lock_key );
		if ( is_array( $current_lock ) && isset( $current_lock['token'] ) && hash_equals( $lock, (string) $current_lock['token'] ) ) {
			delete_option( $lock_key );
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
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic IN() placeholders are generated from get_post_stati().
		$sql = $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ({$placeholders}) AND ID > %d AND ID <= %d ORDER BY ID ASC LIMIT %d",
			array_merge( array( $post_type ), $statuses, array( $after_id, $max_id, $limit ) )
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above; keyset pagination avoids loading the full catalog.
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
		return (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type IN ('artist', 'release')" );
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
			'status'          => (string) ( $job['status'] ?? 'running' ),
			'job_id'          => (string) ( $job['job_id'] ?? '' ),
			'phase'           => $phase,
			'driver'          => SyncQueue::driver(),
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
	 * @return true|\WP_Error|null True on success, an error on failure, or null when synchronization is not applicable.
	 */
	public static function broadcast_to_spokes( $post_id, $post_type ): bool|\WP_Error|null {
		$results = self::broadcast_batch_to_spokes(
			array(
				array(
					'id'        => (int) $post_id,
					'post_type' => (string) $post_type,
				),
			)
		);
		$key     = $post_type . ':' . (int) $post_id;
		return $results[ $key ] ?? null;
	}

	/**
	 * Synchronizes a batch of Hub posts to their resolved targets through registered transports.
	 *
	 * @param array<int, array{id:int,post_type:string}> $items List of Hub post IDs and post types.
	 * @return array<string, bool|\WP_Error|null> Per-record results keyed by `"{post_type}:{id}"`.
	 */
	public static function broadcast_batch_to_spokes( array $items ): array {
		$results = array();
		if ( ! $items || self::$is_broadcasting ) {
			return $results;
		}

		$master_hub_id   = self::hub_id();
		$current_blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;

		if ( is_multisite() && $current_blog_id !== $master_hub_id ) {
			return $results;
		}

		$entries_by_target = array();
		$target_map        = array();
		$record_errors     = array();
		$record_applicable = array();

		foreach ( $items as $item ) {
			$post_id   = (int) ( $item['id'] ?? $item['post_id'] ?? 0 );
			$post_type = (string) ( $item['post_type'] ?? '' );
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
			foreach ( $payload['targets'] as $target ) {
				$target_key = self::target_key( $target );
				if ( '' === $target_key ) {
					continue;
				}
				$target_map[ $target_key ]          = $target;
				$entries_by_target[ $target_key ][] = array(
					'key'     => $rec_key,
					'payload' => $payload,
				);
			}
		}

		if ( ! $entries_by_target ) {
			foreach ( array_keys( $record_applicable ) as $rec_key ) {
				$results[ $rec_key ] = true;
			}
			return $results;
		}

		$transports            = self::transports();
		self::$is_broadcasting = true;
		try {
			foreach ( $entries_by_target as $target_key => $target_entries ) {
				$target = $target_map[ $target_key ];
				foreach ( $transports as $transport ) {
					if ( ! $transport->supports( $target ) ) {
						continue;
					}
					$target_results = $transport->push_batch( $target, $target_entries );
					foreach ( $target_results as $rec_key => $err ) {
						if ( is_string( $err ) && '' !== $err ) {
							$record_errors[ $rec_key ][] = $err;
						}
					}
					break;
				}
			}
			if ( class_exists( Cache::class ) ) {
				Cache::queue_blog( $master_hub_id );
			}
		} finally {
			self::$is_broadcasting = false;
		}

		foreach ( array_keys( $record_applicable ) as $rec_key ) {
			if ( ! empty( $record_errors[ $rec_key ] ) ) {
				$results[ $rec_key ] = new \WP_Error( 'mds_sync_failed', implode( ' ', $record_errors[ $rec_key ] ) );
			} else {
				$results[ $rec_key ] = true;
			}
		}

		return $results;
	}

	/**
	 * Builds normalized target descriptors (local Multisite blogs + any extension remote targets).
	 *
	 * @param int    $post_id   Hub post ID.
	 * @param string $post_type Post type (`release` or `artist`).
	 * @return array<int, array<string, mixed>> Target descriptors.
	 */
	private static function resolve_targets_for_post( int $post_id, string $post_type ): array {
		$targets = array();
		if ( is_multisite() ) {
			foreach ( self::resolve_target_sites( $post_id, $post_type ) as $spoke_id ) {
				$targets[] = array(
					'type'    => 'multisite',
					'blog_id' => $spoke_id,
				);
			}
		}

		/**
		 * Filters the normalized sync target descriptors for a Hub record.
		 *
		 * Future remote-spoke extensions can append remote targets such as
		 * `['type' => 'rest_webhook', 'endpoint' => 'https://...']` here.
		 *
		 * @since 2.1.0
		 *
		 * @param array<int, array<string, mixed>> $targets   Normalized target descriptors.
		 * @param int                              $post_id   Hub post ID.
		 * @param string                           $post_type Post type (`release` or `artist`).
		 */
		$filtered = apply_filters( 'mds_rm_sync_targets', $targets, $post_id, $post_type );
		return is_array( $filtered ) ? $filtered : $targets;
	}

	/**
	 * Computes a stable grouping key for a target descriptor.
	 *
	 * @param array<string, mixed> $target Target descriptor.
	 * @return string Grouping key.
	 */
	private static function target_key( array $target ): string {
		$type = isset( $target['type'] ) && is_string( $target['type'] ) ? $target['type'] : '';
		if ( 'multisite' === $type && ! empty( $target['blog_id'] ) ) {
			return 'multisite:' . (int) $target['blog_id'];
		}
		if ( '' !== $type && ! empty( $target['id'] ) ) {
			return $type . ':' . (string) $target['id'];
		}
		return '' !== $type ? $type . ':' . md5( (string) wp_json_encode( $target ) ) : '';
	}

	/**
	 * Collects all Hub post, meta, taxonomy, icon, and thumbnail data into a JSON-serializable payload.
	 *
	 * @param int    $post_id       Hub post ID.
	 * @param string $post_type     Post type (`release` or `artist`).
	 * @param int    $master_hub_id Hub blog ID.
	 * @return array<string, mixed>|\WP_Error|null Payload array, \WP_Error on validation failure, or null if not applicable.
	 */
	public static function collect_hub_payload( int $post_id, string $post_type, int $master_hub_id ): array|\WP_Error|null {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! in_array( $post_type, array( 'release', 'artist' ), true ) ) {
			return null;
		}

		$targets = self::resolve_targets_for_post( $post_id, $post_type );
		if ( empty( $targets ) ) {
			return null;
		}

		$uuid = get_post_meta( $post_id, 'artist' === $post_type ? '_mds_artist_uuid' : 'upc_ean', true );
		if ( '' === (string) $uuid ) {
			return new \WP_Error( 'mds_sync_missing_identity', __( 'Cannot synchronize a record without its identifier.', 'mds-release-manager' ) );
		}
		if ( 'release' === $post_type && ( is_wp_error( ReleaseModel::format_name( $post_id ) ) || ReleaseModel::has_legacy_format_conflict( $post_id ) ) ) {
			return new \WP_Error( 'mds_sync_invalid_format', __( 'Cannot synchronize a release with an invalid or conflicting format.', 'mds-release-manager' ) );
		}

		$raw_meta      = get_post_meta( $post_id );
		$excluded_keys = self::EXCLUDED_SYNC_META_KEYS;

		/**
		 * Filters post meta keys excluded from Hub-to-Spoke shadow synchronization.
		 *
		 * @since 2.0.0
		 * @param string[] $excluded_keys Meta keys to skip during sync.
		 * @param string   $post_type     Post type (`release` or `artist`).
		 */
		$filtered_excluded = apply_filters( 'mds_rm_sync_excluded_meta_keys', $excluded_keys, $post_type );
		if ( is_array( $filtered_excluded ) ) {
			$excluded_keys = array_values(
				array_unique(
					array_merge(
						self::EXCLUDED_SYNC_META_KEYS,
						array_filter( $filtered_excluded, 'is_string' )
					)
				)
			);
		}

		$meta = array();
		foreach ( (array) $raw_meta as $key => $values ) {
			if ( in_array( $key, $excluded_keys, true ) ) {
				continue;
			}
			if ( 'artist' === $post_type && 'artist_featured_image' === $key ) {
				continue;
			}
			$meta[ $key ] = $values;
		}

		$previous_upcs = json_decode( (string) get_post_meta( $post_id, '_mds_previous_upcs', true ), true );
		$previous_upcs = is_array( $previous_upcs ) ? $previous_upcs : array();

		$taxonomies         = get_object_taxonomies( $post_type );
		$terms_data         = array();
		$media_icon_cache   = array();
		$release_type_icons = array();
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
						$media_icon_cache[ $term_obj->name ] = ReleaseModel::get_media_format_icon( (int) $term_obj->term_id, $term_obj->name );
					}
				}
			}
			if ( 'release_type' === $tax && ! empty( $terms ) ) {
				$term_objs = wp_get_post_terms( $post_id, $tax );
				if ( ! is_wp_error( $term_objs ) ) {
					foreach ( $term_objs as $term_obj ) {
						$release_type_icons[ $term_obj->name ] = ReleaseModel::get_release_type_icon( (int) $term_obj->term_id, $term_obj->name );
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
			'schema_version'     => '2.1.0',
			'post'               => array(
				'post_title'    => (string) $post->post_title,
				'post_content'  => (string) $post->post_content,
				'post_excerpt'  => (string) $post->post_excerpt,
				'post_status'   => (string) $post->post_status,
				'post_name'     => (string) $post->post_name,
				'post_date'     => (string) $post->post_date,
				'post_date_gmt' => (string) $post->post_date_gmt,
			),
			'post_id'            => $post_id,
			'post_type'          => $post_type,
			'targets'            => $targets,
			'uuid'               => (string) $uuid,
			'hub_release_key'    => $master_hub_id . ':' . $post_id,
			'previous_upcs'      => $previous_upcs,
			'meta'               => $meta,
			'excluded_meta_keys' => $excluded_keys,
			'terms_data'         => $terms_data,
			'media_icons'        => $media_icon_cache,
			'release_type_icons' => $release_type_icons,
			'thumbnail_id'       => $thumbnail_id,
			'hub_img_url'        => $hub_img_url,
			'media_info'         => $media_info,
		);
	}
}

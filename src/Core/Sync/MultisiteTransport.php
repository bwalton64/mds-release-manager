<?php
/**
 * WordPress Multisite Hub-to-Spoke synchronization transport.
 *
 * Delivers JSON-serializable catalog payloads and lifecycle tombstones to local
 * Multisite spoke blogs using single-switch batch execution, batch SQL shadow
 * lookup, deferred term counting, spoke health checks, and protected spoke meta.
 *
 * @package MDS\ReleaseManager
 * @since   2.1.0
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Core\Sync;

use MDS\ReleaseManager\Api\VirtualMedia;
use MDS\ReleaseManager\Core\Cache;
use MDS\ReleaseManager\Core\ReleaseModel;
use MDS\ReleaseManager\Core\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Built-in Multisite transport for Hub-and-Spoke synchronization.
 *
 * @since 2.1.0
 */
final class MultisiteTransport implements SyncTransportInterface {

	/**
	 * Returns the transport identifier.
	 *
	 * @since 2.1.0
	 *
	 * @return string Transport slug.
	 */
	public function id(): string {
		return 'multisite';
	}

	/**
	 * Checks whether the target descriptor is a valid Multisite blog target.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed> $target Target descriptor.
	 * @return bool True for multisite targets with a positive blog ID.
	 */
	public function supports( array $target ): bool {
		return 'multisite' === ( $target['type'] ?? '' ) && ! empty( $target['blog_id'] ) && (int) $target['blog_id'] > 0;
	}

	/**
	 * Pushes a batch of payloads to a single Multisite spoke blog.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed>             $target  Target descriptor (`blog_id`).
	 * @param array<int, array<string, mixed>> $entries Batch entries (`key`, `payload`).
	 * @return array<string, string|null> Per-record error message or null on success.
	 */
	public function push_batch( array $target, array $entries ): array {
		$spoke_id = (int) ( $target['blog_id'] ?? 0 );
		$results  = array();

		$health_error = self::validate_spoke_site( $spoke_id );
		if ( null !== $health_error ) {
			foreach ( $entries as $entry ) {
				$results[ (string) $entry['key'] ] = $health_error;
			}
			return $results;
		}

		switch_to_blog( $spoke_id );
		$deferred = function_exists( 'wp_defer_term_counting' );
		if ( $deferred ) {
			wp_defer_term_counting( true );
		}

		try {
			$shadow_maps = self::prefetch_shadow_maps( $entries );
			foreach ( $entries as $entry ) {
				$key             = (string) $entry['key'];
				$results[ $key ] = self::apply_payload_to_current_spoke( $entry['payload'], $spoke_id, $shadow_maps );
			}
			if ( class_exists( Cache::class ) ) {
				Cache::queue_blog( $spoke_id );
			}
		} finally {
			if ( $deferred ) {
				wp_defer_term_counting( false );
			}
			restore_current_blog();
		}

		return $results;
	}

	/**
	 * Applies lifecycle tombstones (`trash`, `untrash`, `delete`) to a single Multisite spoke blog.
	 *
	 * Linked WooCommerce products on the spoke are never modified or deleted.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed>             $target     Target descriptor (`blog_id`).
	 * @param array<int, array<string, mixed>> $tombstones Tombstone descriptors.
	 * @return array<string, string|null> Per-record error message or null on success.
	 */
	public function delete_batch( array $target, array $tombstones ): array {
		$spoke_id = (int) ( $target['blog_id'] ?? 0 );
		$results  = array();

		$health_error = self::validate_spoke_site( $spoke_id );
		if ( null !== $health_error ) {
			foreach ( $tombstones as $tombstone ) {
				$results[ (string) $tombstone['key'] ] = $health_error;
			}
			return $results;
		}

		switch_to_blog( $spoke_id );
		try {
			foreach ( $tombstones as $tombstone ) {
				$key             = (string) $tombstone['key'];
				$results[ $key ] = self::apply_tombstone_to_current_spoke( $tombstone );
			}
			if ( class_exists( Cache::class ) ) {
				Cache::queue_blog( $spoke_id );
			}
		} finally {
			restore_current_blog();
		}

		return $results;
	}

	/**
	 * Validates that a Multisite spoke blog exists and is active (not deleted, archived, or spam).
	 *
	 * @param int $spoke_id Spoke blog ID.
	 * @return string|null Error message if unhealthy, or null when active.
	 */
	private static function validate_spoke_site( int $spoke_id ): ?string {
		if ( $spoke_id <= 0 ) {
			return __( 'Invalid spoke site ID.', 'mds-release-manager' );
		}

		if ( function_exists( 'get_site' ) ) {
			$site = get_site( $spoke_id );
			if ( ! $site ) {
				/* translators: %d: Spoke site ID. */
				return sprintf( __( 'Spoke site %d does not exist.', 'mds-release-manager' ), $spoke_id );
			}
			if ( ! empty( $site->deleted ) || ! empty( $site->archived ) || ! empty( $site->spam ) ) {
				/* translators: %d: Spoke site ID. */
				return sprintf( __( 'Spoke site %d is inactive (archived, deleted, or spam).', 'mds-release-manager' ), $spoke_id );
			}
		}

		return null;
	}

	/**
	 * Batch-resolves existing shadow post IDs on the currently switched spoke in bounded SQL queries.
	 *
	 * @param array<int, array<string, mixed>> $entries Batch entries.
	 * @return array{
	 *     artist_by_uuid: array<string, int[]>,
	 *     release_by_hub_key: array<string, int[]>,
	 *     release_by_upc: array<string, int[]>
	 * } Lookup maps keyed by identity string.
	 */
	private static function prefetch_shadow_maps( array $entries ): array {
		global $wpdb;

		$maps = array(
			'artist_by_uuid'     => array(),
			'release_by_hub_key' => array(),
			'release_by_upc'     => array(),
		);

		if ( ! ( $wpdb instanceof \wpdb ) || empty( $entries ) ) {
			return $maps;
		}

		$artist_uuids = array();
		$hub_keys     = array();
		$upcs         = array();

		foreach ( $entries as $entry ) {
			$payload   = $entry['payload'];
			$post_type = (string) ( $payload['post_type'] ?? '' );
			if ( 'artist' === $post_type ) {
				$uuid = (string) ( $payload['uuid'] ?? '' );
				if ( '' !== $uuid ) {
					$artist_uuids[] = $uuid;
				}
			} elseif ( 'release' === $post_type ) {
				$hub_key = (string) ( $payload['hub_release_key'] ?? '' );
				if ( '' !== $hub_key ) {
					$hub_keys[] = $hub_key;
				}
				$uuid = (string) ( $payload['uuid'] ?? '' );
				if ( '' !== $uuid ) {
					$upcs[] = $uuid;
				}
				foreach ( (array) ( $payload['previous_upcs'] ?? array() ) as $prev ) {
					if ( is_string( $prev ) && '' !== $prev ) {
						$upcs[] = $prev;
					}
				}
			}
		}

		$artist_uuids = array_values( array_unique( $artist_uuids ) );
		$hub_keys     = array_values( array_unique( $hub_keys ) );
		$upcs         = array_values( array_unique( $upcs ) );

		if ( $artist_uuids ) {
			$placeholders = implode( ',', array_fill( 0, count( $artist_uuids ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID, pm.meta_value FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON (pm.post_id = p.ID AND pm.meta_key = '_mds_artist_uuid') WHERE p.post_type = 'artist' AND pm.meta_value IN ({$placeholders}) ORDER BY p.post_date DESC, p.ID DESC", ...$artist_uuids ), ARRAY_A );
			foreach ( (array) $rows as $row ) {
				$maps['artist_by_uuid'][ (string) $row['meta_value'] ][] = (int) $row['ID'];
			}
		}

		if ( $hub_keys ) {
			$placeholders = implode( ',', array_fill( 0, count( $hub_keys ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID, pm.meta_value FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON (pm.post_id = p.ID AND pm.meta_key = '_mds_hub_release_key') WHERE p.post_type = 'release' AND pm.meta_value IN ({$placeholders})", ...$hub_keys ), ARRAY_A );
			foreach ( (array) $rows as $row ) {
				$maps['release_by_hub_key'][ (string) $row['meta_value'] ][] = (int) $row['ID'];
			}
		}

		if ( $upcs ) {
			$placeholders = implode( ',', array_fill( 0, count( $upcs ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT p.ID, pm.meta_value FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON (pm.post_id = p.ID AND pm.meta_key IN ('_mds_normalized_upc', 'upc_ean')) WHERE p.post_type = 'release' AND pm.meta_value IN ({$placeholders})", ...$upcs ), ARRAY_A );
			foreach ( (array) $rows as $row ) {
				$val = (string) $row['meta_value'];
				$id  = (int) $row['ID'];
				if ( ! in_array( $id, $maps['release_by_upc'][ $val ] ?? array(), true ) ) {
					$maps['release_by_upc'][ $val ][] = $id;
				}
			}
		}

		return $maps;
	}

	/**
	 * Applies a collected Hub payload to the currently switched spoke site.
	 *
	 * @param array<string, mixed> $payload     JSON-serializable Hub payload.
	 * @param int                  $spoke_id    Current spoke blog ID.
	 * @param array<string, mixed> $shadow_maps Prefetched shadow lookup maps.
	 * @return string|null Error message on failure, or null on success.
	 */
	private static function apply_payload_to_current_spoke( array $payload, int $spoke_id, array $shadow_maps ): ?string {
		$post_data_src   = is_array( $payload['post'] ?? null ) ? $payload['post'] : array();
		$post_id         = (int) ( $payload['post_id'] ?? 0 );
		$post_type       = (string) ( $payload['post_type'] ?? '' );
		$uuid            = (string) ( $payload['uuid'] ?? '' );
		$hub_release_key = (string) ( $payload['hub_release_key'] ?? '' );
		$previous_upcs   = (array) ( $payload['previous_upcs'] ?? array() );
		$meta            = (array) ( $payload['meta'] ?? array() );
		$terms_data      = (array) ( $payload['terms_data'] ?? array() );
		$thumbnail_id    = (int) ( $payload['thumbnail_id'] ?? 0 );
		$hub_img_url     = (string) ( $payload['hub_img_url'] ?? '' );
		$media_info      = (array) ( $payload['media_info'] ?? array() );
		$excluded_keys   = (array) ( $payload['excluded_meta_keys'] ?? array() );

		$shadow_post_id = 0;
		$sync_conflict  = false;
		$has_stable_key = false;

		if ( 'artist' === $post_type ) {
			$matched = $shadow_maps['artist_by_uuid'][ $uuid ] ?? array();
			if ( count( $matched ) > 1 ) {
				$sync_conflict = true;
			} elseif ( $matched ) {
				$shadow_post_id = (int) $matched[0];
			}
		} else {
			$stable_matched = $shadow_maps['release_by_hub_key'][ $hub_release_key ] ?? array();
			if ( count( $stable_matched ) > 1 ) {
				$sync_conflict = true;
			} elseif ( $stable_matched ) {
				$shadow_post_id = (int) $stable_matched[0];
				$has_stable_key = true;
			}

			if ( ! $sync_conflict && ! $has_stable_key ) {
				$upc_identifiers = array_values( array_unique( array_filter( array_merge( array( $uuid ), array_filter( $previous_upcs, 'is_string' ) ) ) ) );
				$candidates      = array();
				foreach ( $upc_identifiers as $upc_id ) {
					foreach ( $shadow_maps['release_by_upc'][ $upc_id ] ?? array() as $cid ) {
						$candidates[ $cid ] = $cid;
					}
				}
				$candidates = array_values( $candidates );
				if ( count( $candidates ) > 1 ) {
					$sync_conflict = true;
				} elseif ( $candidates ) {
					$candidate  = (int) $candidates[0];
					$linked_key = get_post_meta( $candidate, '_mds_hub_release_key', true );
					if ( $linked_key && $linked_key !== $hub_release_key ) {
						$sync_conflict = true;
					} else {
						$shadow_post_id = $candidate;
					}
				}
			}

			if ( $has_stable_key && ! $sync_conflict ) {
				foreach ( $shadow_maps['release_by_upc'][ $uuid ] ?? array() as $cid ) {
					if ( (int) $cid !== $shadow_post_id ) {
						$sync_conflict = true;
						break;
					}
				}
			}
		}

		if ( $sync_conflict ) {
			/* translators: %d: Spoke site ID. */
			return sprintf( __( 'Conflicting shadow records were found on site %d.', 'mds-release-manager' ), $spoke_id );
		}

		$post_data = array(
			'post_title'    => (string) ( $post_data_src['post_title'] ?? '' ),
			'post_content'  => (string) ( $post_data_src['post_content'] ?? '' ),
			'post_excerpt'  => (string) ( $post_data_src['post_excerpt'] ?? '' ),
			'post_status'   => (string) ( $post_data_src['post_status'] ?? 'publish' ),
			'post_type'     => $post_type,
			'post_name'     => (string) ( $post_data_src['post_name'] ?? '' ),
			'post_date'     => (string) ( $post_data_src['post_date'] ?? '' ),
			'post_date_gmt' => (string) ( $post_data_src['post_date_gmt'] ?? '' ),
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

		// Diff-based meta sync preserving spoke-protected and extension-excluded keys.
		self::sync_shadow_meta( $shadow_post_id, $meta, $post_type, $excluded_keys );

		if ( 'release' === $post_type && get_post_meta( $shadow_post_id, '_mds_hub_release_key', true ) !== $hub_release_key ) {
			update_post_meta( $shadow_post_id, '_mds_hub_release_key', $hub_release_key );
		}

		// Sync thumbnail via virtual attachment only when changed.
		$virt_id       = $thumbnail_id && '' !== $hub_img_url
			? (int) VirtualMedia::get_or_create_virtual_attachment( $hub_img_url, $thumbnail_id, $media_info )
			: 0;
		$current_thumb = (int) get_post_thumbnail_id( $shadow_post_id );
		if ( $virt_id > 0 ) {
			if ( $current_thumb !== $virt_id ) {
				set_post_thumbnail( $shadow_post_id, $virt_id );
			}
		} elseif ( $current_thumb > 0 ) {
			delete_post_thumbnail( $shadow_post_id );
		}

		// Sync taxonomies (checking taxonomy_exists first) and term icons.
		$errors             = array();
		$media_icons        = is_array( $payload['media_icons'] ?? null ) ? $payload['media_icons'] : array();
		$release_type_icons = is_array( $payload['release_type_icons'] ?? null ) ? $payload['release_type_icons'] : array();

		foreach ( $terms_data as $tax => $names ) {
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}
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
						$tid = Sync::ensure_term_exists( $name, $tax );
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
					if ( $spoke_term && ! is_wp_error( $spoke_term ) && get_term_meta( (int) $spoke_term->term_id, ReleaseModel::MEDIA_FORMAT_ICON_META_KEY, true ) !== $icon_val ) {
						update_term_meta( (int) $spoke_term->term_id, ReleaseModel::MEDIA_FORMAT_ICON_META_KEY, $icon_val );
					}
				}
			}

			if ( 'release_type' === $tax && $release_type_icons ) {
				foreach ( $release_type_icons as $term_name => $icon_val ) {
					$spoke_term = get_term_by( 'name', $term_name, 'release_type' );
					if ( $spoke_term && ! is_wp_error( $spoke_term ) && get_term_meta( (int) $spoke_term->term_id, ReleaseModel::RELEASE_TYPE_ICON_META_KEY, true ) !== $icon_val ) {
						update_term_meta( (int) $spoke_term->term_id, ReleaseModel::RELEASE_TYPE_ICON_META_KEY, $icon_val );
					}
				}
			}
		}

		/**
		 * Fires after a shadow Release or Artist record's post, meta, thumbnail, and taxonomies
		 * have all been synchronized to a Spoke site.
		 *
		 * @since 2.0.0
		 *
		 * @param int                         $shadow_post_id Spoke shadow post ID.
		 * @param int                         $spoke_id       Spoke blog ID.
		 * @param int                         $post_id        Hub source post ID.
		 * @param string                      $post_type      Post type (`release` or `artist`).
		 * @param array<string, array<mixed>> $meta           Synchronized Hub meta array.
		 */
		do_action( 'mds_rm_after_shadow_sync', $shadow_post_id, $spoke_id, $post_id, $post_type, $meta );

		return $errors ? implode( ' ', $errors ) : null;
	}

	/**
	 * Applies a single lifecycle tombstone (`trash`, `untrash`, `delete`) on the current spoke.
	 *
	 * @param array<string, mixed> $tombstone Tombstone descriptor.
	 * @return string|null Error message or null on success.
	 */
	private static function apply_tombstone_to_current_spoke( array $tombstone ): ?string {
		$action          = (string) ( $tombstone['action'] ?? '' );
		$post_type       = (string) ( $tombstone['post_type'] ?? '' );
		$uuid            = (string) ( $tombstone['uuid'] ?? '' );
		$hub_release_key = (string) ( $tombstone['hub_release_key'] ?? '' );

		$shadow_id = self::find_single_shadow_id( $post_type, $hub_release_key, $uuid );
		if ( $shadow_id <= 0 ) {
			return null;
		}

		if ( 'trash' === $action ) {
			wp_trash_post( $shadow_id );
		} elseif ( 'untrash' === $action ) {
			wp_untrash_post( $shadow_id );
		} elseif ( 'delete' === $action ) {
			wp_delete_post( $shadow_id, true );
		}

		return null;
	}

	/**
	 * Resolves a single shadow post ID on the currently switched spoke for lifecycle operations.
	 *
	 * @param string $post_type       Post type (`artist` or `release`).
	 * @param string $hub_release_key Stable `{hub_id}:{post_id}` key.
	 * @param string $uuid            Artist UUID or Release UPC.
	 * @return int Shadow post ID, or 0 if not found.
	 */
	private static function find_single_shadow_id( string $post_type, string $hub_release_key, string $uuid ): int {
		$meta_key   = 'artist' === $post_type ? '_mds_artist_uuid' : '_mds_hub_release_key';
		$meta_value = 'artist' === $post_type ? $uuid : $hub_release_key;

		if ( '' === $meta_value ) {
			return 0;
		}

		$found = get_posts(
			array(
				'post_type'              => $post_type,
				'post_status'            => array_keys( get_post_stati() ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'meta_key'               => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Locate shadow for lifecycle mirroring.
				'meta_value'             => $meta_value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return ! empty( $found[0] ) ? (int) $found[0] : 0;
	}

	/**
	 * Synchronizes post meta onto a shadow post using a diff while preserving protected spoke keys.
	 *
	 * @param int                         $shadow_post_id Spoke shadow post ID.
	 * @param array<string, array<mixed>> $hub_meta       Filtered hub post meta array (`key => list of raw meta strings`).
	 * @param string                      $post_type      Post type (`release` or `artist`).
	 * @param string[]                    $excluded_keys  Excluded hub meta keys.
	 * @return void
	 */
	private static function sync_shadow_meta( int $shadow_post_id, array $hub_meta, string $post_type, array $excluded_keys ): void {
		$existing_meta = get_post_meta( $shadow_post_id );
		if ( ! is_array( $existing_meta ) ) {
			$existing_meta = array();
		}

		/**
		 * Filters spoke-local post meta keys that must never be deleted during Hub-to-Spoke sync.
		 *
		 * Extensions (such as WooCommerce's `_mds_local_product_id`) register spoke-owned keys here.
		 *
		 * @since 2.1.0
		 *
		 * @param string[] $protected_keys Meta keys preserved on the Spoke.
		 * @param string   $post_type      Post type (`release` or `artist`).
		 */
		$protected = apply_filters( 'mds_rm_spoke_protected_meta_keys', $excluded_keys, $post_type );
		$protected = is_array( $protected )
			? array_values( array_unique( array_merge( $excluded_keys, array_filter( $protected, 'is_string' ) ) ) )
			: $excluded_keys;

		foreach ( array_keys( $existing_meta ) as $existing_key ) {
			if ( in_array( $existing_key, $protected, true ) ) {
				continue;
			}
			if ( ! array_key_exists( $existing_key, $hub_meta ) ) {
				delete_post_meta( $shadow_post_id, $existing_key );
			}
		}

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

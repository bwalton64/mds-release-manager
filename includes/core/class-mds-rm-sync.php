<?php
declare(strict_types=1);

/**
 * Synchronization helpers for the MDS Release Manager plugin.
 *
 * @package MDS_Release_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Synchronizes release and artist data across the network.
 */
final class MDS_RM_Sync {

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

		$master_hub_id   = (int) get_site_option( 'mds_rm_master_blog_id', get_main_site_id() );
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
			$identifiers    = 'artist' === $post_type
				? array( array( '_mds_artist_uuid', $uuid ) )
				: array_merge(
					array( array( '_mds_hub_release_key', $hub_release_key ), array( 'upc_ean', $uuid ) ),
					array_map(
						static function ( $upc ) {
							return array( 'upc_ean', $upc );
						},
						$previous_upcs
					)
				);
			$sync_conflict  = false;
			foreach ( $identifiers as $identifier ) {
				$shadow_posts = get_posts(
					array(
						'post_type'              => $post_type,
						'post_status'            => array_keys( get_post_stati() ),
						'posts_per_page'         => 2,
						'fields'                 => 'ids',
						'meta_key'               => $identifier[0],
						'meta_value'             => $identifier[1],
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
					break;
				}
				if ( $shadow_posts ) {
					$candidate  = (int) $shadow_posts[0];
					$linked_key = get_post_meta( $candidate, '_mds_hub_release_key', true );
					if ( 'release' === $post_type && $linked_key && $linked_key !== $hub_release_key ) {
						$sync_conflict = true;
						break;
					}
					if ( $shadow_post_id && $shadow_post_id !== $candidate ) {
						$sync_conflict = true;
						break;
					}
					$shadow_post_id = $candidate;
					if ( '_mds_hub_release_key' === $identifier[0] || 'artist' === $post_type ) {
						break;
					}
				}
			}
			if ( 'release' === $post_type && ! $sync_conflict ) {
				$conflicts     = get_posts(
					array(
						'post_type'      => 'release',
						'post_status'    => array_keys( get_post_stati() ),
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'post__not_in'   => $shadow_post_id ? array( $shadow_post_id ) : array(),
						'meta_key'       => 'upc_ean',
						'meta_value'     => $uuid,
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

<?php
declare(strict_types=1);

/**
 * Shared core functions for the MDS Release Manager plugin.
 *
 * @package MDS_Release_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// --- 1. Native Logging & Cache Purge ---.
/**
 * Provides shared logging, rendering, asset, and synchronization helpers.
 *
 * @since 0.9.0
 */
final class MDS_RM_Core_Functions {

	/**
	 * Writes a message to the error log when WordPress debugging is enabled.
	 *
	 * @since 0.9.0
	 * @param mixed $message The value to log.
	 */
	public static function mds_rm_log( $message ) {
		if (
			! defined( 'WP_DEBUG' )
			|| true !== WP_DEBUG
			|| ! defined( 'WP_DEBUG_LOG' )
			|| true !== WP_DEBUG_LOG
		) {
			return;
		}

		$prefix  = '[MDS Release Manager] ';
		$message = is_array( $message ) || is_object( $message ) ? wp_json_encode( $message ) : $message;
		if ( false === $message ) {
			$message = '[Unable to encode log message]';
		}
		do_action( 'mds_rm_log_message', $prefix . $message );
	}

	/**
	 * Purges the LiteSpeed cache when its purge hook is available.
	 *
	 * @since 0.9.0
	 */
	public static function mds_rm_purge_cache() {
		if ( defined( 'LSCWP_V' ) || has_action( 'litespeed_purge_all' ) ) {
			do_action( 'litespeed_purge_all' );
		}
	}

	// --- 2. Database-Backed Editable Assets ---.
	/**
	 * Retrieves editable asset contents stored in the database.
	 *
	 * @since 0.9.0
	 * @return array The saved asset contents.
	 */
	public static function mds_rm_get_db_templates() {
		$templates = is_multisite()
		? get_site_option( 'mds_rm_db_templates', array() )
		: get_option( 'mds_rm_db_templates', array() );
		return is_array( $templates ) ? $templates : array();
	}

	/**
	 * Load a PHP template and return its rendered output.
	 *
	 * @since 0.9.0
	 * @param string $template_name Template path relative to the plugin directory.
	 * @param array  $args          Variables passed to the template.
	 * @return string Rendered template.
	 */
	public static function mds_rm_render_template( $template_name, $args = array() ) {
		if (
			! is_string( $template_name )
			|| 'templates' !== dirname( $template_name )
			|| 'php' !== strtolower( pathinfo( $template_name, PATHINFO_EXTENSION ) )
		) {
			wp_die( esc_html__( 'The requested Release Manager template is invalid.', 'mds-release-manager' ) );
			return '';
		}

		if ( ! is_array( $args ) ) {
			wp_die( esc_html__( 'The requested Release Manager template arguments are invalid.', 'mds-release-manager' ) );
			return '';
		}

		$theme_template = locate_template( 'mds-release-manager/' . $template_name );
		$template_path  = $theme_template ? $theme_template : MDS_RM_PATH . $template_name;

		if ( ! is_readable( $template_path ) ) {
			wp_die( esc_html__( 'The requested Release Manager template could not be found.', 'mds-release-manager' ) );
			return '';
		}

		ob_start();
		load_template( $template_path, false, $args );

		return (string) ob_get_clean();
	}

	/**
	 * Sanitize shortcode output while preserving supported audio embeds.
	 *
	 * @since 0.9.0
	 * @param string $html Shortcode output.
	 * @return string Sanitized HTML.
	 */
	public static function mds_rm_kses_shortcode_html( $html ) {
		$allowed_html           = wp_kses_allowed_html( 'post' );
		$allowed_html['iframe'] = array(
			'allow'             => true,
			'allowfullscreen'   => true,
			'allowtransparency' => true,
			'class'             => true,
			'frameborder'       => true,
			'height'            => true,
			'loading'           => true,
			'name'              => true,
			'referrerpolicy'    => true,
			'scrolling'         => true,
			'seamless'          => true,
			'src'               => true,
			'style'             => true,
			'title'             => true,
			'width'             => true,
		);

		return wp_kses( $html, $allowed_html );
	}

	/**
	 * Enqueues a stylesheet from its saved contents or plugin file.
	 *
	 * @since 0.9.0
	 * @param string $handle   The registered stylesheet handle.
	 * @param string $rel_path The stylesheet path relative to the plugin directory.
	 * @param array  $deps     The stylesheet dependencies.
	 */
	public static function mds_rm_enqueue_editable_style( $handle, $rel_path, $deps = array() ) {
		$db_templates = self::mds_rm_get_db_templates();
		if ( isset( $db_templates[ $rel_path ] ) && trim( $db_templates[ $rel_path ] ) !== '' ) {
			wp_register_style( $handle, false, $deps, MDS_RELEASE_MANAGER_VERSION );
			wp_enqueue_style( $handle );
			wp_add_inline_style( $handle, $db_templates[ $rel_path ] );
		} else {
			$asset_path = MDS_RM_PATH . $rel_path;
			wp_register_style( $handle, MDS_RM_URL . $rel_path, $deps, filemtime( $asset_path ) );
			wp_enqueue_style( $handle );
		}
	}

	/**
	 * Enqueues a script from its saved contents or plugin file.
	 *
	 * @since 0.9.0
	 * @param string $handle   The registered script handle.
	 * @param string $rel_path The script path relative to the plugin directory.
	 * @param array  $deps     The script dependencies.
	 * @param bool   $in_footer Whether to enqueue the script in the footer.
	 */
	public static function mds_rm_enqueue_editable_script( $handle, $rel_path, $deps = array(), $in_footer = true ) {
		$db_templates = self::mds_rm_get_db_templates();
		if ( isset( $db_templates[ $rel_path ] ) && trim( $db_templates[ $rel_path ] ) !== '' ) {
			wp_register_script( $handle, false, $deps, MDS_RELEASE_MANAGER_VERSION, $in_footer );
			wp_enqueue_script( $handle );
			wp_add_inline_script( $handle, $db_templates[ $rel_path ] );
		} else {
			$asset_path = MDS_RM_PATH . $rel_path;
			wp_register_script( $handle, MDS_RM_URL . $rel_path, $deps, filemtime( $asset_path ), $in_footer );
			wp_enqueue_script( $handle );
		}
	}

	// --- Hub & Spoke Synchronization Engine ---

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
	public static function mds_rm_ensure_term_exists( $term_name, $taxonomy ) {
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
	public static function mds_rm_broadcast_to_spokes( $post_id, $post_type ) {
		if ( ! is_multisite() ) {
			return;
		}

		// Prevent infinite loops if saving happens inside a switched context.
		static $is_broadcasting = false;
		if ( $is_broadcasting ) {
			return;
		}

		$master_hub_id   = (int) get_site_option( 'mds_rm_master_blog_id', get_main_site_id() );
		$current_blog_id = get_current_blog_id();

		// Only broadcast if we are saving on the Master Hub.
		if ( $current_blog_id !== $master_hub_id ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post_type, array( 'release', 'artist' ), true ) ) {
			return;
		}

		$spokes = array_map( 'intval', (array) apply_filters( 'mds_rm_sync_target_sites', array(), $post_id, $post_type ) );
		if ( empty( $spokes ) ) {
			return;
		}

		$uuid = get_post_meta( $post_id, 'artist' === $post_type ? '_mds_artist_uuid' : 'upc_ean', true );
		if ( '' === (string) $uuid ) {
			return new WP_Error( 'mds_sync_missing_identity', __( 'Cannot synchronize a record without its identifier.', 'mds-release-manager' ) );
		}
		if ( 'release' === $post_type && ( is_wp_error( MDS_RM_Release_Model::format_name( $post_id ) ) || MDS_RM_Release_Model::has_legacy_format_conflict( $post_id ) ) ) {
			return new WP_Error( 'mds_sync_invalid_format', __( 'Cannot synchronize a release with an invalid or conflicting format.', 'mds-release-manager' ) );
		}

		$meta            = get_post_meta( $post_id );
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

		$thumbnail_id    = get_post_thumbnail_id( $post_id );
		$hub_img_url     = $thumbnail_id ? wp_get_attachment_url( $thumbnail_id ) : '';

		$is_broadcasting = true;
		$errors          = array();

		foreach ( $spokes as $spoke_id ) {
			if ( $spoke_id === $master_hub_id ) {
				continue;
			}

			switch_to_blog( $spoke_id );

			// Prefer a stable hub identity; legacy shadows are adopted by their current or former UPC.
			$shadow_post_id = 0;
			$identifiers = 'artist' === $post_type
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
			$sync_conflict = false;
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
					$candidate = (int) $shadow_posts[0];
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
				$conflicts = get_posts(
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
				$shadow_post_id = wp_update_post( $post_data, true );
			} else {
				$shadow_post_id = wp_insert_post( $post_data, true );
			}

			if ( ! $shadow_post_id || is_wp_error( $shadow_post_id ) ) {
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
							$tid = self::mds_rm_ensure_term_exists( $name, $tax );
							if ( $tid ) {
								$term_ids[] = $tid;
							} else {
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

	/**
	 * Retrieves configured providers and fills in missing brand colors.
	 *
	 * @since 0.9.0
	 * @return array The configured provider data.
	 */
	public static function mds_rm_get_default_providers() {
		$providers = get_option( 'mds_rm_providers', false );

			// If empty or not set, provide the standard brand defaults.
		if ( empty( $providers ) || ! is_array( $providers ) ) {
			$providers = array(
				array(
					'name'  => 'Spotify',
					'icon'  => 'fab fa-spotify',
					'color' => '#1DB954',
				),
				array(
					'name'  => 'Apple Music',
					'icon'  => 'fab fa-apple',
					'color' => '#FA243C',
				),
				array(
					'name'  => 'Amazon Music',
					'icon'  => 'fab fa-amazon',
					'color' => '#00A8E1',
				),
				array(
					'name'  => 'YouTube Music',
					'icon'  => 'fab fa-youtube',
					'color' => '#FF0000',
				),
				array(
					'name'  => 'Soundcloud',
					'icon'  => 'fab fa-soundcloud',
					'color' => '#FF5500',
				),
			);
			// Save it so it populates the settings page.
			update_option( 'mds_rm_providers', $providers );
		} else {
			// Upgrade routine: If a user has the providers but their colors got wiped to blank or #000000, restore the brand color.
			$brand_colors = array(
				'Spotify'       => '#1DB954',
				'Apple Music'   => '#FA243C',
				'Amazon Music'  => '#00A8E1',
				'YouTube Music' => '#FF0000',
				'Soundcloud'    => '#FF5500',
			);
			$updated      = false;
			foreach ( $providers as &$p ) {
				if ( isset( $brand_colors[ $p['name'] ] ) && ( empty( $p['color'] ) || '#000000' === $p['color'] ) ) {
					$p['color'] = $brand_colors[ $p['name'] ];
					$updated    = true;
				}
			}
			if ( $updated ) {
				update_option( 'mds_rm_providers', $providers );
			}
		}

		return $providers;
	}
}

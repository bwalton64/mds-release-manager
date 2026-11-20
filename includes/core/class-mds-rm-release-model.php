<?php
/**
 * Release identity and format validation.
 *
 * @package MDS_Release_Manager
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates release identity and keeps the supported taxonomy terms available.
 */
final class MDS_RM_Release_Model {
	public const MDS_RM_FORMATS = array( 'Single', 'EP', 'Album', 'Digital' );

	/**
	 * UPC values captured before REST updates, keyed by release ID.
	 *
	 * @var array<int, mixed>
	 */
	private static $previous_upcs = array();

	/**
	 * UPC locks held by this request, keyed by lock name.
	 *
	 * @var array<string, bool>
	 */
	private static $upc_locks = array();

	/**
	 * Sync errors to return to the REST client, keyed by request object ID.
	 *
	 * @var array<int, WP_Error>
	 */
	private static $rest_sync_errors = array();

	/**
	 * Active REST methods, keyed by request object ID.
	 *
	 * @var array<int, string>
	 */
	private static $rest_requests = array();

	/** Registers the release validation hooks. */
	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'register_fields_and_formats' ), 20 );
		add_filter( 'wp_insert_post_empty_content', array( self::class, 'validate_classic_save' ), 10, 2 );
		add_filter( 'rest_pre_insert_release', array( self::class, 'validate_rest_save' ), 10, 2 );
		add_action( 'rest_after_insert_release', array( self::class, 'after_rest_save' ), 10, 3 );
		add_filter( 'rest_request_before_callbacks', array( self::class, 'before_rest_request' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( self::class, 'report_rest_sync_error' ), 10, 3 );
		add_action( 'admin_notices', array( self::class, 'show_audit_notice' ) );
		add_filter( 'pre_insert_term', array( self::class, 'validate_format_term' ), 10, 3 );
		add_filter( 'map_meta_cap', array( self::class, 'protect_format_terms' ), 10, 4 );
	}

	/** Registers REST metadata and the supported format terms. */
	public static function register_fields_and_formats(): void {
		register_post_meta(
			'release',
			'upc_ean',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( self::class, 'normalize_upc' ),
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);

		foreach ( self::MDS_RM_FORMATS as $format ) {
			$term = term_exists( $format, 'release_format' );
			if ( ! $term ) {
				$term = wp_insert_term( $format, 'release_format' );
			}
			if ( is_wp_error( $term ) && 'term_exists' === $term->get_error_code() ) {
				$existing = term_exists( $format, 'release_format' );
				if ( $existing && ! is_wp_error( $existing ) ) {
					$term = $existing;
				}
			}
			if ( is_wp_error( $term ) ) {
				error_log( '[MDS Release Manager] Could not register release format: ' . $term->get_error_message() );
				add_action(
					'admin_notices',
					static function () use ( $format ) {
						/* translators: %s: Release format name. */
						echo '<div class="notice notice-error"><p>' . esc_html( sprintf( __( 'Could not register the release format "%s". Check the server error log.', 'mds-release-manager' ), $format ) ) . '</p></div>';
					}
				);
			}
		}
	}

	/**
	 * Normalizes an identifier without losing leading zeroes.
	 *
	 * @param mixed $value Submitted UPC/EAN.
	 * @return string Digits in the identifier.
	 */
	public static function normalize_upc( mixed $value ): string {
		return is_scalar( $value ) ? preg_replace( '/[^0-9]/', '', (string) $value ) : '';
	}

	/**
	 * Rejects unsupported or nested release format terms.
	 *
	 * @param string|WP_Error $term Term name or previous error.
	 * @param string          $taxonomy Taxonomy name.
	 * @param array           $args Term arguments.
	 * @return string|WP_Error Term name or validation error.
	 */
	public static function validate_format_term( $term, $taxonomy, $args ) {
		if ( 'release_format' !== $taxonomy || is_wp_error( $term ) ) {
			return $term;
		}
		if ( ! is_string( $term ) || ! in_array( $term, self::MDS_RM_FORMATS, true ) || ! empty( $args['parent'] ) ) {
			return new WP_Error( 'mds_invalid_format_term', __( 'Release formats must be Single, EP, Album, or Digital without a parent.', 'mds-release-manager' ) );
		}
		return $term;
	}

	/**
	 * Prevents edits or deletion of canonical release formats.
	 *
	 * @param array  $caps Required capabilities.
	 * @param string $cap Requested meta capability.
	 * @param int    $user_id User ID.
	 * @param array  $args Capability arguments.
	 * @return array Required capabilities.
	 */
	public static function protect_format_terms( $caps, $cap, $user_id, $args ) {
		if ( ! in_array( $cap, array( 'edit_term', 'delete_term' ), true ) || empty( $args[0] ) ) {
			return $caps;
		}
		// REST's edit context uses edit_term for reading as well as writing.
		if ( 'edit_term' === $cap && self::$rest_requests && 'GET' === end( self::$rest_requests ) ) {
			return $caps;
		}
		$term = get_term( (int) $args[0], 'release_format' );
		if ( $term && ! is_wp_error( $term ) && 0 === (int) $term->parent && in_array( $term->name, self::MDS_RM_FORMATS, true ) ) {
			return array( 'do_not_allow' );
		}
		return $caps;
	}

	/**
	 * Tracks REST reads while capability checks are performed.
	 *
	 * @param mixed           $response Existing response.
	 * @param array           $handler Route handler.
	 * @param WP_REST_Request $request Current request.
	 * @return mixed Existing response.
	 */
	public static function before_rest_request( mixed $response, $handler, $request ): mixed {
		self::$rest_requests[ spl_object_id( $request ) ] = $request->get_method();
		return $response;
	}

	/**
	 * Returns the authoritative format for a release.
	 *
	 * @param int $post_id Release ID.
	 * @return string|WP_Error Format name or validation error.
	 */
	public static function format_name( $post_id ): string|WP_Error {
		$terms = wp_get_post_terms( $post_id, 'release_format' );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}
		if ( 1 !== count( $terms ) || 0 !== (int) $terms[0]->parent || ! in_array( $terms[0]->name, self::MDS_RM_FORMATS, true ) ) {
			return new WP_Error( 'mds_invalid_format', __( 'The release must have exactly one valid release format.', 'mds-release-manager' ) );
		}
		return $terms[0]->name;
	}

	/**
	 * Checks whether old format metadata conflicts with the taxonomy.
	 *
	 * @param int $post_id Release ID.
	 * @return bool Whether the legacy value needs review.
	 */
	public static function has_legacy_format_conflict( $post_id ): bool {
		$legacy = get_post_meta( $post_id, 'release_format', true );
		if ( '' === $legacy ) {
			return false;
		}
		if ( ! is_string( $legacy ) ) {
			return true;
		}
		$format = self::format_name( $post_id );
		return is_wp_error( $format ) || 0 !== strcasecmp( trim( $legacy ), $format );
	}

	/** Reports existing release data requiring administrator review. */
	public static function show_audit_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'edit' !== $screen->base || 'release' !== $screen->post_type || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$posts  = get_posts(
			array(
				'post_type'      => 'release',
				'post_status'    => array_diff( array_keys( get_post_stati() ), array( 'auto-draft', 'inherit' ) ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$issues = array();
		$upcs   = array();
		foreach ( $posts as $id ) {
			$upc        = get_post_meta( $id, 'upc_ean', true );
			$normalized = self::normalize_upc( $upc );
			if ( '' === $normalized || ! is_string( $upc ) || $upc !== $normalized || is_wp_error( self::format_name( $id ) ) || self::has_legacy_format_conflict( $id ) ) {
				$issues[ $id ] = true;
			}
			if ( '' !== $normalized ) {
				$upcs[ $normalized ][] = $id;
			}
		}
		foreach ( $upcs as $ids ) {
			if ( count( $ids ) > 1 ) {
				foreach ( $ids as $id ) {
					$issues[ $id ] = true;
				}
			}
		}
		if ( ! $issues ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'These releases need review for missing, malformed, or duplicate UPCs, invalid formats, or conflicting legacy format data:', 'mds-release-manager' ) . ' ';
		$links = array();
		foreach ( array_keys( $issues ) as $id ) {
			$url     = get_edit_post_link( $id, 'raw' );
			$label   = esc_html( get_the_title( $id ) . ' (#' . $id . ')' );
			$links[] = $url ? '<a href="' . esc_url( $url ) . '">' . $label . '</a>' : $label;
		}
		echo implode( ', ', $links ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each link is escaped above.
	}

	/**
	 * Resolves a supported format name to its local taxonomy term ID.
	 *
	 * @param string $name Format name.
	 * @return int Term ID, or zero if invalid or unavailable.
	 */
	public static function format_id( $name ): int {
		foreach ( self::MDS_RM_FORMATS as $format ) {
			if ( 0 === strcasecmp( trim( $name ), $format ) ) {
				$term = get_term_by( 'name', $format, 'release_format' );
				return $term && 0 === (int) $term->parent ? (int) $term->term_id : 0;
			}
		}
		return 0;
	}

	/**
	 * Validates and reserves a release identifier and format for saving.
	 *
	 * @param int   $post_id Existing release ID, or zero.
	 * @param mixed $upc Submitted identifier.
	 * @param array $format_ids Selected term IDs.
	 * @return true|WP_Error True when valid, otherwise an error.
	 */
	public static function validate( $post_id, $upc, $format_ids ): bool|WP_Error {
		$upc = self::normalize_upc( $upc );
		if ( '' === $upc ) {
			return new WP_Error( 'mds_upc_required', __( 'A UPC/EAN is required.', 'mds-release-manager' ), array( 'status' => 400 ) );
		}

		if ( ! is_array( $format_ids ) || 1 !== count( $format_ids ) ) {
			return new WP_Error( 'mds_format_required', __( 'Select exactly one release format.', 'mds-release-manager' ), array( 'status' => 400 ) );
		}
		$term = get_term( (int) reset( $format_ids ), 'release_format' );
		if ( ! $term || is_wp_error( $term ) || 0 !== (int) $term->parent || ! in_array( $term->name, self::MDS_RM_FORMATS, true ) ) {
			return new WP_Error( 'mds_invalid_format', __( 'Select Single, EP, Album, or Digital.', 'mds-release-manager' ), array( 'status' => 400 ) );
		}

		$lock = self::lock_upc( $upc );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		$matches = get_posts(
			array(
				'post_type'              => 'release',
				'post_status'            => array_keys( get_post_stati() ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'post__not_in'           => $post_id ? array( (int) $post_id ) : array(),
				'meta_key'               => 'upc_ean',
				'meta_value'             => $upc,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		if ( ! $matches ) {
			$legacy_ids = get_posts(
				array(
					'post_type'              => 'release',
					'post_status'            => array_keys( get_post_stati() ),
					'posts_per_page'         => -1,
					'fields'                 => 'ids',
					'post__not_in'           => $post_id ? array( (int) $post_id ) : array(),
					'meta_query'             => array(
						array(
							'key'     => 'upc_ean',
							'value'   => '[^0-9]',
							'compare' => 'REGEXP',
						),
					),
					'no_found_rows'          => true,
					'update_post_meta_cache' => true,
					'update_post_term_cache' => false,
				)
			);
			foreach ( $legacy_ids as $legacy_id ) {
				foreach ( get_post_meta( $legacy_id, 'upc_ean', false ) as $legacy_upc ) {
					if ( self::normalize_upc( $legacy_upc ) === $upc ) {
						$matches = array( $legacy_id );
						break 2;
					}
				}
			}
		}
		if ( $matches ) {
			self::release_upc_lock( $upc );
			return new WP_Error( 'mds_duplicate_upc', __( 'This UPC/EAN is already assigned to another release.', 'mds-release-manager' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Acquires a site-scoped database lock for a UPC.
	 *
	 * @param string $upc Normalized UPC.
	 * @return true|WP_Error True when locked, otherwise an error.
	 */
	private static function lock_upc( string $upc ): bool|WP_Error {
		global $wpdb;
		$key = 'mds-upc-' . substr( hash( 'sha256', get_current_blog_id() . ':' . $upc ), 0, 48 );
		if ( isset( self::$upc_locks[ $key ] ) ) {
			return true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A connection-scoped advisory lock must execute against MySQL, not the object cache.
		$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $key, 5 ) );
		if ( '1' !== (string) $locked ) {
			return new WP_Error( 'mds_upc_lock_failed', __( 'Could not reserve this UPC/EAN for saving. Please try again.', 'mds-release-manager' ), array( 'status' => 503 ) );
		}
		self::$upc_locks[ $key ] = true;
		register_shutdown_function(
			static function () use ( $key, $wpdb ) {
				if ( isset( self::$upc_locks[ $key ] ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Release the advisory lock on its owning database connection.
					$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
					unset( self::$upc_locks[ $key ] );
				}
			}
		);
		return true;
	}

	/**
	 * Releases a previously acquired UPC lock.
	 *
	 * @param string $upc UPC to unlock.
	 */
	public static function release_upc_lock( $upc ): void {
		global $wpdb;
		$key = 'mds-upc-' . substr( hash( 'sha256', get_current_blog_id() . ':' . self::normalize_upc( $upc ) ), 0, 48 );
		if ( isset( self::$upc_locks[ $key ] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Release the advisory lock on its owning database connection.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
			unset( self::$upc_locks[ $key ] );
		}
	}

	/**
	 * Validates classic editor fields before WordPress saves the post.
	 *
	 * @param bool                 $empty   Whether the post is empty.
	 * @param array<string, mixed> $postarr Post data.
	 * @return bool Existing empty-post result.
	 */
	public static function validate_classic_save( bool $empty, array $postarr ): bool {
		if ( 'release' !== $postarr['post_type'] || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return $empty;
		}
		if ( is_multisite() && get_current_blog_id() !== (int) get_site_option( 'mds_rm_master_blog_id', get_main_site_id() ) ) {
			return $empty;
		}
		$post_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;

		// Verify nonce with proper sanitization.
		$nonce     = isset( $_POST['mds_release_meta_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['mds_release_meta_nonce'] ) ) : '';
		$has_nonce = is_string( $nonce ) && wp_verify_nonce( $nonce, 'mds_save_release_data' );

		if ( ! $has_nonce ) {
			$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
			if ( in_array( $action, array( 'inline-save', 'edit' ), true ) && $post_id ) {
				if ( isset( $postarr['tax_input']['release_format'] ) ) {
					wp_die(
						esc_html__( 'Change the release format in the release editor.', 'mds-release-manager' ),
						'',
						array(
							'response'  => 400,
							'back_link' => true,
						)
					);
				}
				if ( self::has_legacy_format_conflict( $post_id ) ) {
					wp_die(
						esc_html__( 'Resolve the release format conflict in the release editor first.', 'mds-release-manager' ),
						'',
						array(
							'response'  => 400,
							'back_link' => true,
						)
					);
				}
				$upc     = get_post_meta( $post_id, 'upc_ean', true );
				$formats = wp_get_post_terms( $post_id, 'release_format', array( 'fields' => 'ids' ) );
				$result  = is_wp_error( $formats ) ? $formats : self::validate( $post_id, $upc, $formats );
				if ( is_wp_error( $result ) ) {
					wp_die(
						esc_html( $result->get_error_message() ),
						'',
						array(
							'response'  => 400,
							'back_link' => true,
						)
					);
				}
				self::release_upc_lock( $upc );
			}
			return $empty;
		}

		$upc = sanitize_text_field( isset( $_POST['upc_ean'] ) && is_string( $_POST['upc_ean'] ) ? wp_unslash( $_POST['upc_ean'] ) : '' );

		$format = isset( $_POST['release_format'] ) && is_scalar( $_POST['release_format'] )
			? array( absint( wp_unslash( $_POST['release_format'] ) ) )
			: ( $post_id ? wp_get_post_terms( $post_id, 'release_format', array( 'fields' => 'ids' ) ) : array() );

		if ( ! isset( $_POST['release_format'] ) && $post_id && self::has_legacy_format_conflict( $post_id ) ) {
			wp_die(
				esc_html__( 'The saved format conflicts with legacy data. Choose a release format explicitly.', 'mds-release-manager' ),
				'',
				array(
					'response'  => 400,
					'back_link' => true,
				)
			);
		}
		if ( is_wp_error( $format ) ) {
			wp_die( esc_html( $format->get_error_message() ) );
		}
		$taxonomy = get_taxonomy( 'release_format' );
		if ( isset( $_POST['release_format'] ) && ( ! $taxonomy || ! current_user_can( $taxonomy->cap->assign_terms ) ) ) {
			wp_die(
				esc_html__( 'You are not allowed to assign a release format.', 'mds-release-manager' ),
				'',
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}
		$result = self::validate( $post_id, $upc, $format );
		if ( is_wp_error( $result ) ) {
			wp_die(
				esc_html( $result->get_error_message() ),
				esc_html__( 'Invalid release', 'mds-release-manager' ),
				array(
					'response'  => 400,
					'back_link' => true,
				)
			);
		}
		return $empty;
	}

	/**
	 * Validates a release REST write before the post is saved.
	 *
	 * @param stdClass        $prepared Prepared post.
	 * @param WP_REST_Request $request REST request.
	 * @return stdClass|WP_Error Prepared post or validation error.
	 */
	public static function validate_rest_save( $prepared, $request ) {
		$post_id = (int) $request['id'];
		$post    = $post_id ? get_post( $post_id ) : null;
		$status  = isset( $prepared->post_status ) ? $prepared->post_status : ( $post ? $post->post_status : 'draft' );
		if ( 'auto-draft' === $status ) {
			return $prepared;
		}
		$meta    = $request->get_param( 'meta' );
		$upc     = is_array( $meta ) && array_key_exists( 'upc_ean', $meta ) ? $meta['upc_ean'] : ( $post_id ? get_post_meta( $post_id, 'upc_ean', true ) : '' );
		$formats = $request->get_param( 'release_format' );
		if ( null === $formats && $post_id && self::has_legacy_format_conflict( $post_id ) ) {
			return new WP_Error( 'mds_format_conflict', __( 'The saved format conflicts with legacy data. Choose a release format explicitly.', 'mds-release-manager' ), array( 'status' => 400 ) );
		}
		if ( null === $formats ) {
			$formats = $post_id ? wp_get_post_terms( $post_id, 'release_format', array( 'fields' => 'ids' ) ) : array();
		}
		if ( is_wp_error( $formats ) ) {
			return $formats;
		}
		$result = self::validate( $post_id, $upc, $formats );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( $post_id ) {
			self::$previous_upcs[ $post_id ] = get_post_meta( $post_id, 'upc_ean', true );
		}
		return $prepared;
	}

	/**
	 * Retains old identifiers for adoption of legacy spoke records.
	 *
	 * @param int    $post_id Release ID.
	 * @param string $previous Previous UPC.
	 * @param string $current Current UPC.
	 */
	public static function remember_previous_upc( $post_id, $previous, $current ): void {
		if ( '' === $previous || $previous === $current ) {
			return;
		}
		$history = json_decode( get_post_meta( $post_id, '_mds_previous_upcs', true ), true );
		$history = is_array( $history ) ? $history : array();
		if ( ! in_array( $previous, $history, true ) ) {
			$history[] = $previous;
			update_post_meta( $post_id, '_mds_previous_upcs', wp_json_encode( $history ) );
		}
	}

	/**
	 * Synchronizes a release after its REST metadata and terms are saved.
	 *
	 * @param WP_Post         $post Saved post.
	 * @param WP_REST_Request $request REST request.
	 * @param bool            $creating Whether the post was created.
	 */
	public static function after_rest_save( $post, $request, $creating ): void {
		if ( 'auto-draft' === $post->post_status ) {
			return;
		}
		$previous = self::$previous_upcs[ $post->ID ] ?? '';
		self::remember_previous_upc( $post->ID, $previous, get_post_meta( $post->ID, 'upc_ean', true ) );
		delete_post_meta( $post->ID, 'release_format' );
		$result = MDS_RM_Sync::broadcast_to_spokes( $post->ID, 'release' );
		if ( is_wp_error( $result ) ) {
			self::$rest_sync_errors[ spl_object_id( $request ) ] = $result;
		}
		MDS_RM_Sync::purge_cache();
		self::release_upc_lock( get_post_meta( $post->ID, 'upc_ean', true ) );
	}

	/**
	 * Reports a synchronization failure after a successful REST save.
	 *
	 * @param mixed           $response Existing response.
	 * @param array           $handler Route handler.
	 * @param WP_REST_Request $request REST request.
	 * @return mixed Existing response or synchronization error.
	 */
	public static function report_rest_sync_error( mixed $response, $handler, $request ): mixed {
		$key = spl_object_id( $request );
		unset( self::$rest_requests[ $key ] );
		if ( isset( self::$rest_sync_errors[ $key ] ) ) {
			$error = self::$rest_sync_errors[ $key ];
			unset( self::$rest_sync_errors[ $key ] );
			/* translators: %s: synchronization error message */
			return new WP_Error( 'mds_sync_failed', sprintf( __( 'The release was saved, but synchronization failed: %s', 'mds-release-manager' ), $error->get_error_message() ), array( 'status' => 500 ) );
		}
		return $response;
	}
}

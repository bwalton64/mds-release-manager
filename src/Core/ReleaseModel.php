<?php
/**
 * Release identity and format validation.
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates release identity and keeps the supported taxonomy terms available.
 */
final class ReleaseModel {
	public const MDS_RM_FORMATS                   = array( 'Single', 'EP', 'Album', 'Digital' );
	public const DEFAULT_MEDIA_FORMATS            = array(
		'Digital Download' => 'fas fa-download',
		'CD'               => 'fas fa-compact-disc',
		'Vinyl LP'         => 'fas fa-record-vinyl',
		'Vinyl EP'         => 'fas fa-record-vinyl',
		'7" Single'        => 'fas fa-record-vinyl',
		'Cassette'         => 'fas fa-tape',
		'USB Flash Drive'  => 'fab fa-usb',
	);
	public const MEDIA_FORMAT_ICON_META_KEY       = '_mds_media_format_icon';
	private const AUDIT_CACHE_KEY                 = 'mds_rm_release_audit';
	private const NORMALIZED_UPC_META_KEY         = '_mds_normalized_upc';
	private const NORMALIZED_UPC_MIGRATION_OPTION = 'mds_rm_normalized_upc_migration_complete';
	private const RELEASE_TYPE_MIGRATION_OPTION   = 'mds_rm_release_type_migrated';
	private const MEDIA_FORMATS_SEEDED_OPTION     = 'mds_rm_media_formats_seeded';

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
		add_action( 'init', array( self::class, 'migrate_release_format_taxonomy' ), 15 );
		add_action( 'init', array( self::class, 'register_fields_and_formats' ), 20 );
		add_action( 'admin_init', array( self::class, 'migrate_normalized_upcs' ) );
		add_action( 'release_media_format_add_form_fields', array( self::class, 'render_media_format_add_icon_field' ) );
		add_action( 'release_media_format_edit_form_fields', array( self::class, 'render_media_format_edit_icon_field' ) );
		add_action( 'created_release_media_format', array( self::class, 'save_media_format_icon_meta' ) );
		add_action( 'edited_release_media_format', array( self::class, 'save_media_format_icon_meta' ) );
		add_filter( 'manage_edit-release_media_format_columns', array( self::class, 'add_media_format_icon_column' ) );
		add_filter( 'manage_release_media_format_custom_column', array( self::class, 'render_media_format_icon_column' ), 10, 3 );
		add_filter( 'wp_insert_post_empty_content', array( self::class, 'validate_classic_save' ), 10, 2 );
		add_filter( 'rest_pre_insert_release', array( self::class, 'validate_rest_save' ), 10, 2 );
		add_action( 'rest_after_insert_release', array( self::class, 'after_rest_save' ), 10, 3 );
		add_filter( 'rest_request_before_callbacks', array( self::class, 'before_rest_request' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( self::class, 'report_rest_sync_error' ), 10, 3 );
		add_action( 'admin_notices', array( self::class, 'show_audit_notice' ) );
		add_action( 'save_post_release', array( self::class, 'invalidate_release_audit_cache' ) );
		add_action( 'before_delete_post', array( self::class, 'invalidate_release_audit_cache' ) );
		add_action( 'added_post_meta', array( self::class, 'sync_normalized_upc_meta' ), 10, 4 );
		add_action( 'updated_post_meta', array( self::class, 'sync_normalized_upc_meta' ), 10, 4 );
		add_action( 'deleted_post_meta', array( self::class, 'sync_normalized_upc_meta' ), 10, 4 );
		add_action( 'added_post_meta', array( self::class, 'invalidate_audit_on_meta_change' ), 10, 4 );
		add_action( 'updated_post_meta', array( self::class, 'invalidate_audit_on_meta_change' ), 10, 4 );
		add_action( 'deleted_post_meta', array( self::class, 'invalidate_audit_on_meta_change' ), 10, 4 );
		add_action( 'set_object_terms', array( self::class, 'invalidate_audit_on_term_change' ), 10, 4 );
		add_action( 'edited_term', array( self::class, 'invalidate_audit_on_format_edit' ), 10, 3 );
		add_filter( 'pre_insert_term', array( self::class, 'validate_format_term' ), 10, 3 );
		add_filter( 'map_meta_cap', array( self::class, 'protect_format_terms' ), 10, 4 );
	}

	/**
	 * One-time migration renaming the legacy `release_format` taxonomy rows to `release_type`.
	 *
	 * @return void
	 */
	public static function migrate_release_format_taxonomy(): void {
		if ( '1' === get_option( self::RELEASE_TYPE_MIGRATION_OPTION, '0' ) ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time taxonomy slug migration.
		$updated = $wpdb->update(
			$wpdb->term_taxonomy,
			array( 'taxonomy' => 'release_type' ),
			array( 'taxonomy' => 'release_format' ),
			array( '%s' ),
			array( '%s' )
		);
		if ( false !== $updated ) {
			update_option( self::RELEASE_TYPE_MIGRATION_OPTION, '1', false );
			if ( $updated > 0 ) {
				clean_taxonomy_cache( 'release_type' );
				flush_rewrite_rules( false );
			}
		}
	}

	/** Registers REST metadata, canonical Release Type terms, and default Media Format terms. */
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
			$term = term_exists( $format, 'release_type' );
			if ( ! $term ) {
				$term = wp_insert_term( $format, 'release_type' );
			}
			if ( is_wp_error( $term ) && 'term_exists' === $term->get_error_code() ) {
				$existing = term_exists( $format, 'release_type' );
				if ( $existing && ! is_wp_error( $existing ) ) {
					$term = $existing;
				}
			}
			if ( is_wp_error( $term ) ) {
				Logger::report_failure( 'Could not register release type: ' . $term->get_error_message() );
				add_action(
					'admin_notices',
					static function () use ( $format ) {
						/* translators: %s: Release type name. */
						echo '<div class="notice notice-error"><p>' . esc_html( sprintf( __( 'Could not register the release type "%s". Check the server error log.', 'mds-release-manager' ), $format ) ) . '</p></div>';
					}
				);
			}
		}

		if ( '1' !== get_option( self::MEDIA_FORMATS_SEEDED_OPTION, '0' ) && taxonomy_exists( 'release_media_format' ) ) {
			foreach ( self::DEFAULT_MEDIA_FORMATS as $label => $icon ) {
				$existing = term_exists( $label, 'release_media_format' );
				if ( ! $existing ) {
					$existing = wp_insert_term( $label, 'release_media_format' );
				}
				if ( ! is_wp_error( $existing ) && $existing ) {
					$term_id = is_array( $existing ) ? (int) $existing['term_id'] : (int) $existing;
					if ( $term_id > 0 && '' === (string) get_term_meta( $term_id, self::MEDIA_FORMAT_ICON_META_KEY, true ) ) {
						update_term_meta( $term_id, self::MEDIA_FORMAT_ICON_META_KEY, $icon );
					}
				}
			}
			update_option( self::MEDIA_FORMATS_SEEDED_OPTION, '1', false );
		}
	}

	/**
	 * Renders the icon CSS class input on the Add Media Format term screen.
	 *
	 * @return void
	 */
	public static function render_media_format_add_icon_field(): void {
		?>
		<div class="form-field term-mds-icon-wrap">
			<label for="mds_media_format_icon"><?php esc_html_e( 'Icon CSS Classes', 'mds-release-manager' ); ?></label>
			<input type="text" name="mds_media_format_icon" id="mds_media_format_icon" value="fas fa-compact-disc" />
			<p class="description"><?php esc_html_e( 'Font Awesome or Dashicons classes shown in the Buy Now format picker (e.g. fas fa-record-vinyl, fas fa-compact-disc, fas fa-download).', 'mds-release-manager' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Renders the icon CSS class input on the Edit Media Format term screen.
	 *
	 * @param WP_Term $term Term being edited.
	 * @return void
	 */
	public static function render_media_format_edit_icon_field( $term ): void {
		$icon = self::get_media_format_icon( (int) $term->term_id, $term->name );
		?>
		<tr class="form-field term-mds-icon-wrap">
			<th scope="row"><label for="mds_media_format_icon"><?php esc_html_e( 'Icon CSS Classes', 'mds-release-manager' ); ?></label></th>
			<td>
				<input type="text" name="mds_media_format_icon" id="mds_media_format_icon" value="<?php echo esc_attr( $icon ); ?>" class="regular-text" />
				<p class="description"><?php esc_html_e( 'Font Awesome or Dashicons classes shown in the Buy Now format picker (e.g. fas fa-record-vinyl, fas fa-compact-disc, fas fa-download).', 'mds-release-manager' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Persists the icon CSS classes for a `release_media_format` term.
	 *
	 * @param int $term_id Term ID.
	 * @return void
	 */
	public static function save_media_format_icon_meta( int $term_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress verifies term add/edit nonces before firing created_/edited_ hooks.
		if ( ! isset( $_POST['mds_media_format_icon'] ) || ! is_string( $_POST['mds_media_format_icon'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$icon = sanitize_text_field( wp_unslash( $_POST['mds_media_format_icon'] ) );
		if ( '' !== $icon ) {
			update_term_meta( $term_id, self::MEDIA_FORMAT_ICON_META_KEY, $icon );
		} else {
			delete_term_meta( $term_id, self::MEDIA_FORMAT_ICON_META_KEY );
		}

		if ( class_exists( 'MDS\ReleaseManager\Core\Cache' ) ) {
			Cache::purge( 'media_format' );
		}
	}

	/**
	 * Adds an Icon column to the Media Formats taxonomy table.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string> Updated columns.
	 */
	public static function add_media_format_icon_column( array $columns ): array {
		$updated = array();
		foreach ( $columns as $key => $label ) {
			$updated[ $key ] = $label;
			if ( 'name' === $key ) {
				$updated['mds_media_icon'] = __( 'Icon', 'mds-release-manager' );
			}
		}
		return $updated;
	}

	/**
	 * Renders the Icon column cell for a Media Format term.
	 *
	 * @param string $content     Existing cell content.
	 * @param string $column_name Column slug.
	 * @param int    $term_id     Term ID.
	 * @return string Cell markup.
	 */
	public static function render_media_format_icon_column( string $content, string $column_name, int $term_id ): string {
		if ( 'mds_media_icon' !== $column_name ) {
			return $content;
		}
		$term = get_term( $term_id, 'release_media_format' );
		$icon = self::get_media_format_icon( $term_id, $term && ! is_wp_error( $term ) ? $term->name : '' );
		return '<code>' . esc_html( $icon ) . '</code>';
	}

	/**
	 * Resolves the icon CSS classes for a Media Format term.
	 *
	 * @param int    $term_id   Term ID.
	 * @param string $term_name Optional term name for default fallback.
	 * @return string Icon CSS classes.
	 */
	public static function get_media_format_icon( int $term_id, string $term_name = '' ): string {
		$stored = $term_id > 0 ? get_term_meta( $term_id, self::MEDIA_FORMAT_ICON_META_KEY, true ) : '';
		if ( is_string( $stored ) && '' !== trim( $stored ) ) {
			return trim( $stored );
		}
		if ( '' !== $term_name ) {
			foreach ( self::DEFAULT_MEDIA_FORMATS as $default_name => $default_icon ) {
				if ( 0 === strcasecmp( $default_name, $term_name ) ) {
					return $default_icon;
				}
			}
		}
		return 'fas fa-cart-shopping';
	}

	/**
	 * Returns all normalized Buy Now purchase offers for a Release.
	 *
	 * Falls back seamlessly to the legacy scalar `buy_now_link` post meta when
	 * `buy_now_links` has not been saved yet. Extensions (such as WooCommerce
	 * Integration) can filter the result via `mds_rm_buy_now_links`.
	 *
	 * @since 1.1.0
	 * @param int $post_id Release post ID.
	 * @return array<int, array{format:string,label:string,store:string,url:string,icon:string,primary:bool}> Normalized purchase offers.
	 */
	public static function get_buy_now_links( int $post_id ): array {
		$links = array();
		$raw   = get_post_meta( $post_id, 'buy_now_links', true );
		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$links = self::sanitize_buy_now_links( $decoded );
			}
		} elseif ( is_array( $raw ) ) {
			$links = self::sanitize_buy_now_links( $raw );
		}

		if ( empty( $links ) ) {
			$legacy_url = get_post_meta( $post_id, 'buy_now_link', true );
			if ( is_string( $legacy_url ) && '' !== trim( $legacy_url ) && '' !== esc_url_raw( $legacy_url ) ) {
				$links[] = array(
					'format'  => '',
					'label'   => __( 'Buy Now', 'mds-release-manager' ),
					'store'   => '',
					'url'     => esc_url_raw( $legacy_url ),
					'icon'    => 'fas fa-cart-shopping',
					'primary' => true,
				);
			}
		}

		// Enrich each entry with live Media Format term label and icon.
		foreach ( $links as &$item ) {
			$slug = $item['format'] ?? '';
			$term = '' !== $slug && function_exists( 'get_term_by' ) ? get_term_by( 'slug', $slug, 'release_media_format' ) : false;
			if ( $term && ! is_wp_error( $term ) ) {
				$item['label'] = $term->name;
				$item['icon']  = self::get_media_format_icon( (int) $term->term_id, $term->name );
			} else {
				if ( '' === ( $item['label'] ?? '' ) ) {
					$item['label'] = '' !== $slug ? ucwords( str_replace( '-', ' ', $slug ) ) : __( 'Buy Now', 'mds-release-manager' );
				}
				if ( '' === ( $item['icon'] ?? '' ) ) {
					$item['icon'] = self::get_media_format_icon( 0, $item['label'] );
				}
			}
		}
		unset( $item );

		/**
		 * Filters the normalized multi-format Buy Now links for a Release.
		 *
		 * @since 1.1.0
		 * @param array $links   Normalized purchase offers.
		 * @param int   $post_id Release post ID.
		 */
		$filtered = apply_filters( 'mds_rm_buy_now_links', $links, $post_id );
		return is_array( $filtered ) ? $filtered : $links;
	}

	/**
	 * Returns the authoritative Primary Buy Now URL for a Release.
	 *
	 * Extensions (such as WooCommerce Integration in White-Label mode) can hook
	 * `mds_rm_buy_now_url` to supply a unified storefront product URL.
	 *
	 * @since 1.1.0
	 * @param int $post_id Release post ID.
	 * @return string Sanitized URL, or empty string when unavailable.
	 */
	public static function get_primary_buy_now_url( int $post_id ): string {
		$links   = self::get_buy_now_links( $post_id );
		$primary = '';
		foreach ( $links as $item ) {
			if ( ! empty( $item['primary'] ) && ! empty( $item['url'] ) ) {
				$primary = (string) $item['url'];
				break;
			}
		}
		if ( '' === $primary && ! empty( $links[0]['url'] ) ) {
			$primary = (string) $links[0]['url'];
		}

		/**
		 * Filters the primary Buy Now destination URL for a Release.
		 *
		 * @since 1.1.0
		 * @param string $primary Primary Buy Now URL.
		 * @param int    $post_id Release post ID.
		 * @param array  $links   All normalized Buy Now offers for the Release.
		 */
		$filtered = apply_filters( 'mds_rm_buy_now_url', $primary, $post_id, $links );
		return is_string( $filtered ) ? esc_url_raw( $filtered ) : '';
	}

	/**
	 * Sanitizes an array of submitted Buy Now link rows.
	 *
	 * @param array<int, mixed> $raw_links Raw link rows.
	 * @return array<int, array{format:string,label:string,store:string,url:string,icon:string,primary:bool}> Sanitized rows.
	 */
	public static function sanitize_buy_now_links( array $raw_links ): array {
		$clean       = array();
		$has_primary = false;

		foreach ( $raw_links as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$url = isset( $row['url'] ) && is_string( $row['url'] ) ? esc_url_raw( trim( $row['url'] ) ) : '';
			if ( '' === $url ) {
				continue;
			}
			$format     = isset( $row['format'] ) && is_string( $row['format'] ) ? sanitize_title( $row['format'] ) : '';
			$label      = isset( $row['label'] ) && is_string( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '';
			$store      = isset( $row['store'] ) && is_string( $row['store'] ) ? sanitize_text_field( $row['store'] ) : '';
			$icon       = isset( $row['icon'] ) && is_string( $row['icon'] ) ? sanitize_text_field( $row['icon'] ) : '';
			$is_primary = ! empty( $row['primary'] ) && ! $has_primary;
			if ( $is_primary ) {
				$has_primary = true;
			}
			$clean[] = array(
				'format'  => $format,
				'label'   => $label,
				'store'   => $store,
				'url'     => $url,
				'icon'    => $icon,
				'primary' => $is_primary,
			);
		}

		if ( $clean && ! $has_primary ) {
			$clean[0]['primary'] = true;
		}

		return $clean;
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
	 * Synchronizes the normalized UPC lookup key when the stored value changes.
	 *
	 * @param mixed  $meta_id Meta ID or IDs.
	 * @param int    $post_id Post ID.
	 * @param string $meta_key Meta key.
	 * @param mixed  $meta_value New or deleted meta value.
	 * @return void
	 */
	public static function sync_normalized_upc_meta( $meta_id, int $post_id, string $meta_key, $meta_value ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Preserve the metadata hook signature.
		if ( 'upc_ean' !== $meta_key || 'release' !== get_post_type( $post_id ) ) {
			return;
		}

		$upc = self::normalize_upc( get_post_meta( $post_id, 'upc_ean', true ) );
		if ( '' === $upc ) {
			delete_post_meta( $post_id, self::NORMALIZED_UPC_META_KEY );
			return;
		}

		update_post_meta( $post_id, self::NORMALIZED_UPC_META_KEY, $upc );
	}

	/**
	 * Backfills normalized UPC keys in small batches until the legacy catalog is migrated.
	 *
	 * @return void
	 */
	public static function migrate_normalized_upcs(): void {
		if ( ! current_user_can( 'manage_options' ) || '1' === get_option( self::NORMALIZED_UPC_MIGRATION_OPTION, '0' ) ) {
			return;
		}

		$release_ids = get_posts(
			array(
				'post_type'              => 'release',
				'post_status'            => array_diff( array_keys( get_post_stati() ), array( 'auto-draft', 'inherit' ) ),
				'posts_per_page'         => 100,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'     => self::NORMALIZED_UPC_META_KEY,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		foreach ( $release_ids as $release_id ) {
			$upc = self::normalize_upc( get_post_meta( $release_id, 'upc_ean', true ) );
			update_post_meta( $release_id, self::NORMALIZED_UPC_META_KEY, $upc );
		}

		if ( count( $release_ids ) < 100 ) {
			update_option( self::NORMALIZED_UPC_MIGRATION_OPTION, '1', false );
		}
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
		if ( 'release_type' !== $taxonomy || is_wp_error( $term ) ) {
			return $term;
		}
		if ( ! is_string( $term ) || ! in_array( $term, self::MDS_RM_FORMATS, true ) || ! empty( $args['parent'] ) ) {
			return new \WP_Error( 'mds_invalid_format_term', __( 'Release types must be Single, EP, Album, or Digital without a parent.', 'mds-release-manager' ) );
		}
		return $term;
	}

	/**
	 * Prevents edits or deletion of canonical release types.
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
		$term = get_term( (int) $args[0], 'release_type' );
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
	 * Returns the authoritative release type for a release.
	 *
	 * @param int $post_id Release ID.
	 * @return string|WP_Error Release type name or validation error.
	 */
	public static function format_name( $post_id ): string|\WP_Error {
		$terms = wp_get_post_terms( $post_id, 'release_type' );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}
		if ( 1 !== count( $terms ) || 0 !== (int) $terms[0]->parent || ! in_array( $terms[0]->name, self::MDS_RM_FORMATS, true ) ) {
			return new \WP_Error( 'mds_invalid_format', __( 'The release must have exactly one valid release type.', 'mds-release-manager' ) );
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

		$issues = get_transient( self::AUDIT_CACHE_KEY );
		if ( false === $issues ) {
			$issues = self::collect_release_audit_issues();
			set_transient( self::AUDIT_CACHE_KEY, $issues, DAY_IN_SECONDS );
		}
		if ( ! $issues ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>' . esc_html__( 'These releases need review for missing, malformed, or duplicate UPCs, invalid formats, or conflicting legacy format data:', 'mds-release-manager' ) . ' ';
		$links = array();
		foreach ( $issues as $id ) {
			$url     = get_edit_post_link( $id, 'raw' );
			$label   = esc_html( get_the_title( $id ) . ' (#' . $id . ')' );
			$links[] = $url ? '<a href="' . esc_url( $url ) . '">' . $label . '</a>' : $label;
		}
		echo implode( ', ', $links ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each link is escaped above.
	}

	/**
	 * Collects release IDs needing administrative review.
	 *
	 * @return int[] Release IDs.
	 */
	private static function collect_release_audit_issues(): array {
		$issues = array();
		$upcs   = array();
		$page   = 1;
		do {
			$posts = get_posts(
				array(
					'post_type'              => 'release',
					'post_status'            => array_diff( array_keys( get_post_stati() ), array( 'auto-draft', 'inherit' ) ),
					'posts_per_page'         => 100,
					'paged'                  => $page,
					'fields'                 => 'ids',
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_meta_cache' => true,
					'update_post_term_cache' => true,
				)
			);
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
			++$page;
		} while ( count( $posts ) === 100 );
		foreach ( $upcs as $ids ) {
			if ( count( $ids ) > 1 ) {
				foreach ( $ids as $id ) {
					$issues[ $id ] = true;
				}
			}
		}

		return array_map( 'intval', array_keys( $issues ) );
	}

	/**
	 * Invalidates the cached release audit after a release is saved or deleted.
	 *
	 * @param int $post_id Affected post ID.
	 * @return void
	 */
	public static function invalidate_release_audit_cache( int $post_id ): void {
		if ( 'release' === get_post_type( $post_id ) ) {
			delete_transient( self::AUDIT_CACHE_KEY );
		}
	}

	/**
	 * Invalidates the audit when release metadata changes.
	 *
	 * @param mixed  $meta_id Meta ID or IDs.
	 * @param int    $post_id Post ID.
	 * @param string $meta_key Changed key.
	 * @param mixed  $meta_value Changed value.
	 * @return void
	 */
	public static function invalidate_audit_on_meta_change( $meta_id, int $post_id, string $meta_key, $meta_value ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Preserve the metadata hook signature.
		if ( 'release' === get_post_type( $post_id ) && in_array( $meta_key, array( 'upc_ean', 'release_format' ), true ) ) {
			delete_transient( self::AUDIT_CACHE_KEY );
		}
	}

	/**
	 * Invalidates the audit when a release's format terms change.
	 *
	 * @param int    $object_id Object ID.
	 * @param array  $terms Terms assigned.
	 * @param array  $term_taxonomy_ids Term-taxonomy IDs.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public static function invalidate_audit_on_term_change( int $object_id, array $terms, array $term_taxonomy_ids, string $taxonomy ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Preserve the term hook signature.
		if ( 'release_type' === $taxonomy && 'release' === get_post_type( $object_id ) ) {
			delete_transient( self::AUDIT_CACHE_KEY );
		}
	}

	/**
	 * Invalidates the audit after a canonical Release Type is renamed.
	 *
	 * @param int    $term_id Term ID.
	 * @param int    $term_taxonomy_id Term-taxonomy ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return void
	 */
	public static function invalidate_audit_on_format_edit( int $term_id, int $term_taxonomy_id, string $taxonomy ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Preserve the term hook signature.
		if ( 'release_type' === $taxonomy ) {
			delete_transient( self::AUDIT_CACHE_KEY );
		}
	}

	/**
	 * Resolves a supported release type name to its local taxonomy term ID.
	 *
	 * @param string $name Release type name.
	 * @return int Term ID, or zero if invalid or unavailable.
	 */
	public static function format_id( $name ): int {
		foreach ( self::MDS_RM_FORMATS as $format ) {
			if ( 0 === strcasecmp( trim( $name ), $format ) ) {
				$term = get_term_by( 'name', $format, 'release_type' );
				return $term && 0 === (int) $term->parent ? (int) $term->term_id : 0;
			}
		}
		return 0;
	}

	/**
	 * Validates and reserves a release identifier and release type for saving.
	 *
	 * @param int   $post_id Existing release ID, or zero.
	 * @param mixed $upc Submitted identifier.
	 * @param array $format_ids Selected term IDs.
	 * @return true|WP_Error True when valid, otherwise an error.
	 */
	public static function validate( $post_id, $upc, $format_ids ): bool|\WP_Error {
		$upc = self::normalize_upc( $upc );
		if ( '' === $upc ) {
			return new \WP_Error( 'mds_upc_required', __( 'A UPC/EAN is required.', 'mds-release-manager' ), array( 'status' => 400 ) );
		}

		if ( ! is_array( $format_ids ) || 1 !== count( $format_ids ) ) {
			return new \WP_Error( 'mds_format_required', __( 'Select exactly one release type.', 'mds-release-manager' ), array( 'status' => 400 ) );
		}
		$term = get_term( (int) reset( $format_ids ), 'release_type' );
		if ( ! $term || is_wp_error( $term ) || 0 !== (int) $term->parent || ! in_array( $term->name, self::MDS_RM_FORMATS, true ) ) {
			return new \WP_Error( 'mds_invalid_format', __( 'Select Single, EP, Album, or Digital.', 'mds-release-manager' ), array( 'status' => 400 ) );
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
				'post__not_in'           => $post_id ? array( (int) $post_id ) : array(), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Exclude the record being edited before selecting the first conflict.
				'meta_key'               => self::NORMALIZED_UPC_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Match the dedicated normalized lookup key under the advisory lock.
				'meta_value'             => $upc, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		if ( ! $matches && '1' !== get_option( self::NORMALIZED_UPC_MIGRATION_OPTION, '0' ) ) {
			$page       = 1;
			$batch_size = 100;
			do {
				$legacy_ids = get_posts(
					array(
						'post_type'              => 'release',
						'post_status'            => array_keys( get_post_stati() ),
						'posts_per_page'         => $batch_size,
						'paged'                  => $page,
						'fields'                 => 'ids',
						'post__not_in'           => $post_id ? array( (int) $post_id ) : array(), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- The edited record cannot be a conflicting legacy record.
						'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Transitional lookup only while the bounded normalized-key migration is incomplete.
							'relation' => 'AND',
							array(
								'key'     => self::NORMALIZED_UPC_META_KEY,
								'compare' => 'NOT EXISTS',
							),
							array(
								'relation' => 'OR',
								array(
									'key'     => 'upc_ean',
									'value'   => $upc,
									'compare' => '=',
								),
								array(
									'key'     => 'upc_ean',
									'value'   => '[^0-9]',
									'compare' => 'REGEXP',
								),
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
				++$page;
			} while ( ! $matches && count( $legacy_ids ) === $batch_size );
		}
		if ( $matches ) {
			self::release_upc_lock( $upc );
			return new \WP_Error( 'mds_duplicate_upc', __( 'This UPC/EAN is already assigned to another release.', 'mds-release-manager' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Acquires a site-scoped database lock for a UPC.
	 *
	 * @param string $upc Normalized UPC.
	 * @return true|WP_Error True when locked, otherwise an error.
	 */
	private static function lock_upc( string $upc ): bool|\WP_Error {
		global $wpdb;
		$key = 'mds-upc-' . substr( hash( 'sha256', get_current_blog_id() . ':' . $upc ), 0, 48 );
		if ( isset( self::$upc_locks[ $key ] ) ) {
			return true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A connection-scoped advisory lock must execute against MySQL, not the object cache.
		$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $key, 5 ) );
		if ( '1' !== (string) $locked ) {
			return new \WP_Error( 'mds_upc_lock_failed', __( 'Could not reserve this UPC/EAN for saving. Please try again.', 'mds-release-manager' ), array( 'status' => 503 ) );
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
	public static function validate_classic_save( bool $empty, array $postarr ): bool { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.emptyFound -- Preserve the public parameter name for PHP named arguments.
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
				if ( isset( $postarr['tax_input']['release_type'] ) ) {
					wp_die(
						esc_html__( 'Change the release type in the release editor.', 'mds-release-manager' ),
						'',
						array(
							'response'  => 400,
							'back_link' => true,
						)
					);
				}
				if ( self::has_legacy_format_conflict( $post_id ) ) {
					wp_die(
						esc_html__( 'Resolve the release type conflict in the release editor first.', 'mds-release-manager' ),
						'',
						array(
							'response'  => 400,
							'back_link' => true,
						)
					);
				}
				$upc     = get_post_meta( $post_id, 'upc_ean', true );
				$formats = wp_get_post_terms( $post_id, 'release_type', array( 'fields' => 'ids' ) );
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

		$format = isset( $_POST['release_type'] ) && is_scalar( $_POST['release_type'] )
			? array( absint( wp_unslash( $_POST['release_type'] ) ) )
			: ( $post_id ? wp_get_post_terms( $post_id, 'release_type', array( 'fields' => 'ids' ) ) : array() );

		if ( ! isset( $_POST['release_type'] ) && $post_id && self::has_legacy_format_conflict( $post_id ) ) {
			wp_die(
				esc_html__( 'The saved release type conflicts with legacy data. Choose a release type explicitly.', 'mds-release-manager' ),
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
		$taxonomy = get_taxonomy( 'release_type' );
		if ( isset( $_POST['release_type'] ) && ( ! $taxonomy || ! current_user_can( $taxonomy->cap->assign_terms ) ) ) {
			wp_die(
				esc_html__( 'You are not allowed to assign a release type.', 'mds-release-manager' ),
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
		$formats = $request->get_param( 'release_type' );
		if ( null === $formats && $post_id && self::has_legacy_format_conflict( $post_id ) ) {
			return new \WP_Error( 'mds_format_conflict', __( 'The saved release type conflicts with legacy data. Choose a release type explicitly.', 'mds-release-manager' ), array( 'status' => 400 ) );
		}
		if ( null === $formats ) {
			$formats = $post_id ? wp_get_post_terms( $post_id, 'release_type', array( 'fields' => 'ids' ) ) : array();
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
	 * @param bool            $creating Whether the post was created; both paths use the same synchronization.
	 */
	public static function after_rest_save( $post, $request, $creating ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Preserve the registered REST callback signature.
		if ( 'auto-draft' === $post->post_status ) {
			return;
		}
		$previous = self::$previous_upcs[ $post->ID ] ?? '';
		self::remember_previous_upc( $post->ID, $previous, get_post_meta( $post->ID, 'upc_ean', true ) );
		delete_post_meta( $post->ID, 'release_format' );
		$result = Sync::broadcast_to_spokes( $post->ID, 'release' );
		if ( is_wp_error( $result ) ) {
			self::$rest_sync_errors[ spl_object_id( $request ) ] = $result;
		}
		Sync::purge_cache( 'post_save' );
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
			return new \WP_Error( 'mds_sync_failed', sprintf( __( 'The release was saved, but synchronization failed: %s', 'mds-release-manager' ), $error->get_error_message() ), array( 'status' => 500 ) );
		}
		return $response;
	}
}

<?php
declare(strict_types=1);

/**
 * Handles CSV import and export for release posts.
 *
 * @package MDS_Release_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles CSV import and export for release posts.
 *
 * @since 0.9.0
 */
final class MDS_RM_CSV_Handler {

	/**
	 * Registers the CSV page and form handlers.
	 *
	 * @since 0.9.0
	 */
	public static function register_hooks() {
		add_action( 'admin_menu', array( self::class, 'register_csv_page' ) );
		add_action( 'admin_post_mds_rm_export_csv', array( self::class, 'export_csv' ) );
		add_action( 'admin_post_mds_rm_import_csv', array( self::class, 'import_csv' ) );
	}

	/**
	 * Adds the CSV import and export submenu page.
	 *
	 * @since 0.9.0
	 */
	public static function register_csv_page() {
		add_submenu_page(
			'edit.php?post_type=release',
			'CSV Import/Export',
			'CSV Import/Export',
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
	public static function verify_csv_request() {
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
	 * Streams release data as a CSV download.
	 *
	 * @since 0.9.0
	 */
	public static function export_csv() {
		self::verify_csv_request();

		$releases             = get_posts(
			array(
				'post_type'   => 'release',
				'numberposts' => -1,
				'post_status' => 'any',
			)
		);
		$artists              = get_posts(
			array(
				'post_type'   => 'artist',
				'numberposts' => -1,
				'post_status' => 'any',
			)
		);
		$artist_names_by_uuid = array();
		foreach ( $artists as $artist ) {
			$artist_uuid = get_post_meta( $artist->ID, '_mds_artist_uuid', true );
			if ( $artist_uuid && ! isset( $artist_names_by_uuid[ $artist_uuid ] ) ) {
				$artist_names_by_uuid[ $artist_uuid ] = $artist->post_title;
			}
		}

		$release_formats = array();
		foreach ( $releases as $release ) {
			$format = MDS_RM_Release_Model::format_name( $release->ID );
			if ( MDS_RM_Release_Model::has_legacy_format_conflict( $release->ID ) ) {
				wp_die( esc_html( sprintf( __( 'Cannot export release %d: its format conflicts with legacy data. Resolve the conflict first.', 'mds-release-manager' ), $release->ID ) ) );
			}
			if ( is_wp_error( $format ) ) {
				wp_die( esc_html( sprintf( __( 'Cannot export release %1$d: %2$s', 'mds-release-manager' ), $release->ID, $format->get_error_message() ) ) );
			}
			$release_formats[ $release->ID ] = $format;
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="mds-releases-export.csv"' );
		$out = fopen( 'php://output', 'w' );
		if ( ! $out ) {
			wp_die( esc_html__( 'Could not create the CSV export.', 'mds-release-manager' ) );
		}

		if ( empty( $releases ) ) {
			fputcsv( $out, array( 'No data found in WordPress posts.' ) );
			exit;
		}

		$headers = array(
			'UPC/EAN',
			'Release Title',
			'Artist',
			'Release Date',
			'Release Format',
			'Record Label',
			'Distributor',
			'Buy Now Link',
			'Bandcamp Shortcode',
			'Tags',
			'Featured Image URL',
			'Audio Preview URL',
			'About',
			'Streaming Links',
		);
		fputcsv( $out, $headers );

		foreach ( $releases as $release ) {
			$artist_id = get_post_meta( $release->ID, 'artist_id', true );
			$row       = array(
				get_post_meta( $release->ID, 'upc_ean', true ),
				$release->post_title,
				$artist_names_by_uuid[ $artist_id ] ?? 'Unknown Artist',
				$release->post_date,
				$release_formats[ $release->ID ],
				get_post_meta( $release->ID, 'record_label', true ),
				get_post_meta( $release->ID, 'distributor', true ),
				get_post_meta( $release->ID, 'buy_now_link', true ),
				get_post_meta( $release->ID, 'bandcamp_shortcode', true ),
				get_post_meta( $release->ID, 'release_tags', true ),
				get_post_meta( $release->ID, 'featured_image_url', true ),
				get_post_meta( $release->ID, 'audio_preview_url', true ),
				$release->post_content,
				get_post_meta( $release->ID, 'streaming_links', true ),
			);
			fputcsv( $out, $row );
		}

		exit;
	}

	/**
	 * Imports release data from the uploaded CSV file.
	 *
	 * @since 0.9.0
	 */
	public static function import_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to manage CSV data.', 'mds-release-manager' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( 'mds_csv_action', 'mds_csv_nonce' );

		$tmp_name = isset( $_FILES['mds_csv_file']['tmp_name'] ) && is_string( $_FILES['mds_csv_file']['tmp_name'] )
			? $_FILES['mds_csv_file']['tmp_name']
			: '';

		if ( empty( $tmp_name ) ) {
			self::csv_redirect_with_message(
				'mds_csv_missing_file',
				__( 'Please select a CSV file to import.', 'mds-release-manager' ),
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

		$csv_stream = new SplTempFileObject();
		$csv_stream->fwrite( $csv_content );
		$csv_stream->rewind();

		$headers    = null;
		$imported   = 0;
		$updated    = 0;
		$errors     = array();
		$row_number = 0;

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

		while ( ( $row = $csv_stream->fgetcsv() ) !== false ) {
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
					if ( ! in_array( 'Release Format', $headers, true ) ) {
						self::csv_redirect_with_message( 'mds_csv_missing_format', __( 'The CSV must include a Release Format column.', 'mds-release-manager' ), 'error' );
					}
				}
				continue;
			}

			if ( count( $headers ) !== count( $row ) ) {
				$errors[] = sprintf( __( 'Row %d: incorrect number of columns.', 'mds-release-manager' ), $row_number );
				continue;
			}
			$data = array_combine( $headers, $row );

			$artist_name  = sanitize_text_field( $data['Artist'] ?? 'Unknown Artist' );
			$upc_raw      = sanitize_text_field( $data['UPC/EAN'] ?? '' );
			$upc          = preg_replace( '/[^0-9]/', '', $upc_raw );
			$title        = sanitize_text_field( $data['Release Title'] ?? 'Untitled' );
			$release_date = sanitize_text_field( $data['Release Date'] ?? '' );
			$format       = sanitize_text_field( $data['Release Format'] ?? '' );
			$label        = sanitize_text_field( $data['Record Label'] ?? '' );
			$distributor  = sanitize_text_field( $data['Distributor'] ?? '' );
			$buy_link     = esc_url_raw( $data['Buy Now Link'] ?? '' );
			$bandcamp     = sanitize_text_field( $data['Bandcamp Shortcode'] ?? '' );
			$tags_raw     = sanitize_text_field( $data['Tags'] ?? '' );
			$featured_img = isset( $data['Featured Image URL'] ) ? esc_url_raw( $data['Featured Image URL'] ) : null;
			$audio_url    = isset( $data['Audio Preview URL'] ) ? esc_url_raw( $data['Audio Preview URL'] ) : null;
			$about_raw    = isset( $data['About'] ) ? wp_kses_post( $data['About'] ) : null;

			// Skip empty trailing rows.
			if ( empty( $title ) && empty( $upc ) ) {
				continue;
			}

			$format_id = MDS_RM_Release_Model::format_id( $format );
			$release_found = '' === $upc ? array() : get_posts(
				array(
					'post_type'   => 'release',
					'meta_key'    => 'upc_ean',
					'meta_value'  => $upc,
					'post_status' => array_keys( get_post_stati() ),
					'numberposts' => -1,
				)
			);
			$release_post_id = 1 === count( $release_found ) ? $release_found[0]->ID : 0;
			$validation = MDS_RM_Release_Model::validate( $release_post_id, $upc, $format_id ? array( $format_id ) : array() );
			if ( count( $release_found ) > 1 || is_wp_error( $validation ) ) {
				$message = count( $release_found ) > 1
					? __( 'More than one release has this UPC/EAN.', 'mds-release-manager' )
					: $validation->get_error_message();
				$errors[] = sprintf( __( 'Row %1$d: %2$s', 'mds-release-manager' ), $row_number, $message );
				continue;
			}

			// 1. Resolve Artist CPT natively.
			$artist_args  = array(
				'post_type'   => 'artist',
				'title'       => $artist_name,
				'post_status' => 'any',
				'numberposts' => 1,
			);
			$artist_found = get_posts( $artist_args );
			if ( ! empty( $artist_found ) ) {
				$artist_post_id = $artist_found[0]->ID;
				$artist_uuid    = get_post_meta( $artist_post_id, '_mds_artist_uuid', true );
			} else {
				$artist_post_id = wp_insert_post(
					array(
						'post_title'  => $artist_name,
						'post_name'   => sanitize_title( $artist_name ),
						'post_type'   => 'artist',
						'post_status' => 'publish',
					)
				);
				$artist_uuid    = wp_generate_uuid4();
				update_post_meta( $artist_post_id, '_mds_artist_uuid', $artist_uuid );
			}

			static $broadcasted_artists = array();
			if ( ! empty( $artist_post_id ) && ! isset( $broadcasted_artists[ $artist_post_id ] ) ) {
				$artist_sync = MDS_RM_Sync::broadcast_to_spokes( $artist_post_id, 'artist' );
				if ( is_wp_error( $artist_sync ) ) {
					$errors[] = sprintf( __( 'Row %1$d: artist synchronization failed: %2$s', 'mds-release-manager' ), $row_number, $artist_sync->get_error_message() );
				}
				$broadcasted_artists[ $artist_post_id ] = true;
			}

			// 2. Collect Streaming Provider Links.
			$streaming_links = array();
			foreach ( $provider_names as $provider_name ) {
				$column_key = $provider_name . ' Link';
				if ( empty( $data[ $column_key ] ) ) {
					continue;
				}

				$clean_url = esc_url_raw( trim( $data[ $column_key ] ) );
				if ( empty( $clean_url ) ) {
					continue;
				}

				$streaming_links[ $provider_name ] = $clean_url;
			}
			$streaming_json = wp_json_encode( $streaming_links );

			// 3. Upsert Release CPT natively by UPC.
			$is_update = 0 !== $release_post_id;

			$post_args = array(
				'post_title'   => $title,
				'post_content' => $about_raw ?? '',
				'post_type'    => 'release',
				'post_status'  => 'publish',
			);
			if ( $release_date ) {
				$date                       = gmdate( 'Y-m-d H:i:s', strtotime( $release_date ) );
				$post_args['post_date']     = $date;
				$post_args['post_date_gmt'] = get_gmt_from_date( $date );
			}

			if ( $is_update ) {
				$post_args['ID'] = $release_post_id;
				$release_post_id = wp_update_post( $post_args, true );
			} else {
				$release_post_id = wp_insert_post( $post_args, true );
			}
			if ( is_wp_error( $release_post_id ) || ! $release_post_id ) {
				$errors[] = sprintf( __( 'Row %d: could not save the release.', 'mds-release-manager' ), $row_number );
				MDS_RM_Release_Model::release_upc_lock( $upc );
				continue;
			}

			// Preserve fields.
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
			update_post_meta( $release_post_id, 'bandcamp_shortcode', $bandcamp );
			update_post_meta( $release_post_id, 'streaming_links', wp_slash( $streaming_json ) );

			$result = wp_set_post_terms( $release_post_id, array( $format_id ), 'release_format', false );
			if ( is_wp_error( $result ) ) {
				$errors[] = sprintf( __( 'Row %1$d: %2$s', 'mds-release-manager' ), $row_number, $result->get_error_message() );
				MDS_RM_Release_Model::release_upc_lock( $upc );
				continue;
			}
			delete_post_meta( $release_post_id, 'release_format' );

			if ( $tags_raw ) {
				$tag_array = array_filter( array_map( 'trim', explode( ',', $tags_raw ) ) );
				wp_set_post_terms( $release_post_id, $tag_array, 'release_tag', false );
				update_post_meta( $release_post_id, 'release_tags', implode( ', ', $tag_array ) );
			} else {
				wp_set_post_terms( $release_post_id, array(), 'release_tag', false );
			}

			$release_sync = MDS_RM_Sync::broadcast_to_spokes( $release_post_id, 'release' );
			MDS_RM_Release_Model::release_upc_lock( $upc );
			if ( is_wp_error( $release_sync ) ) {
				$errors[] = sprintf( __( 'Row %1$d: release saved but synchronization failed: %2$s', 'mds-release-manager' ), $row_number, $release_sync->get_error_message() );
			}

			if ( $is_update ) {
				++$updated;
			} else {
				++$imported;
			}
		}

		if ( ! $headers ) {
			self::csv_redirect_with_message( 'mds_csv_missing_header', __( 'The CSV must include UPC/EAN and Release Format columns.', 'mds-release-manager' ), 'error' );
		}
		MDS_RM_Sync::purge_cache();
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
	public static function csv_redirect_with_message( $code, $message, $type ) {
		add_settings_error( 'mds_csv_msg', $code, $message, $type );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect(
			add_query_arg(
				'settings-updated',
				'true',
				admin_url( 'edit.php?post_type=release&page=mds-release-csv' )
			)
		);
		exit;
	}

	/**
	 * Renders the CSV import and export forms.
	 *
	 * @since 0.9.0
	 */
	public static function csv_page() {
		settings_errors( 'mds_csv_msg' );

		$args = array(
			'admin_url'   => admin_url( 'admin-post.php' ),
			'nonce_field' => wp_nonce_field( 'mds_csv_action', 'mds_csv_nonce', true, false ),
		);

    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The template escapes the URL and emits the nonce field.
		echo MDS_RM_Template_Loader::render_admin_view( 'csv-page.php', $args );
	}
}

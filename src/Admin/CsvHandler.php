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
 * Handles CSV import and export for release posts.
 *
 * @since 0.9.0
 */
final class CsvHandler {

	/**
	 * Registers the CSV page and form handlers.
	 *
	 * @since 0.9.0
	 */
	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'register_csv_page' ) );
		add_action( 'admin_post_mds_rm_export_csv', array( self::class, 'export_csv' ) );
		add_action( 'admin_post_mds_rm_import_csv', array( self::class, 'import_csv' ) );
	}

	/**
	 * Adds the CSV import and export submenu page.
	 *
	 * @since 0.9.0
	 */
	public static function register_csv_page(): void {
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
	 * Streams release data as a CSV download.
	 *
	 * @since 0.9.0
	 */
	public static function export_csv(): void {
		self::verify_csv_request();

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
			'Streaming Links',
		);

		$stream     = new \SplTempFileObject();
		$batch_size = 100;
		$page       = 1;
		$has_data   = false;

		do {
			$releases = get_posts(
				array(
					'post_type'      => 'release',
					'post_status'    => 'any',
					'posts_per_page' => $batch_size,
					'paged'          => $page,
					'orderby'        => array(
						'date' => 'DESC',
						'ID'   => 'DESC',
					),
					'no_found_rows'  => true,
				)
			);

			if ( ! $releases ) {
				break;
			}
			if ( ! $has_data ) {
				if ( false === $stream->fputcsv( $headers ) ) {
					wp_die( esc_html__( 'Could not create the CSV export.', 'mds-release-manager' ) );
				}
				$has_data = true;
			}

			$artist_uuids = array();
			foreach ( $releases as $release ) {
				$artist_uuid = get_post_meta( $release->ID, 'artist_id', true );
				if ( is_string( $artist_uuid ) && '' !== $artist_uuid ) {
					$artist_uuids[] = $artist_uuid;
				}
			}
			$artist_uuids         = array_values( array_unique( $artist_uuids ) );
			$artist_names_by_uuid = array();
			if ( $artist_uuids ) {
				$artist_page = 1;
				do {
					$artists = get_posts(
						array(
							'post_type'      => 'artist',
							'post_status'    => 'any',
							'posts_per_page' => 100,
							'paged'          => $artist_page,
							'orderby'        => array(
								'date' => 'DESC',
								'ID'   => 'DESC',
							),
							'no_found_rows'  => true,
							'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Resolves artist names by UUID batch during CSV export.
								array(
									'key'     => '_mds_artist_uuid',
									'value'   => $artist_uuids,
									'compare' => 'IN',
								),
							),
						)
					);
					foreach ( $artists as $artist ) {
						$artist_uuid = get_post_meta( $artist->ID, '_mds_artist_uuid', true );
						if ( $artist_uuid && ! isset( $artist_names_by_uuid[ $artist_uuid ] ) ) {
							$artist_names_by_uuid[ $artist_uuid ] = $artist->post_title;
						}
					}
					++$artist_page;
					$artists_count  = count( $artists );
					$resolved_count = count( $artist_names_by_uuid );
					$total_uuids    = count( $artist_uuids );
				} while ( 100 === $artists_count && $resolved_count < $total_uuids );
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

				$artist_id = get_post_meta( $release->ID, 'artist_id', true );
				$row       = array(
					get_post_meta( $release->ID, 'upc_ean', true ),
					$release->post_title,
					$artist_names_by_uuid[ $artist_id ] ?? 'Unknown Artist',
					$release->post_date,
					$format,
					get_post_meta( $release->ID, 'record_label', true ),
					get_post_meta( $release->ID, 'distributor', true ),
					get_post_meta( $release->ID, 'buy_now_link', true ),
					get_post_meta( $release->ID, 'release_tags', true ),
					get_post_meta( $release->ID, 'featured_image_url', true ),
					get_post_meta( $release->ID, 'audio_preview_url', true ),
					$release->post_content,
					get_post_meta( $release->ID, 'streaming_links', true ),
				);
				if ( false === $stream->fputcsv( $row ) ) {
					wp_die( esc_html__( 'Could not create the CSV export.', 'mds-release-manager' ) );
				}
			}

			++$page;
			$releases_count = count( $releases );
		} while ( $releases_count === $batch_size );

		if ( ! $has_data && false === $stream->fputcsv( array( 'No data found in WordPress posts.' ) ) ) {
			wp_die( esc_html__( 'Could not create the CSV export.', 'mds-release-manager' ) );
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="mds-releases-export.csv"' );
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
			$data = array_combine( $headers, $row );

			$artist_name  = sanitize_text_field( $data['Artist'] ?? 'Unknown Artist' );
			$upc_raw      = sanitize_text_field( $data['UPC/EAN'] ?? '' );
			$upc          = preg_replace( '/[^0-9]/', '', $upc_raw );
			$title        = sanitize_text_field( $data['Release Title'] ?? 'Untitled' );
			$release_date = sanitize_text_field( $data['Release Date'] ?? '' );
			$format       = sanitize_text_field( $data['Release Type'] ?? '' );
			$label        = sanitize_text_field( $data['Record Label'] ?? '' );
			$distributor  = sanitize_text_field( $data['Distributor'] ?? '' );
			$buy_link     = esc_url_raw( $data['Buy Now Link'] ?? '' );
			$tags_raw     = sanitize_text_field( $data['Tags'] ?? '' );
			$featured_img = isset( $data['Featured Image URL'] ) ? esc_url_raw( $data['Featured Image URL'] ) : null;
			$audio_url    = isset( $data['Audio Preview URL'] ) ? esc_url_raw( $data['Audio Preview URL'] ) : null;
			$about_raw    = isset( $data['About'] ) ? wp_kses_post( $data['About'] ) : null;

			// Skip empty trailing rows.
			if ( empty( $title ) && empty( $upc ) ) {
				continue;
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
				/* translators: %1$d: CSV row number. %2$s: Error message. */
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
			if ( ! isset( $pending_sync_items ) ) {
				$pending_sync_items = array();
			}
			if ( ! empty( $artist_post_id ) && ! isset( $broadcasted_artists[ $artist_post_id ] ) ) {
				$pending_sync_items[ 'artist:' . $artist_post_id ] = array(
					'id'        => (int) $artist_post_id,
					'post_type' => 'artist',
				);
				$broadcasted_artists[ $artist_post_id ]            = true;
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
				/* translators: %d: CSV row number. */
				$errors[] = sprintf( __( 'Row %d: could not save the release.', 'mds-release-manager' ), $row_number );
				\MDS\ReleaseManager\Core\ReleaseModel::release_upc_lock( $upc );
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
			update_post_meta( $release_post_id, 'streaming_links', wp_slash( $streaming_json ) );

			$result = wp_set_post_terms( $release_post_id, array( $format_id ), 'release_type', false );
			if ( is_wp_error( $result ) ) {
				/* translators: %1$d: CSV row number. %2$s: Error message. */
				$errors[] = sprintf( __( 'Row %1$d: %2$s', 'mds-release-manager' ), $row_number, $result->get_error_message() );
				\MDS\ReleaseManager\Core\ReleaseModel::release_upc_lock( $upc );
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

			\MDS\ReleaseManager\Core\ReleaseModel::release_upc_lock( $upc );
			$pending_sync_items[ 'release:' . $release_post_id ] = array(
				'id'        => (int) $release_post_id,
				'post_type' => 'release',
			);

			if ( count( $pending_sync_items ) >= 25 ) {
				$batch_results = \MDS\ReleaseManager\Core\Sync::broadcast_batch_to_spokes( array_values( $pending_sync_items ) );
				foreach ( $batch_results as $sync_key => $sync_res ) {
					if ( is_wp_error( $sync_res ) ) {
						/* translators: %1$s: Record key. %2$s: Error message. */
						$errors[] = sprintf( __( '%1$s saved but synchronization failed: %2$s', 'mds-release-manager' ), $sync_key, $sync_res->get_error_message() );
					}
				}
				$pending_sync_items = array();
			}

			if ( $is_update ) {
				++$updated;
			} else {
				++$imported;
			}
		}

		if ( ! empty( $pending_sync_items ) ) {
			$batch_results = \MDS\ReleaseManager\Core\Sync::broadcast_batch_to_spokes( array_values( $pending_sync_items ) );
			foreach ( $batch_results as $sync_key => $sync_res ) {
				if ( is_wp_error( $sync_res ) ) {
					/* translators: %1$s: Record key. %2$s: Error message. */
					$errors[] = sprintf( __( '%1$s saved but synchronization failed: %2$s', 'mds-release-manager' ), $sync_key, $sync_res->get_error_message() );
				}
			}
		}

		if ( ! $headers ) {
			self::csv_redirect_with_message( 'mds_csv_missing_header', __( 'The CSV must include UPC/EAN and Release Format columns.', 'mds-release-manager' ), 'error' );
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
	public static function csv_page(): void {
		settings_errors( 'mds_csv_msg' );

		$args = array(
			'admin_url'   => admin_url( 'admin-post.php' ),
			'nonce_field' => wp_nonce_field( 'mds_csv_action', 'mds_csv_nonce', true, false ),
		);

    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The template escapes the URL and emits the nonce field.
		echo \MDS\ReleaseManager\Core\TemplateLoader::render_admin_view( 'csv-page.php', $args );
	}
}

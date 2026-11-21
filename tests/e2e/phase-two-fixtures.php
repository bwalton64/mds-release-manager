<?php
/**
 * Disposable data and direct integration checks for Phase 2 E2E tests.
 *
 * @package MDS_Release_Manager
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

$check = static function ( $result ) {
	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( $result->get_error_message() );
	}
	if ( ! $result ) {
		throw new RuntimeException( 'WordPress did not create or remove the requested fixture.' );
	}
	return $result;
};

$action = $args[0] ?? '';

if ( 'sync-seed' === $action ) {
	$missing     = new stdClass();
	$previous_job = get_option( 'mds_rm_network_sync_job', $missing );
	if ( is_array( $previous_job ) && 'running' === ( $previous_job['status'] ?? '' ) ) {
		WP_CLI::error( 'A network synchronization is already active; refusing to replace its state.' );
	}

	$token = str_replace( '-', '', wp_generate_uuid4() );
	$state = array(
		'posts'        => array( 'artist' => array(), 'release' => array() ),
		'previous_job' => array(
			'exists' => $missing !== $previous_job,
			'value'  => $missing !== $previous_job ? $previous_job : null,
		),
	);

	try {
		foreach ( array( 'artist', 'release' ) as $post_type ) {
			for ( $index = 0; $index < 11; ++$index ) {
				$state['posts'][ $post_type ][] = $check(
					wp_insert_post(
						array(
							'post_type'   => $post_type,
							'post_status' => 'draft',
							'post_title'  => sprintf( 'Phase Two %1$s %2$s %3$d', ucfirst( $post_type ), $token, $index ),
						),
						true
					)
				);
			}
		}

		echo 'MDS_RM_TEST_JSON:' . wp_json_encode( $state );
	} catch ( Throwable $error ) {
		foreach ( $state['posts'] as $post_ids ) {
			foreach ( $post_ids as $post_id ) {
				wp_delete_post( $post_id, true );
			}
		}
		WP_CLI::error( $error->getMessage() );
	}
	return;
}

if ( 'sync-batch' === $action ) {
	$job_id = getenv( 'MDS_RM_SYNC_TEST_JOB_ID' );
	$result = MDS_RM_Sync::run_network_sync_batch( is_string( $job_id ) ? $job_id : '' );
	if ( is_wp_error( $result ) ) {
		WP_CLI::error( $result->get_error_message() );
	}
	echo 'MDS_RM_TEST_JSON:' . wp_json_encode( $result );
	return;
}

if ( 'sync-cleanup' === $action ) {
	$state = json_decode( getenv( 'MDS_RM_SYNC_TEST_STATE' ), true, 512, JSON_THROW_ON_ERROR );
	foreach ( $state['posts'] as $post_ids ) {
		foreach ( $post_ids as $post_id ) {
			$check( wp_delete_post( (int) $post_id, true ) );
		}
	}

	$job_id      = getenv( 'MDS_RM_SYNC_TEST_JOB_ID' );
	$current_job = get_option( 'mds_rm_network_sync_job' );
	if ( $job_id && is_array( $current_job ) && hash_equals( $job_id, (string) ( $current_job['job_id'] ?? '' ) ) ) {
		if ( $state['previous_job']['exists'] ) {
			update_option( 'mds_rm_network_sync_job', $state['previous_job']['value'], false );
		} else {
			delete_option( 'mds_rm_network_sync_job' );
		}
	}
	return;
}

if ( 'export-seed' === $action ) {
	$term = get_term_by( 'name', 'Album', 'release_format' );
	if ( ! $term ) {
		WP_CLI::error( 'The Album release format was not registered.' );
	}

	$token = str_replace( '-', '', wp_generate_uuid4() );
	$state = array(
		'marker'   => $token,
		'releases' => array(),
	);
	try {
		$base_upc = random_int( 10000000000, 98999999998 );
		for ( $index = 0; $index < 101; ++$index ) {
			$release_id = $check(
				wp_insert_post(
					array(
						'post_type'    => 'release',
						'post_status'  => 'draft',
						'post_title'   => sprintf( 'Phase Two Export %1$s %2$03d', $token, $index ),
						'post_content' => '',
					),
					true
				)
			);
			$state['releases'][] = $release_id;
			update_post_meta( $release_id, 'upc_ean', (string) ( $base_upc + $index ) );
			$check( wp_set_post_terms( $release_id, array( (int) $term->term_id ), 'release_format' ) );
		}

		echo 'MDS_RM_TEST_JSON:' . wp_json_encode( $state );
	} catch ( Throwable $error ) {
		foreach ( $state['releases'] as $release_id ) {
			wp_delete_post( $release_id, true );
		}
		WP_CLI::error( $error->getMessage() );
	}
	return;
}

if ( 'export-cleanup' === $action ) {
	$state = json_decode( getenv( 'MDS_RM_EXPORT_TEST_STATE' ), true, 512, JSON_THROW_ON_ERROR );
	foreach ( $state['releases'] as $release_id ) {
		$check( wp_delete_post( (int) $release_id, true ) );
	}
	return;
}

if ( 'check-upc' === $action ) {
	$previous_migration = get_option( 'mds_rm_normalized_upc_migration_complete', new stdClass() );
	$had_migration      = ! ( $previous_migration instanceof stdClass );
	$upc                = (string) random_int( 10000000000, 99999999999 );
	$legacy_upc         = substr( $upc, 0, 5 ) . '-' . substr( $upc, 5 );
	$post_id            = 0;

	try {
		$term = get_term_by( 'name', 'Album', 'release_format' );
		if ( ! $term ) {
			throw new RuntimeException( 'The Album release format was not registered.' );
		}
		$post_id = $check(
			wp_insert_post(
				array(
					'post_type'   => 'release',
					'post_status' => 'draft',
					'post_title'  => 'Phase Two UPC ' . wp_generate_uuid4(),
				),
				true
			)
		);
		update_post_meta( $post_id, 'upc_ean', $legacy_upc );
		delete_post_meta( $post_id, '_mds_normalized_upc' );
		update_option( 'mds_rm_normalized_upc_migration_complete', '0', false );

		$legacy_result = MDS_RM_Release_Model::validate( 0, $upc, array( (int) $term->term_id ) );
		update_post_meta( $post_id, '_mds_normalized_upc', $upc );
		$indexed_result = MDS_RM_Release_Model::validate( 0, $upc, array( (int) $term->term_id ) );

		echo 'MDS_RM_TEST_JSON:' . wp_json_encode(
			array(
				'legacy_error'  => is_wp_error( $legacy_result ) ? $legacy_result->get_error_code() : '',
				'indexed_error' => is_wp_error( $indexed_result ) ? $indexed_result->get_error_code() : '',
			)
		);
	} finally {
		if ( $post_id ) {
			wp_delete_post( $post_id, true );
		}
		if ( $had_migration ) {
			update_option( 'mds_rm_normalized_upc_migration_complete', $previous_migration, false );
		} else {
			delete_option( 'mds_rm_normalized_upc_migration_complete' );
		}
	}
	return;
}

WP_CLI::error( 'Expected sync-seed, sync-batch, sync-cleanup, or check-upc.' );

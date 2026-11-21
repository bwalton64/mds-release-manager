<?php
/**
 * Disposable data for the Playwright baseline, invoked via wp eval-file.
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

$cleanup = static function ( array $state ) use ( $check ): void {
	require_once ABSPATH . 'wp-admin/includes/ms.php';
	foreach ( array( 'release', 'artist', 'host' ) as $type ) {
		if ( isset( $state[ $type ] ) ) {
			$check( wp_delete_post( $state[ $type ], true ) );
		}
	}
	if ( isset( $state['user'] ) ) {
		$check( wpmu_delete_user( $state['user'] ) );
	}
	foreach ( $state['options'] as $name => $snapshot ) {
		if ( $snapshot['exists'] ) {
			update_option( $name, $snapshot['value'] );
		} else {
			delete_option( $name );
		}
	}
};

if ( 'cleanup' === ( $args[0] ?? '' ) ) {
	$cleanup( json_decode( getenv( 'MDS_RM_FIXTURE_STATE' ), true, 512, JSON_THROW_ON_ERROR ) );
	return;
}
if ( 'seed' !== ( $args[0] ?? '' ) ) {
	WP_CLI::error( 'Expected seed or cleanup.' );
}

$state = array( 'options' => array() );
$missing = new stdClass();
foreach ( array( 'mds_rm_providers', 'mds_rm_audio_players', 'mds_rm_bandcamp_template', 'mds_rm_global_redirect', 'mds_rm_fa_support', 'mds_rm_block_defaults', 'mds_rm_artist_image_cleanup_complete' ) as $name ) {
	$value = get_option( $name, $missing );
	$state['options'][ $name ] = array( 'exists' => $missing !== $value, 'value' => $missing !== $value ? $value : null );
}

try {
	$token    = str_replace( '-', '', wp_generate_uuid4() );
	$username = 'mdssmoke' . $token;
	$password = wp_generate_password( 32, false );
	$label    = 'Smoke Label ' . substr( $token, 0, 8 );
	$title    = 'Smoke Release ' . substr( $token, 0, 8 );
	$spotify  = 'https://open.spotify.com/album/0123456789ABCDEFGHIJKL';
	$term     = get_term_by( 'name', 'Album', 'release_format' );
	$check( $term );
	$state['user'] = $check( wp_insert_user( array( 'user_login' => $username, 'user_pass' => $password, 'role' => 'administrator' ) ) );
	$state['artist'] = $check(
		wp_insert_post( array( 'post_type' => 'artist', 'post_status' => 'publish', 'post_title' => 'Smoke Artist ' . $token, 'post_content' => 'Temporary artist biography.' ), true )
	);
	update_post_meta( $state['artist'], '_mds_artist_uuid', $token );
	$state['release'] = $check(
		wp_insert_post( array( 'post_type' => 'release', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => 'Temporary release description.' ), true )
	);
	update_post_meta( $state['release'], 'artist_id', $token );
	update_post_meta( $state['release'], 'record_label', $label );
	update_post_meta( $state['release'], 'upc_ean', '0' . random_int( 99000000000, 99999999999 ) );
	update_post_meta( $state['release'], 'streaming_links', wp_slash( wp_json_encode( array( 'Spotify' => $spotify ) ) ) );
	$check( wp_set_post_terms( $state['release'], array( (int) $term->term_id ), 'release_format' ) );
	$state['host'] = $check(
		wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Smoke Host ' . $token, 'post_content' => '' ), true )
	);
	update_option( 'mds_rm_global_redirect', '0' );
	update_option( 'mds_rm_fa_support', 'cdn' );
	update_option( 'mds_rm_block_defaults', array() );
	update_option( 'mds_rm_artist_image_cleanup_complete', '1' );

	echo 'MDS_RM_TEST_JSON:' . wp_json_encode( array(
		'state' => $state,
		'username' => $username,
		'password' => $password,
		'label' => $label,
		'title' => $title,
		'spotify' => $spotify,
		'artistUrl' => get_permalink( $state['artist'] ),
		'releaseUrl' => get_permalink( $state['release'] ),
		'hostUrl' => get_permalink( $state['host'] ),
	) );
} catch ( Throwable $error ) {
	$cleanup( $state );
	WP_CLI::error( $error->getMessage() );
}

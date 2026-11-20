<?php
/**
 * Isolated regression for the uninstall handler. Run with php tests\uninstall.php.
 *
 * @package MDS_Release_Manager
 */

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI || defined( 'ABSPATH' ) ) {
	exit( 1 );
}

define( 'WP_UNINSTALL_PLUGIN', true );

function get_site_option( $key, $default ) {
	return $GLOBALS['network_options'][ $key ] ?? $default;
}

function delete_site_option( $key ) {
	unset( $GLOBALS['network_options'][ $key ] );
}

function delete_option( $key ) {
	unset( $GLOBALS['local_options'][ get_current_blog_id() ][ $key ] );
}

function is_multisite() {
	return $GLOBALS['multisite'];
}

function get_current_blog_id() {
	return $GLOBALS['blog_stack'][ count( $GLOBALS['blog_stack'] ) - 1 ];
}

function get_current_network_id() {
	return 1;
}

function get_sites( $args = array() ) {
	$sites = range( 1, 106 );
	if ( isset( $args['network_id'] ) ) {
		// The last site belongs to a different network.
		$sites = array_slice( $sites, 0, 105 );
	}
	$limit = $args['number'] ?? 100;
	if ( $limit ) {
		$sites = array_slice( $sites, 0, $limit );
	}
	return 'ids' === ( $args['fields'] ?? '' ) ? $sites : array_map(
		static fn( $id ) => (object) array( 'blog_id' => $id ),
		$sites
	);
}

function switch_to_blog( $id ) {
	$GLOBALS['blog_stack'][] = $id;
}

function restore_current_blog() {
	array_pop( $GLOBALS['blog_stack'] );
}

function get_post_stati() {
	return array( 'publish' => 'publish', 'trash' => 'trash', 'private' => 'private' );
}

function get_posts( $args ) {
	$posts = array_filter(
		$GLOBALS['posts'][ get_current_blog_id() ],
		static fn( $post ) => in_array( $post->post_type, $args['post_type'], true )
			&& in_array( $post->post_status, 'any' === $args['post_status'] ? array( 'publish', 'private' ) : $args['post_status'], true )
	);
	return 'ids' === ( $args['fields'] ?? '' ) ? array_keys( $posts ) : $posts;
}

function wp_delete_post( $id, $force_delete ) {
	if ( ! $force_delete ) {
		throw new RuntimeException( 'The opt-in wipe did not permanently delete the fixture.' );
	}
	unset( $GLOBALS['posts'][ get_current_blog_id() ][ $id ] );
}

function check_uninstall_fixture( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$site_options = array( 'mds_rm_providers' => array(), 'mds_rm_wc_integrated_sites' => array( 2 ) );
$site_posts   = array(
	1 => (object) array( 'ID' => 1, 'post_type' => 'release', 'post_status' => 'trash' ),
	2 => (object) array( 'ID' => 2, 'post_type' => 'artist', 'post_status' => 'trash' ),
	3 => (object) array( 'ID' => 3, 'post_type' => 'release', 'post_status' => 'publish' ),
	4 => (object) array( 'ID' => 4, 'post_type' => 'artist', 'post_status' => 'private' ),
	5 => (object) array( 'ID' => 5, 'post_type' => 'post', 'post_status' => 'trash' ),
	6 => (object) array( 'ID' => 6, 'post_type' => 'attachment', 'post_status' => 'publish' ),
);

foreach ( array( array( true, false ), array( false, true ), array( true, true ) ) as list( $multisite, $wipe ) ) {
	$blog_stack      = array( 1 );
	$network_options = array(
		'mds_rm_wipe_on_uninstall'    => $wipe ? '1' : '0',
		'mds_rm_master_blog_id'      => 1,
		'mds_rm_db_templates'        => array(),
		'mds_rm_wc_integrated_sites' => array( 2 ),
	);
	if ( $multisite ) {
		$network_options['spoke_sites'] = array( 105 );
	}
	$network_before = $network_options;
	$local_options  = array_fill( 1, 106, $site_options );
	$posts          = array_fill( 1, 106, $site_posts );

	require dirname( __DIR__ ) . DIRECTORY_SEPARATOR . 'uninstall.php';

	foreach ( range( 1, 106 ) as $id ) {
		$in_wipe = $wipe && ( 1 === $id || ( $multisite && $id <= 105 ) );
		check_uninstall_fixture( array_keys( $posts[ $id ] ) === ( $in_wipe ? array( 5, 6 ) : array_keys( $site_posts ) ), "Unexpected post deletion scope on site {$id}." );
		$expected_options = $site_options;
		if ( 1 === $id || $in_wipe ) {
			unset( $expected_options['mds_rm_providers'] );
		}
		check_uninstall_fixture( $expected_options === $local_options[ $id ], "Unexpected option deletion scope on site {$id}." );
	}
	check_uninstall_fixture( $network_options === ( $wipe ? array( 'mds_rm_wc_integrated_sites' => array( 2 ) ) : $network_before ), 'Unexpected network option deletion scope.' );
	check_uninstall_fixture( array( 1 ) === $blog_stack, 'The original blog context was not restored.' );
}

echo "Uninstall fixtures passed: default, opt-in single-site, and opt-in 105-site network.\n";

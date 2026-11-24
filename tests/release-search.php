<?php
/**
 * Standalone checks for the shared Release catalog search.
 *
 * Run with: php tests/release-search.php
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

class WP_Post {
	public $ID;
	public $post_title;
	public function __construct( int $id, string $title ) {
		$this->ID         = $id;
		$this->post_title = $title;
	}
}
function get_posts( $args ) {
	if ( 'artist' === $args['post_type'] ) {
		if ( empty( $args['meta_query'] ) ) {
			return $GLOBALS['artists'];
		}
		$wanted = $args['meta_query'][0]['value'];
		return array_values(
			array_filter(
				$GLOBALS['artists'],
				static function ( $artist ) use ( $wanted ) {
					return in_array( $GLOBALS['meta'][ $artist->ID ]['_mds_artist_uuid'] ?? '', $wanted, true );
				}
			)
		);
	}
	return $GLOBALS['releases'];
}
function get_post_meta( $post_id, $key, $single ) {
	return $GLOBALS['meta'][ $post_id ][ $key ] ?? '';
}
function get_the_title( $post ) {
	return $post->post_title;
}

require __DIR__ . '/../vendor/autoload.php';
use MDS\ReleaseManager\Core\ReleaseSearch;

function check_search( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function ids( array $posts ): array {
	return array_map( static fn( $post ) => $post->ID, $posts );
}

$GLOBALS['releases'] = array(
	new WP_Post( 94, 'America' ),
	new WP_Post( 50, 'Solo Song' ),
	new WP_Post( 92, 'What the .... (Gold)' ),
);
$GLOBALS['artists']  = array(
	new WP_Post( 70, 'Brian Walton' ),
	new WP_Post( 71, 'Other Artist' ),
);
$GLOBALS['meta'] = array(
	92 => array( 'upc_ean' => '198712394852', 'artist_id' => 'uuid-a' ),
	94 => array( 'upc_ean' => '00123', 'artist_id' => 'uuid-a' ),
	50 => array( 'upc_ean' => '', 'artist_id' => 'uuid-b' ),
	70 => array( '_mds_artist_uuid' => 'uuid-a' ),
	71 => array( '_mds_artist_uuid' => 'uuid-b' ),
);

check_search( array( 94, 50, 92 ) === ids( ReleaseSearch::query( '' ) ), 'An empty term must return the catalog.' );
check_search( array( 92 ) === ids( ReleaseSearch::query( 'What the' ) ), 'Title terms must match releases.' );
check_search( array( 94, 92 ) === ids( ReleaseSearch::query( 'brian walton' ) ), 'Artist-name terms must match that Artist\'s releases case-insensitively.' );
check_search( array( 92 ) === ids( ReleaseSearch::query( '198712394852' ) ), 'An all-digit term must match the exact UPC/EAN.' );
check_search( array( 94 ) === ids( ReleaseSearch::query( '00123' ) ), 'Leading zeroes must stay significant in UPC/EAN matching.' );
check_search( array() === ids( ReleaseSearch::query( '123' ) ), 'Digits without a UPC or text match must return nothing.' );

$page_one = ReleaseSearch::paginate( $GLOBALS['releases'], 1, 2 );
$page_two = ReleaseSearch::paginate( $GLOBALS['releases'], 2, 2 );
check_search( 2 === count( $page_one['posts'] ) && 2 === $page_one['pages'], 'Page one must hold the page size and total pages.' );
check_search( array( 92 ) === ids( $page_two['posts'] ) && 2 === $page_two['pages'], 'Page two must hold the remainder.' );

$described = ReleaseSearch::describe( array( $GLOBALS['releases'][2], $GLOBALS['releases'][1] ) );
check_search(
	array( 'id' => 92, 'title' => 'What the .... (Gold)', 'artist' => 'Brian Walton', 'upc' => '198712394852' ) === $described[0],
	'Described records must resolve the Artist name and UPC.'
);
check_search( 'Other Artist' === $described[1]['artist'] && '' === $described[1]['upc'], 'Records without a UPC must keep an empty string.' );

function apply_filters( $hook, $item, $post ) {
	if ( ! empty( $GLOBALS['enable_picker_filter'] ) && 'mds_rm_release_picker_item' === $hook ) {
		$item['bandcamp_album_id'] = 'bc-' . $post->ID;
		$item['id']                = 999999; // Core identity fields must remain authoritative.
	}
	if ( ! empty( $GLOBALS['enable_picker_filter'] ) && 'mds_rm_artist_picker_item' === $hook ) {
		$item['artist_url'] = 'https://artist-' . $post->ID . '.bandcamp.com';
		$item['subdomain']  = 'artist-' . $post->ID;
		$item['id']         = 888888;
	}
	return $item;
}

$GLOBALS['enable_picker_filter'] = true;
$catalog                         = ReleaseSearch::editor_catalog();
check_search( false === $catalog['truncated'] && 3 === count( $catalog['items'] ), 'Editor catalog must include all non-truncated items.' );
check_search( 'bc-94' === $catalog['items'][0]['bandcamp_album_id'] && 94 === $catalog['items'][0]['id'], 'Extension picker filter must enrich items without overwriting core identity keys.' );
check_search( false === $catalog['artistsTruncated'] && 2 === count( $catalog['artists'] ), 'Editor catalog must include published Artists.' );
check_search( 'https://artist-70.bandcamp.com' === $catalog['artists'][0]['artist_url'] && 70 === $catalog['artists'][0]['id'], 'Artist picker filter must enrich artists without overwriting core identity keys.' );
check_search( array( 70 ) === ids( ReleaseSearch::query_artists( 'brian' ) ), 'Artist search must match Artist titles case-insensitively.' );

echo "Release search checks passed.\n";

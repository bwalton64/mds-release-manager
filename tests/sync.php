<?php
/**
 * Standalone checks for the Hub-and-Spoke Sync transport layer, Option B targeting,
 * lifecycle tombstones, and SyncQueue driver selection.
 *
 * Run with: php tests/sync.php
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

class WP_Post {
	public $ID;
	public $post_type;
	public $post_title    = 'Test Record';
	public $post_content  = '';
	public $post_excerpt  = '';
	public $post_status   = 'publish';
	public $post_name     = 'test-record';
	public $post_date     = '2026-01-01 00:00:00';
	public $post_date_gmt = '2026-01-01 00:00:00';
	public function __construct( int $id, string $post_type ) {
		$this->ID        = $id;
		$this->post_type = $post_type;
	}
}
class WP_Error {
	public $code;
	public $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_message() {
		return $this->message;
	}
	public function get_error_code() {
		return $this->code;
	}
}

$GLOBALS['site_options'] = array(
	'mds_rm_master_blog_id' => 1,
	'mds_rm_spoke_sites'    => array( 2, 3, 4 ), // 2=WC store A, 3=WC store B, 4=Non-WC editorial spoke.
);
$GLOBALS['filters']      = array();
$GLOBALS['posts']        = array(
	10 => new WP_Post( 10, 'artist' ),
	20 => new WP_Post( 20, 'release' ),
);
$GLOBALS['meta']         = array(
	10 => array( '_mds_artist_uuid' => array( 'uuid-artist-10' ) ),
	20 => array( 'upc_ean' => array( '001234567890' ) ),
);
$GLOBALS['cron_events']  = array();

function is_multisite() {
	return true;
}
function get_current_blog_id() {
	return 1;
}
function get_main_site_id() {
	return 1;
}
function get_site_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['site_options'] ) ? $GLOBALS['site_options'][ $key ] : $default;
}
function get_option( $key, $default = false ) {
	return $default;
}
function get_post( $id ) {
	return $GLOBALS['posts'][ $id ] ?? null;
}
function get_post_meta( $post_id, $key = '', $single = false ) {
	if ( '' === $key ) {
		return $GLOBALS['meta'][ $post_id ] ?? array();
	}
	$vals = $GLOBALS['meta'][ $post_id ][ $key ] ?? array();
	return $single ? ( $vals[0] ?? '' ) : $vals;
}
function get_object_taxonomies( $post_type ) {
	return array();
}
function get_post_thumbnail_id( $post_id ) {
	return 0;
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function __( $text ) {
	return $text;
}
function wp_json_encode( $data ) {
	return json_encode( $data );
}
function apply_filters( $hook, $value, ...$args ) {
	if ( isset( $GLOBALS['filters'][ $hook ] ) ) {
		return ( $GLOBALS['filters'][ $hook ] )( $value, ...$args );
	}
	return $value;
}
function add_action( $hook, $cb, $prio = 10, $args = 1 ) {}
function wp_next_scheduled( $hook, $args = array() ) {
	return false;
}
function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
	$GLOBALS['cron_events'][] = array( $hook, $args );
	return true;
}

require __DIR__ . '/../vendor/autoload.php';

use MDS\ReleaseManager\Core\Sync;
use MDS\ReleaseManager\Core\Sync\SyncQueue;
use MDS\ReleaseManager\Core\Sync\SyncTransportInterface;

function assert_sync( bool $cond, string $msg ): void {
	if ( ! $cond ) {
		throw new RuntimeException( $msg );
	}
}

// 1. Base spoke resolution reads `mds_rm_spoke_sites` (excluding hub).
assert_sync( array( 2, 3, 4 ) === Sync::base_spoke_sites(), 'Base spokes must load from mds_rm_spoke_sites.' );

// 2. Option B scoping: WooCommerce strips unassigned WC stores (2, 3) and adds only assigned store (2), preserving non-WC spoke (4).
$GLOBALS['filters']['mds_rm_sync_target_sites'] = static function ( array $targets, int $post_id, string $post_type ): array {
	$all_wc   = array( 2, 3 );
	$non_wc   = array_values( array_diff( $targets, $all_wc ) );
	$assigned = array( 2 );
	return array_values( array_unique( array_merge( $non_wc, $assigned ) ) );
};
assert_sync( array( 4, 2 ) === Sync::resolve_target_sites( 10, 'artist' ), 'Option B must preserve non-WC spoke (4) and include only assigned WC store (2).' );

// 3. Pluggable SyncTransportInterface receives JSON-serializable payloads and lifecycle tombstones.
$pushed     = array();
$tombstoned = array();
$fake_transport = new class( $pushed, $tombstoned ) implements SyncTransportInterface {
	private $pushed;
	private $tombstoned;
	public function __construct( &$pushed, &$tombstoned ) {
		$this->pushed     = &$pushed;
		$this->tombstoned = &$tombstoned;
	}
	public function id(): string {
		return 'fake';
	}
	public function supports( array $target ): bool {
		return 'multisite' === ( $target['type'] ?? '' );
	}
	public function push_batch( array $target, array $entries ): array {
		$this->pushed[ $target['blog_id'] ] = $entries;
		$res                                = array();
		foreach ( $entries as $e ) {
			$res[ $e['key'] ] = null;
		}
		return $res;
	}
	public function delete_batch( array $target, array $tombstones ): array {
		$this->tombstoned[ $target['blog_id'] ] = $tombstones;
		return array();
	}
};

$GLOBALS['filters']['mds_rm_register_sync_transports'] = static fn(): array => array( $fake_transport );

$batch_res = Sync::broadcast_batch_to_spokes( array( array( 'id' => 10, 'post_type' => 'artist' ) ) );
assert_sync( true === ( $batch_res['artist:10'] ?? false ), 'Batch broadcast must succeed through custom transport.' );
assert_sync( array( 4, 2 ) === array_keys( $pushed ), 'Broadcast must target Option B spokes (4 and 2) and skip unassigned store 3.' );
$payload = $pushed[4][0]['payload'];
assert_sync( is_array( $payload['post'] ) && '2.1.0' === $payload['schema_version'], 'Payload must be JSON-serializable flat array with schema_version.' );
assert_sync( is_string( json_encode( $payload ) ), 'Payload must encode cleanly to JSON for future remote REST transports.' );

// 4. Lifecycle trash tombstone mirrors onto spokes 4 and 2.
Sync::on_hub_trash_post( 10 );
assert_sync( 'trash' === ( $tombstoned[4][0]['action'] ?? '' ) && 'uuid-artist-10' === ( $tombstoned[2][0]['uuid'] ?? '' ), 'Trash lifecycle event must dispatch tombstone to target spokes.' );

// 5. SyncQueue selects WP-Cron fallback when Action Scheduler is absent.
assert_sync( 'wp_cron' === SyncQueue::driver(), 'SyncQueue must fall back to wp_cron when Action Scheduler is unavailable.' );
$enq = SyncQueue::enqueue( 'job-abc' );
assert_sync( true === $enq['queued'] && 1 === count( $GLOBALS['cron_events'] ), 'SyncQueue must schedule a single WP-Cron event.' );

echo "Hub-and-Spoke sync checks passed.\n";

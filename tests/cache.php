<?php
/**
 * Standalone checks for MDS_RM_Cache (adapter detection, mode validation,
 * post_save toggle, coalesced shutdown queue, and companion purge action).
 *
 * Run with: php tests/cache.php
 */

declare(strict_types=1);
define( 'ABSPATH', __DIR__ );

class WP_Error {
	public $code;
	public function __construct( $code, $_message = '', $_data = array() ) {
		$this->code = $code;
	}
}
function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}
function __( $text, $domain = '' ) {
	return $text;
}
function sanitize_key( $value ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}
function absint( $value ): int {
	return abs( (int) $value );
}
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['options'] ) ? $GLOBALS['options'][ $key ] : $default;
}
function apply_filters( $hook, $value ) {
	return isset( $GLOBALS['filters'][ $hook ] ) ? call_user_func( $GLOBALS['filters'][ $hook ], $value ) : $value;
}
function add_action( $hook, $cb, $priority = 10, $args = 1 ): void {
	$GLOBALS['actions'][ $hook ][] = $cb;
}
function do_action( $hook, ...$args ): void {
	$GLOBALS['fired'][] = array( $hook, $args );
	foreach ( $GLOBALS['actions'][ $hook ] ?? array() as $cb ) {
		call_user_func_array( $cb, $args );
	}
}
function has_action( $hook ): bool {
	return ! empty( $GLOBALS['actions'][ $hook ] );
}
function get_current_blog_id(): int {
	return 1;
}
function is_multisite(): bool {
	return false;
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../includes/legacy-class-aliases.php';

function assert_cache( bool $cond, string $msg ): void {
	if ( ! $cond ) {
		throw new RuntimeException( $msg );
	}
}

$GLOBALS['options'] = array();
$GLOBALS['actions'] = array();
$GLOBALS['fired']   = array();
$GLOBALS['filters'] = array();

$purge_calls = array();
$GLOBALS['filters']['mds_rm_cache_adapters'] = static function ( array $adapters ) use ( &$purge_calls ): array {
	$adapters['mock_cache'] = array(
		'slug'         => 'mock_cache',
		'label'        => 'Mock Cache Pro',
		'tier'         => 'Paid',
		'detect'       => static fn(): bool => true,
		'default_mode' => 'all',
		'modes'        => array(
			'all'      => 'Purge All',
			'assets'   => 'Purge CSS & JS Only',
			'disabled' => 'Disabled',
		),
		'purge'        => static function ( string $mode, int $blog_id ) use ( &$purge_calls ): bool {
			$purge_calls[] = array( $mode, $blog_id );
			return true;
		},
	);
	return $adapters;
};

// 1. Validation accepts valid modes and rejects invalid modes.
$valid = MDS_RM_Cache::validate_settings(
	array(
		'purge_on_post_save' => false,
		'plugins'            => array( 'mock_cache' => 'assets' ),
	)
);
assert_cache( ! is_wp_error( $valid ) && false === $valid['purge_on_post_save'] && 'assets' === $valid['plugins']['mock_cache'], 'Valid cache settings must pass validation.' );

$invalid = MDS_RM_Cache::validate_settings(
	array(
		'purge_on_post_save' => true,
		'plugins'            => array( 'mock_cache' => 'nuclear' ),
	)
);
assert_cache( is_wp_error( $invalid ) && 'mds_rm_invalid_cache_settings' === $invalid->code, 'Invalid purge mode must be rejected.' );

// 2. Respects purge_on_post_save = false while still purging settings/companion contexts.
$GLOBALS['options'][ MDS_RM_Cache::OPTION_NAME ] = $valid;
$res = MDS_RM_Cache::purge( 'post_save', array( 'immediate' => true ) );
assert_cache( empty( $res['purged'] ) && in_array( 'post_save_disabled', $res['skipped'], true ), 'post_save must skip when purge_on_post_save is disabled.' );

$res = MDS_RM_Cache::purge( 'settings', array( 'immediate' => true ) );
assert_cache( array( 'Mock Cache Pro' ) === $res['purged'], 'settings context must purge using configured mode.' );
assert_cache( array( array( 'assets', 1 ) ) === $purge_calls, 'Adapter must receive configured assets mode.' );

// 3. Coalescing: 5 queued purges in one request execute only once at shutdown.
$purge_calls = array();
MDS_RM_Cache::register_hooks();
for ( $i = 0; $i < 5; $i++ ) {
	do_action( 'mds_rm_purge_cache', 'bandcamp_sync' );
}
assert_cache( empty( $purge_calls ), 'Queued purges must wait until shutdown.' );
MDS_RM_Cache::flush_queued();
assert_cache( 1 === count( $purge_calls ), 'Five queued purges for the same site must coalesce into one flush.' );

// 4. Disabled adapter is skipped cleanly.
$purge_calls = array();
$GLOBALS['options'][ MDS_RM_Cache::OPTION_NAME ] = array(
	'purge_on_post_save' => true,
	'plugins'            => array( 'mock_cache' => 'disabled' ),
);
$res = MDS_RM_Cache::purge( 'manual', array( 'immediate' => true, 'force' => true ) );
assert_cache( empty( $res['purged'] ) && in_array( 'mock_cache', $res['skipped'], true ), 'Disabled adapter must be skipped.' );

echo "Cache manager checks passed.\n";

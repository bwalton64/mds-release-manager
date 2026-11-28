<?php
/**
 * Standalone unit tests for MDS Release Manager Core GatewayBridge.
 *
 * Run via: php tests/gateway-bridge.php
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../' );

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? '';
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
	}
}
if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( string $str ): string {
		return rtrim( $str, '/\\' );
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $cb, int $priority = 10, int $args = 1 ): bool {
		unset( $hook, $cb, $priority, $args );
		return true;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		unset( $domain );
		return $text;
	}
}

require_once dirname( __DIR__ ) . '/src/Core/GatewayBridge.php';

use MDS\ReleaseManager\Core\GatewayBridge;

$assertions = 0;

/**
 * Simple assertion helper.
 *
 * @param bool   $cond Condition.
 * @param string $msg  Description.
 * @return void
 */
function mds_rm_gw_assert( bool $cond, string $msg ): void {
	global $assertions;
	++$assertions;
	if ( ! $cond ) {
		fwrite( STDERR, "FAIL: {$msg}\n" );
		exit( 1 );
	}
}

// 1. Dormant-by-default Free Core compliance.
mds_rm_gw_assert( ! GatewayBridge::has_registered_plugins(), 'GatewayBridge starts with zero registered plugins.' );
$tabs_before = GatewayBridge::maybe_register_settings_tab( array( 'general' => array() ) );
mds_rm_gw_assert( ! isset( $tabs_before['gateway'] ), 'License & Cloud tab stays hidden on standalone Free Core.' );

// 2. Environment URL auto-detection.
mds_rm_gw_assert( 'http://api.bgw-dev.local' === GatewayBridge::detect_api_base_url( 'http://bgw-dev.local' ), 'LocalWP auto-detects api.bgw-dev.local.' );
mds_rm_gw_assert( 'https://mustydungeon.com' === GatewayBridge::detect_api_base_url( 'https://staging.mustydungeon.com' ), 'Staging auto-detects mustydungeon.com.' );
mds_rm_gw_assert( 'https://api.mustydungeon.com' === GatewayBridge::detect_api_base_url( 'https://myband.com' ), 'Production customer site auto-detects api.mustydungeon.com.' );

// 3. Companion registration activates neutral License & Cloud tab.
$ok = GatewayBridge::register_plugin(
	array(
		'slug'     => 'mds-promote',
		'basename' => 'mds-promote/mds-promote.php',
		'version'  => '1.0.0',
		'name'     => 'MDS Promote',
	)
);
mds_rm_gw_assert( $ok, 'Companion plugin registers cleanly.' );
mds_rm_gw_assert( GatewayBridge::has_registered_plugins(), 'GatewayBridge detects registered companion.' );
$tabs_after = GatewayBridge::maybe_register_settings_tab( array( 'general' => array() ) );
mds_rm_gw_assert( isset( $tabs_after['gateway'] ), 'License & Cloud tab appears once a companion registers.' );

echo "OK: {$assertions} assertions passed.\n";

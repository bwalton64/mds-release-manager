<?php
/**
 * Standalone unit tests for the official MDS BrandIcon helper.
 *
 * Run: php tests/brand-icon.php
 *
 * @package   MDS\ReleaseManager
 * @copyright 2026 Musty Dungeon Studios (Walton International Business Group LLC)
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'MDS_RM_PATH' ) ) {
	define( 'MDS_RM_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'MDS_RM_URL' ) ) {
	define( 'MDS_RM_URL', 'https://mustydungeon.com/wp-content/plugins/mds-release-manager/' );
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}
}

require_once dirname( __DIR__ ) . '/src/Core/BrandIcon.php';

use MDS\ReleaseManager\Core\BrandIcon;

$svg   = BrandIcon::svg();
$uri   = BrandIcon::data_uri();
$icons = BrandIcon::updater_icons( 'mds-release-manager' );

if ( ! str_contains( $svg, '<svg ' ) || ! str_contains( $svg, 'fill="currentColor"' ) || ! str_contains( $svg, 'aria-hidden="true"' ) ) {
	fwrite( STDERR, "FAIL: BrandIcon::svg() did not produce expected themed accessible SVG.\n" );
	exit( 1 );
}
if ( ! str_starts_with( $uri, 'data:image/svg+xml;base64,' ) ) {
	fwrite( STDERR, "FAIL: BrandIcon::data_uri() did not return a base64 SVG data URI.\n" );
	exit( 1 );
}
if ( empty( $icons['svg'] ) || empty( $icons['1x'] ) || empty( $icons['2x'] ) || empty( $icons['default'] ) ) {
	fwrite( STDERR, "FAIL: BrandIcon::updater_icons() missing expected svg/1x/2x/default keys.\n" );
	exit( 1 );
}

echo sprintf( "PASS: BrandIcon assertions succeeded (data_uri=%d bytes, icons=%s).\n", strlen( $uri ), implode( ',', array_keys( $icons ) ) );

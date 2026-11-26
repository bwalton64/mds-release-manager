<?php
/**
 * Standalone unit tests for MDS\ReleaseManager\Core\CatalogQuery.
 *
 * Run with: php tests/catalog-explorer.php
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $str ): string {
		return trim( wp_strip_all_tags( $str ) );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $text ): string {
		return strip_tags( $text );
	}
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( string $title ): string {
		$slug = strtolower( trim( $title ) );
		return (string) preg_replace( '/[^a-z0-9-]+/', '-', $slug );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( mixed $maybeint ): int {
		return abs( (int) $maybeint );
	}
}

require_once __DIR__ . '/../src/Core/CatalogQuery.php';

use MDS\ReleaseManager\Core\CatalogQuery;

$failures = array();
$assert   = static function ( bool $condition, string $label ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $label;
	}
};

// 1. Default normalization.
$defaults = CatalogQuery::normalize_args( array() );
$assert( '' === $defaults['search'], 'Default search is empty' );
$assert( 'date_desc' === $defaults['sort'], 'Default sort is date_desc' );
$assert( 1 === $defaults['page'], 'Default page is 1' );
$assert( 12 === $defaults['per_page'], 'Default per_page is 12' );
$assert( 0 === $defaults['year'], 'Default year is 0' );

// 2. Alias handling (`q` -> `search`, `paged` -> `page`) and bounds clamping.
$clamped = CatalogQuery::normalize_args(
	array(
		'q'        => '  <b>What The Gold</b>  ',
		'type'     => 'Album',
		'year'     => '2025',
		'tag'      => 'Indie Rock',
		'label'    => 'Musty Dungeon',
		'format'   => 'Vinyl LP',
		'sort'     => 'invalid_sort_injection',
		'paged'    => '3',
		'per_page' => '999',
	)
);
$assert( 'What The Gold' === $clamped['search'], 'q alias strips HTML and trims whitespace' );
$assert( 'album' === $clamped['type'], 'type is slug-sanitized' );
$assert( 2025 === $clamped['year'], 'valid 4-digit year preserved' );
$assert( 'indie-rock' === $clamped['tag'], 'tag is slug-sanitized' );
$assert( 'Musty Dungeon' === $clamped['label'], 'record label preserved' );
$assert( 'vinyl-lp' === $clamped['format'], 'media format is slug-sanitized' );
$assert( 'date_desc' === $clamped['sort'], 'invalid sort falls back to date_desc' );
$assert( 3 === $clamped['page'], 'paged alias maps to page' );
$assert( 48 === $clamped['per_page'], 'per_page is capped at 48' );

// 3. Out-of-range year rejected.
$bad_year = CatalogQuery::normalize_args( array( 'year' => 1776 ) );
$assert( 0 === $bad_year['year'], 'Out-of-range year resets to 0' );

// 4. Valid sorts accepted.
foreach ( CatalogQuery::VALID_SORTS as $valid_sort ) {
	$res = CatalogQuery::normalize_args( array( 'sort' => $valid_sort ) );
	$assert( $valid_sort === $res['sort'], "Sort '{$valid_sort}' accepted" );
}

// 5. Artist UUID passthrough.
$assert( 'abc-123-uuid' === CatalogQuery::resolve_artist_uuid( ' abc-123-uuid ' ), 'UUID string passes through trimmed' );
$assert( '' === CatalogQuery::resolve_artist_uuid( '' ), 'Empty artist returns empty string' );

// 6. Query fallback outside WordPress returns clean empty structure.
$empty_query = CatalogQuery::query( array( 'q' => 'test', 'page' => 2 ) );
$assert( array() === $empty_query['items'] && 0 === $empty_query['total'] && 2 === $empty_query['page'], 'Standalone query returns structured empty response' );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, "FAILED:\n - " . implode( "\n - ", $failures ) . "\n" );
	exit( 1 );
}

echo "OK: all CatalogQuery assertions passed.\n";

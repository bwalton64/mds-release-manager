<?php
/**
 * Standalone unit tests for MDS\ReleaseManager\Admin\ImportFieldMapper & ImportExportHandler.
 *
 * Run with: php tests/import-export.php
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

// Minimal WordPress stubs for isolated unit testing.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public string $code;
		public string $message;
		public mixed $data;

		public function __construct( string $code = '', string $message = '', mixed $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

$GLOBALS['mds_test_options'] = array(
	'mds_rm_providers' => array(
		array( 'name' => 'Bandcamp' ),
		array( 'name' => 'Tidal' ),
	),
);
$GLOBALS['mds_test_filters'] = array();

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		unset( $domain );
		return $text;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $key, mixed $default = false ): mixed {
		return $GLOBALS['mds_test_options'][ $key ] ?? $default;
	}
}
if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( string $hook ): bool {
		return ! empty( $GLOBALS['mds_test_filters'][ $hook ] );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		foreach ( $GLOBALS['mds_test_filters'][ $hook ] ?? array() as $callback ) {
			$value = $callback( $value, ...$args );
		}
		return $value;
	}
}

require_once __DIR__ . '/../src/Admin/ImportFieldMapper.php';
require_once __DIR__ . '/../src/Admin/ImportExportHandler.php';

use MDS\ReleaseManager\Admin\ImportFieldMapper;
use MDS\ReleaseManager\Admin\ImportExportHandler;

$failures = array();
$assert   = static function ( bool $condition, string $label ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $label;
	}
};

// 1. Exact MDS Export headers map with high confidence.
$mds_headers = array(
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
$mds_map = ImportFieldMapper::suggest_mapping( $mds_headers, array() );
$assert( 'upc_ean' === $mds_map[0]['target'] && 'high' === $mds_map[0]['confidence'], 'MDS UPC/EAN maps with high confidence' );
$assert( 'title' === $mds_map[1]['target'], 'MDS Release Title maps to title' );
$assert( 'artist' === $mds_map[2]['target'], 'MDS Artist maps' );
$assert( 'release_type' === $mds_map[4]['target'], 'MDS Release Type maps' );
$assert( 'streaming_links_json' === $mds_map[12]['target'], 'MDS Streaming Links maps to streaming_links_json' );

// 2. Distributor export headers (DistroKid / TuneCore / CD Baby style) + custom provider.
$dist_headers = array(
	'Barcode',
	'Album Name',
	'Primary Artist',
	'Street Date',
	'Release Format',
	'Imprint',
	'Aggregator',
	'Spotify URL',
	'Bandcamp Link',
	'Cover Art',
);
$dist_map = ImportFieldMapper::suggest_mapping( $dist_headers, array() );
$targets  = array_column( $dist_map, 'target' );
$assert( 'upc_ean' === $targets[0], 'Barcode maps to upc_ean' );
$assert( 'title' === $targets[1], 'Album Name maps to title' );
$assert( 'artist' === $targets[2], 'Primary Artist maps to artist' );
$assert( 'release_date' === $targets[3], 'Street Date maps to release_date' );
$assert( 'release_type' === $targets[4], 'Release Format maps to release_type' );
$assert( 'record_label' === $targets[5], 'Imprint maps to record_label' );
$assert( 'distributor' === $targets[6], 'Aggregator maps to distributor' );
$assert( 'provider:Spotify' === $targets[7], 'Spotify URL maps to provider:Spotify' );
$assert( 'provider:Bandcamp' === $targets[8], 'Custom configured provider Bandcamp maps' );
$assert( 'featured_image_url' === $targets[9], 'Cover Art maps to featured_image_url' );

// 3. Cryptic headers resolved via sample-data content sniffing.
$cryptic_headers = array( 'Col A', 'Col B', 'Col C', 'Col D', 'Col E', 'Col F', 'Col G' );
$cryptic_samples = array(
	array(
		'197188012345',
		'2025-06-15',
		'Single',
		'https://open.spotify.com/album/4aawyAB9vmqN3uQ7FjRGTy',
		'https://cdn.example.com/covers/art.jpg',
		'https://cdn.example.com/previews/clip.mp3',
		'{"Spotify":"https://open.spotify.com/album/123"}',
	),
	array(
		'0088123456789',
		'2025-07-01',
		'EP',
		'https://open.spotify.com/album/7dGJo4pcD2V6oG8kP0tJRR',
		'https://cdn.example.com/covers/art2.png',
		'https://cdn.example.com/previews/clip2.wav',
		'{"Apple Music":"https://music.apple.com/us/album/456"}',
	),
);
$sniff_map     = ImportFieldMapper::suggest_mapping( $cryptic_headers, $cryptic_samples );
$sniff_targets = array_column( $sniff_map, 'target' );
$assert( 'upc_ean' === $sniff_targets[0], 'Sniffed 12-13 digit UPC column' );
$assert( 'release_date' === $sniff_targets[1], 'Sniffed ISO date column' );
$assert( 'release_type' === $sniff_targets[2], 'Sniffed Single/EP release_type column' );
$assert( 'provider:Spotify' === $sniff_targets[3], 'Sniffed Spotify domain column' );
$assert( 'featured_image_url' === $sniff_targets[4], 'Sniffed image URL column' );
$assert( 'audio_preview_url' === $sniff_targets[5], 'Sniffed audio URL column' );
$assert( 'streaming_links_json' === $sniff_targets[6], 'Sniffed JSON streaming_links_json column' );

// 4. Duplicate target prevention.
$dup_headers = array( 'UPC', 'EAN', 'Barcode' );
$dup_map     = ImportFieldMapper::suggest_mapping( $dup_headers, array() );
$assigned    = array_filter( array_column( $dup_map, 'target' ) );
$assert( 1 === count( $assigned ), 'Only one column assigned to upc_ean when multiple UPC synonyms exist' );

// 5. AI status & filter-backed AI mapping with allowlist sanitization.
$status_before = ImportFieldMapper::ai_status();
$assert( false === $status_before['available'], 'AI status is unavailable without keys or filter' );

$GLOBALS['mds_test_filters']['mds_rm_csv_ai_map_columns'] = array(
	static function (): array {
		return array(
			'0' => 'upc_ean',
			'1' => 'invalid_injected_field',
			'2' => 'artist',
		);
	},
);
$status_after = ImportFieldMapper::ai_status();
$assert( true === $status_after['available'] && 'custom' === $status_after['provider'], 'AI status reports custom provider when filter is hooked' );

$ai_result = ImportFieldMapper::ai_suggest_mapping( array( 'X', 'Y', 'Z' ), array() );
$assert( is_array( $ai_result ) && isset( $ai_result['mapping'] ), 'AI filter returned valid mapping payload' );
if ( is_array( $ai_result ) && isset( $ai_result['mapping'] ) ) {
	$ai_mapping = $ai_result['mapping'];
	$assert( 'upc_ean' === $ai_mapping[0]['target'] && 'ai' === $ai_mapping[0]['confidence'], 'AI mapped col 0 to upc_ean with ai confidence' );
	$assert( '' === $ai_mapping[1]['target'] && 'none' === $ai_mapping[1]['confidence'], 'AI rejected invalid target key against allowlist' );
	$assert( 'artist' === $ai_mapping[2]['target'] && 'ai' === $ai_mapping[2]['confidence'], 'AI mapped col 2 to artist' );
}

// 6. Identical-record duplicate detection (`ImportExportHandler::is_release_identical`).
$existing_release = array(
	'title'              => 'Midnight Drive',
	'artist_name'        => 'Neon Skyline',
	'release_date'       => '2025-06-15 00:00:00',
	'release_type_id'    => 12,
	'record_label'       => 'Starlight Records',
	'distributor'        => 'DistroKid',
	'buy_now_link'       => 'https://shop.example.com/midnight-drive',
	'tags'               => array( 'Synthwave', 'Electronic' ),
	'featured_image_url' => 'https://cdn.example.com/art.jpg',
	'audio_preview_url'  => 'https://cdn.example.com/preview.mp3',
	'about'              => 'Debut summer single.',
	'streaming_links'    => '{"Spotify":"https://open.spotify.com/album/123","Apple Music":"https://music.apple.com/album/456"}',
);

// Same data with different tag order, provider key order, whitespace, and omitted optional columns.
$incoming_identical = array(
	'title'              => '  Midnight Drive ',
	'artist_name'        => 'neon skyline',
	'release_date'       => '2025-06-15 12:30:00',
	'release_type_id'    => 12,
	'record_label'       => 'Starlight Records',
	'distributor'        => 'DistroKid',
	'buy_now_link'       => 'https://shop.example.com/midnight-drive',
	'tags'               => 'electronic, synthwave',
	'featured_image_url' => null,
	'audio_preview_url'  => null,
	'about'              => null,
	'streaming_links'    => array(
		'Apple Music' => 'https://music.apple.com/album/456',
		'Spotify'     => 'https://open.spotify.com/album/123',
	),
);
$assert( true === ImportExportHandler::is_release_identical( $existing_release, $incoming_identical ), 'Identical release matches regardless of tag/link ordering and omitted optional fields' );

$incoming_diff_title          = $incoming_identical;
$incoming_diff_title['title'] = 'Midnight Drive (Deluxe)';
$assert( false === ImportExportHandler::is_release_identical( $existing_release, $incoming_diff_title ), 'Different title is detected as changed' );

$incoming_diff_link                               = $incoming_identical;
$incoming_diff_link['streaming_links']['Spotify'] = 'https://open.spotify.com/album/999';
$assert( false === ImportExportHandler::is_release_identical( $existing_release, $incoming_diff_link ), 'Different streaming URL is detected as changed' );

$incoming_diff_cover                       = $incoming_identical;
$incoming_diff_cover['featured_image_url'] = 'https://cdn.example.com/new-art.jpg';
$assert( false === ImportExportHandler::is_release_identical( $existing_release, $incoming_diff_cover ), 'Explicitly provided different cover art URL is detected as changed' );

// 7. JSON, XML, and Blank CSV Template export serializers.
$sample_catalog = array(
	'schema_version' => '2.1',
	'exported_at'    => '2026-11-25T18:00:00+00:00',
	'site_url'       => 'https://music.example.com/',
	'artists'        => array(
		array(
			'uuid'           => 'artist-uuid-1',
			'name'           => 'Neon & Skyline',
			'slug'           => 'neon-skyline',
			'status'         => 'publish',
			'website'        => 'https://neonskyline.example.com',
			'portrait_url'   => '',
			'routing_policy' => 'fallback',
			'bio'            => 'Synthwave duo.',
		),
	),
	'releases'       => array(
		array(
			'upc_ean'            => '0088123456789',
			'title'              => 'Midnight Drive',
			'artist_uuid'        => 'artist-uuid-1',
			'artist'             => 'Neon & Skyline',
			'release_date'       => '2025-06-15 00:00:00',
			'release_type'       => 'Single',
			'status'             => 'publish',
			'record_label'       => 'Starlight Records',
			'distributor'        => 'DistroKid',
			'buy_now_link'       => 'https://shop.example.com/midnight-drive',
			'buy_now_links'      => array(
				array(
					'format' => 'vinyl-lp',
					'label'  => 'Vinyl LP',
					'url'    => 'https://shop.example.com/midnight-drive-vinyl',
				),
			),
			'tags'               => 'Synthwave, Electronic',
			'featured_image_url' => 'https://cdn.example.com/art.jpg',
			'audio_preview_url'  => 'https://cdn.example.com/preview.mp3',
			'about'              => 'Debut single.',
			'streaming_links'    => array(
				'Spotify' => 'https://open.spotify.com/album/123',
			),
		),
	),
);

$json_out = ImportExportHandler::build_json_export( $sample_catalog );
$decoded  = json_decode( $json_out, true );
$assert( is_array( $decoded ) && '2.1' === ( $decoded['schema_version'] ?? '' ), 'JSON export encodes schema_version 2.1' );
$assert( '0088123456789' === ( $decoded['releases'][0]['upc_ean'] ?? '' ), 'JSON export preserves leading-zero UPC/EAN' );
$assert( 'Vinyl LP' === ( $decoded['releases'][0]['buy_now_links'][0]['label'] ?? '' ), 'JSON export preserves multi-format buy_now_links' );

$xml_out = ImportExportHandler::build_xml_export( $sample_catalog );
$xml_obj = simplexml_load_string( $xml_out );
$assert( false !== $xml_obj, 'XML export produces valid parseable XML' );
if ( false !== $xml_obj ) {
	$assert( 'Neon & Skyline' === (string) $xml_obj->artists->artist[0]->name, 'XML export escapes special characters cleanly' );
	$assert( '0088123456789' === (string) $xml_obj->releases->release[0]->upc_ean, 'XML export preserves release UPC/EAN' );
	$assert( 'Spotify' === (string) $xml_obj->releases->release[0]->streaming_links->link[0]['provider'], 'XML export includes streaming provider attribute' );
}

$template_csv = ImportExportHandler::build_csv_template( array( 'Spotify', 'Bandcamp' ), true );
$assert( str_starts_with( $template_csv, "\xEF\xBB\xBF" ), 'CSV template prepends UTF-8 BOM when requested' );
$assert( str_contains( $template_csv, 'Spotify Link' ) && str_contains( $template_csv, 'Bandcamp Link' ), 'CSV template includes configured provider columns' );

// 8. XML catalog import round-trip & XXE/DOCTYPE rejection.
$parsed_xml = ImportExportHandler::parse_xml_catalog( $xml_out );
$assert( is_array( $parsed_xml ) && ! is_wp_error( $parsed_xml ), 'parse_xml_catalog() parses exported XML cleanly' );
if ( is_array( $parsed_xml ) ) {
	$assert( 'Neon & Skyline' === ( $parsed_xml['artists'][0]['name'] ?? '' ), 'XML round-trip preserves Artist name and entity decoding' );
	$assert( '0088123456789' === ( $parsed_xml['releases'][0]['upc_ean'] ?? '' ), 'XML round-trip preserves Release UPC/EAN' );
	$assert( 'Vinyl LP' === ( $parsed_xml['releases'][0]['buy_now_links'][0]['label'] ?? '' ), 'XML round-trip preserves multi-format buy_now_links' );
	$assert( 'https://open.spotify.com/album/123' === ( $parsed_xml['releases'][0]['streaming_links']['Spotify'] ?? '' ), 'XML round-trip preserves streaming_links' );
}

$xxe_payload = '<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><mds_catalog><releases><release><title>&xxe;</title></release></releases></mds_catalog>';
$xxe_result  = ImportExportHandler::parse_xml_catalog( $xxe_payload );
$assert( is_wp_error( $xxe_result ) && 'mds_xml_forbidden_dtd' === $xxe_result->get_error_code(), 'parse_xml_catalog() rejects DOCTYPE/XXE payloads' );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, "FAILED:\n - " . implode( "\n - ", $failures ) . "\n" );
	exit( 1 );
}

echo "OK: all CsvFieldMapper assertions passed.\n";

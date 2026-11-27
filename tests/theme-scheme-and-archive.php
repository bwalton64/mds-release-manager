<?php
/**
 * Standalone unit tests for multi-source ThemeScheme detection, fingerprint
 * baseline auto-reset, and TemplateDefaults Release Archive schema.
 *
 * Run: php tests/theme-scheme-and-archive.php
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
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
	define( 'ELEMENTOR_VERSION', '3.25.0' );
}

$GLOBALS['mds_test_options']    = array();
$GLOBALS['mds_test_meta']       = array();
$GLOBALS['mds_test_transients'] = array();

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		unset( $domain );
		return $text;
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( mixed $n ): int {
		return abs( (int) $n );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $s ): string {
		return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $s ): string {
		return trim( strip_tags( $s ) );
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $key, mixed $default = false ): mixed {
		return array_key_exists( $key, $GLOBALS['mds_test_options'] ) ? $GLOBALS['mds_test_options'][ $key ] : $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $key, mixed $value, mixed $autoload = null ): bool {
		unset( $autoload );
		$GLOBALS['mds_test_options'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
		$val = $GLOBALS['mds_test_meta'][ $post_id ][ $key ] ?? '';
		return $single ? $val : array( $val );
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, mixed $value, int $ttl = 0 ): bool {
		unset( $ttl );
		$GLOBALS['mds_test_transients'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}
	}
}

require_once dirname( __DIR__ ) . '/src/Blocks/ReleaseMeta.php';
require_once dirname( __DIR__ ) . '/src/Frontend/TemplateDefaults.php';
require_once dirname( __DIR__ ) . '/src/Frontend/ThemeScheme.php';

use MDS\ReleaseManager\Frontend\TemplateDefaults;
use MDS\ReleaseManager\Frontend\ThemeScheme;

$assertions = 0;
function mds_assert_same( mixed $expected, mixed $actual, string $label ): void {
	global $assertions;
	++$assertions;
	if ( $expected !== $actual ) {
		fwrite( STDERR, sprintf( "FAIL [%s]: expected %s, got %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) ) );
		exit( 1 );
	}
}

// 1. Elementor Active Kit dark-mode detection.
$GLOBALS['mds_test_options']['elementor_active_kit'] = 99;
$GLOBALS['mds_test_meta'][99]['_elementor_page_settings'] = array(
	'body_background_color' => '#121318',
	'system_colors'         => array(
		array( '_id' => 'primary', 'color' => '#38bdf8' ),
		array( '_id' => 'text', 'color' => '#e5e7eb' ),
		array( '_id' => 'accent', 'color' => '#f59e0b' ),
	),
);
ThemeScheme::reset_cache();
$scheme = ThemeScheme::detect();
mds_assert_same( 'elementor', $scheme['source'], 'detects Elementor Kit as primary source' );
mds_assert_same( true, $scheme['isDark'], 'detects dark mode from Elementor body background' );
mds_assert_same( '#121318', $scheme['background'], 'resolves Elementor background hex' );
mds_assert_same( '#e5e7eb', $scheme['text'], 'resolves Elementor text hex' );
mds_assert_same( '#f59e0b', $scheme['accent'], 'resolves Elementor accent hex' );

// 2. Initial fingerprint stores without wiping overrides; subsequent scheme change resets ONLY color overrides.
$GLOBALS['mds_test_options'][ TemplateDefaults::OPTION_NAME ] = array(
	'release' => array( 'layout' => 'stacked', 'container_bg' => '#ff0000' ),
	'artist'  => array( 'width' => 1100, 'body_color' => '#00ff00' ),
	'archive' => array( 'columns' => 4, 'page_id' => 77, 'card_bg' => '#0000ff' ),
);
$GLOBALS['mds_test_options']['mds_rm_block_defaults'] = array(
	'release-player' => array( 'backgroundColor' => '#111111', 'borderRadius' => 14 ),
);

mds_assert_same( false, ThemeScheme::maybe_sync_scheme_baseline(), 'first sync records fingerprint without resetting' );
mds_assert_same( '#ff0000', $GLOBALS['mds_test_options'][ TemplateDefaults::OPTION_NAME ]['release']['container_bg'], 'first sync preserves existing colors' );

// Switch Elementor Kit to light scheme.
$GLOBALS['mds_test_meta'][99]['_elementor_page_settings']['body_background_color'] = '#f9fafb';
$GLOBALS['mds_test_meta'][99]['_elementor_page_settings']['system_colors'][1]['color'] = '#111827';
ThemeScheme::reset_cache();

mds_assert_same( true, ThemeScheme::maybe_sync_scheme_baseline(), 'scheme change triggers auto-reset' );
mds_assert_same( '', $GLOBALS['mds_test_options'][ TemplateDefaults::OPTION_NAME ]['release']['container_bg'], 'auto-reset clears release container_bg' );
mds_assert_same( 'stacked', $GLOBALS['mds_test_options'][ TemplateDefaults::OPTION_NAME ]['release']['layout'], 'auto-reset preserves release layout' );
mds_assert_same( '', $GLOBALS['mds_test_options'][ TemplateDefaults::OPTION_NAME ]['archive']['card_bg'], 'auto-reset clears archive card_bg' );
mds_assert_same( 4, $GLOBALS['mds_test_options'][ TemplateDefaults::OPTION_NAME ]['archive']['columns'], 'auto-reset preserves archive columns' );
mds_assert_same( 77, $GLOBALS['mds_test_options'][ TemplateDefaults::OPTION_NAME ]['archive']['page_id'], 'auto-reset preserves archive page_id' );
mds_assert_same( false, isset( $GLOBALS['mds_test_options']['mds_rm_block_defaults']['release-player']['backgroundColor'] ), 'auto-reset clears block color override' );
mds_assert_same( 14, $GLOBALS['mds_test_options']['mds_rm_block_defaults']['release-player']['borderRadius'], 'auto-reset preserves block structural setting' );

// 3. TemplateDefaults archive validation & CSS attribute output.
$validated = TemplateDefaults::validate(
	array(
		'release' => array(),
		'artist'  => array(),
		'archive' => array(
			'enabled'    => true,
			'page_id'    => 123,
			'columns'    => 4,
			'card_radius' => 12,
			'card_shadow' => 'subtle',
		),
	)
);
mds_assert_same( true, is_array( $validated ), 'archive payload validates cleanly' );
mds_assert_same( 123, $validated['archive']['page_id'], 'validates archive page_id' );
mds_assert_same( 4, $validated['archive']['columns'], 'validates archive columns' );
mds_assert_same( true, $validated['release']['enable_catalog_archive'], 'mirrors archive.enabled to legacy release flag' );

echo sprintf( "PASS: %d ThemeScheme & Release Archive assertions succeeded.\n", $assertions );

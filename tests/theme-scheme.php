<?php
/**
 * Standalone checks for ThemeScheme color-scheme detection and presets.
 *
 * Run with: php tests/theme-scheme.php
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

class WP_Error {
	public $code;
	public function __construct( $code, $_message, $_data ) {
		$this->code = $code;
	}
}
function __( $message, $domain ) {
	return $message;
}
function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}
function esc_attr( $value ) {
	return (string) $value;
}
function apply_filters( $hook, $value ) {
	return $value;
}
function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}
function sanitize_text_field( $value ) {
	return trim( strip_tags( $value ) );
}
function sanitize_hex_color( $value ) {
	return preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $value ) ? $value : '';
}
function get_option( $name, $fallback = false ) {
	return $fallback;
}
function wp_get_global_settings( $path = array() ) {
	return $GLOBALS['test_global_settings'] ?? array();
}
function wp_get_global_styles( $path = array() ) {
	return $GLOBALS['test_global_styles'] ?? array();
}

require __DIR__ . '/../vendor/autoload.php';
use MDS\ReleaseManager\Blocks\BlockDefaults;
use MDS\ReleaseManager\Frontend\ThemeScheme;

function check_scheme( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

// 1. Luminance & contrast helpers.
check_scheme( ThemeScheme::luminance( '#ffffff' ) > 0.99, 'White luminance must be near 1.0.' );
check_scheme( ThemeScheme::luminance( '#000000' ) < 0.01, 'Black luminance must be near 0.0.' );
check_scheme( '#ffffff' === ThemeScheme::contrast_text( '#111827' ), 'Dark background must select white text.' );
check_scheme( '#111827' === ThemeScheme::contrast_text( '#f8fafc' ), 'Light background must select dark text.' );
check_scheme( '#808080' === ThemeScheme::mix( '#000000', '#ffffff', 0.5 ), '50% mix of black and white must be mid-gray.' );

// 2. Twenty Twenty-Five "Evening" (dark) detection via Global Styles preset tokens.
$GLOBALS['test_global_settings'] = array(
	'theme' => array(
		array( 'slug' => 'base', 'color' => '#14151a' ),
		array( 'slug' => 'contrast', 'color' => '#f5f5f7' ),
		array( 'slug' => 'accent-1', 'color' => '#a5b4fc' ),
	),
);
$GLOBALS['test_global_styles']   = array(
	'color' => array(
		'background' => 'var:preset|color|base',
		'text'       => 'var:preset|color|contrast',
	),
);
$detected = ThemeScheme::detect();
check_scheme( true === $detected['isDark'] && 'dark' === $detected['mode'], 'TT5 Evening dark base must be detected as dark mode.' );
check_scheme( '#14151a' === $detected['background'] && '#f5f5f7' === $detected['text'], 'Global Styles preset tokens must resolve to hex.' );
check_scheme( '#a5b4fc' === $detected['accent'] && '#111827' === $detected['accentText'], 'Light accent on dark theme must pick dark play icon for WCAG contrast.' );
check_scheme( str_contains( ThemeScheme::css_variables(), '--mds-theme-surface:' ), 'CSS variables output must declare --mds-theme-surface.' );

// 3. Every bundled block & template preset must pass validation, and ExtensionApi must expose ThemeScheme.
foreach ( ThemeScheme::presets() as $preset ) {
	if ( 'auto' === $preset['id'] ) {
		continue;
	}
	$valid = BlockDefaults::validate( $preset['blocks'] );
	check_scheme( true === $valid, 'Block preset ' . $preset['id'] . ' must pass BlockDefaults::validate().' );
}
foreach ( ThemeScheme::template_presets() as $preset ) {
	$valid = \MDS\ReleaseManager\Frontend\TemplateDefaults::validate( $preset['templates'] );
	check_scheme( is_array( $valid ), 'Template preset ' . $preset['id'] . ' must pass TemplateDefaults::validate().' );
}
check_scheme( true === \MDS\ReleaseManager\Api\ExtensionApi::is_dark_theme(), 'ExtensionApi::is_dark_theme() must reflect detected dark scheme.' );
check_scheme( '#14151a' === \MDS\ReleaseManager\Api\ExtensionApi::get_theme_scheme()['background'], 'ExtensionApi::get_theme_scheme() must return resolved tokens.' );
check_scheme( str_contains( \MDS\ReleaseManager\Api\ExtensionApi::get_theme_css_variables(), '--mds-theme-bg:#14151a;' ), 'ExtensionApi::get_theme_css_variables() must return CSS custom properties.' );

echo "ThemeScheme checks passed.\n";

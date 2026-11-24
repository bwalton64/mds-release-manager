<?php
/**
 * Standalone Default Templates & legacy Customizer fallback checks.
 *
 * Run with: php tests/customizer.php
 */

declare(strict_types=1);
define( 'ABSPATH', __DIR__ );
define( 'MDS_RM_PATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );

class WP_Error {
	public $code;
	public function __construct( $code, $_message = '', $_data = array() ) {
		$this->code = $code;
	}
}
function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}
function sanitize_hex_color( $value ) {
	return preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $value ) ? $value : '';
}
function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}
function absint( $value ) {
	return abs( (int) $value );
}
function __( $value, $domain ) {
	return $value;
}
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['options'] ) ? $GLOBALS['options'][ $name ] : $default;
}
function get_theme_mod( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['theme_mods'] ) ? $GLOBALS['theme_mods'][ $name ] : $default;
}

require MDS_RM_PATH . 'vendor/autoload.php';
use MDS\ReleaseManager\Frontend\Customizer;
use MDS\ReleaseManager\Frontend\TemplateDefaults;

function check_tpl( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$GLOBALS['options']    = array();
$GLOBALS['theme_mods'] = array();

// 1. Legacy theme_mod read-fallback works when option is unset.
$GLOBALS['theme_mods'] = array(
	'mds_rm_layout_style'                         => 'stacked',
	'mds_rm_release_fallback_content_color'       => 'rgba(12,34,56,0.5)',
	'mds_rm_release_fallback_content_line_height' => 180,
	'mds_rm_release_fallback_image_shadow'        => 'none',
	'mds_rm_artist_fallback_image_radius'         => 10,
);
$release_css = TemplateDefaults::get_release_style_attribute();
$artist_css  = TemplateDefaults::get_artist_style_attribute();
check_tpl( 'stacked' === TemplateDefaults::get( 'release' )['layout'], 'Legacy layout theme_mod must be inherited.' );
check_tpl( str_contains( $release_css, '--mds-fallback-content-color:rgba(12,34,56,0.5)' ), 'Release content color must retain alpha.' );
check_tpl( str_contains( $release_css, '--mds-fallback-content-line-height:1.8' ), 'Release line height must convert to CSS number.' );
check_tpl( str_contains( $release_css, '--mds-fallback-image-shadow:none' ), 'Release shadow none must emit CSS override.' );
check_tpl( str_contains( $artist_css, '--mds-artist-image-radius:10%' ), 'Artist image radius must use percentages.' );

// 2. Option overrides theme_mod and supports new settings.
$GLOBALS['options'][ TemplateDefaults::OPTION_NAME ] = array(
	'release' => array(
		'layout'         => 'split',
		'split_ratio'    => '35_65',
		'image_position' => 'right',
		'image_shadow'   => 'elevated',
		'image_aspect'   => '1/1',
	),
	'artist'  => array(
		'layout'               => 'split',
		'contact_border_color' => '#334455',
		'contact_border_width' => 2,
		'body_font_size'       => 18,
	),
);
$release_css = Customizer::get_release_fallback_style_attribute();
$artist_css  = Customizer::get_artist_fallback_style_attribute();
check_tpl( str_contains( $release_css, '--mds-fallback-split-columns:minmax(0, 1.85fr) minmax(0, 1fr)' ), 'Right-side 35/65 split must flip column tracks.' );
check_tpl( str_contains( $release_css, '--mds-fallback-image-aspect:1/1' ), 'Release image aspect ratio must be emitted.' );
check_tpl( str_contains( $release_css, '--mds-fallback-image-shadow:0 12px 28px rgba(0, 0, 0, .18)' ), 'Elevated shadow preset must map cleanly.' );
check_tpl( str_contains( $artist_css, '--mds-artist-contact-border:2px solid #334455' ), 'Artist contact border must emit width and color.' );
check_tpl( str_contains( $artist_css, '--mds-artist-body-font-size:18px' ), 'Artist biography font size must emit when > 0.' );

// 3. Validation rejects unsafe or invalid payloads.
check_tpl( is_wp_error( TemplateDefaults::validate( 'bad' ) ), 'Non-array payload must fail validation.' );
check_tpl( is_wp_error( TemplateDefaults::validate( array( 'release' => array( 'content_color' => 'red;display:none' ), 'artist' => array() ) ) ), 'Unsafe CSS color must fail validation.' );
check_tpl( is_wp_error( TemplateDefaults::validate( array( 'release' => array( 'layout' => 'diagonal' ), 'artist' => array() ) ) ), 'Invalid enum must fail validation.' );
check_tpl( is_wp_error( TemplateDefaults::validate( array( 'release' => array( 'content_font_family' => 'Arial;color:red' ), 'artist' => array() ) ) ), 'Unsafe font family must fail validation.' );

$valid = TemplateDefaults::validate(
	array(
		'release' => array(
			'layout'              => 'split',
			'sticky_image'        => true,
			'content_font_family' => 'var(--wp--preset--font-family--system-sans)',
		),
		'artist'  => array(
			'discography_heading' => '<b>Discography</b>',
			'body_font_size'      => 0,
		),
	)
);
check_tpl( ! is_wp_error( $valid ), 'Valid template defaults payload must pass validation.' );
check_tpl( 'Discography' === $valid['artist']['discography_heading'], 'Headings must be sanitized.' );
check_tpl( 0 === $valid['artist']['body_font_size'], 'Zero biography font size (inherit) must be preserved.' );

echo "Default Templates checks passed.\n";

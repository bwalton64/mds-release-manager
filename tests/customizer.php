<?php
/**
 * Standalone fallback Customizer checks. Run with: php tests/customizer.php
 */

declare(strict_types=1);
define( 'ABSPATH', __DIR__ );
define( 'MDS_RM_PATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );

class WP_Customize_Control {
	public $id;
	public $label;
	public $section;
	public $priority = 10;
	public $input_attrs = array();
	public $choices = array();
	public $setting;
	public function __construct( $manager, $id, $args ) {
		$this->id = $id;
		$this->setting = (object) array( 'default' => $manager->settings[ $id ]['default'] );
		foreach ( $args as $key => $value ) {
			$this->$key = $value;
		}
	}
}
class WP_Customize_Manager {
	public $settings = array();
	public $controls = array();
	public function add_panel( ...$args ) {}
	public function add_section( ...$args ) {}
	public function add_setting( $id, $args ) {
		$this->settings[ $id ] = $args;
	}
	public function add_control( $control ) {
		$this->controls[ $control->id ] = $control;
	}
	public function get_control( $id ) {
		return $this->controls[ $id ];
	}
}
function sanitize_hex_color( $value ) {
	return preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $value ) ? $value : '';
}
function absint( $value ) {
	return abs( (int) $value );
}
function __( $value, $domain ) {
	return $value;
}
function get_theme_mod( $name, $default ) {
	return $GLOBALS['theme_mods'][ $name ] ?? $default;
}

require MDS_RM_PATH . 'includes/blocks/class-mds-rm-release-meta.php';
require MDS_RM_PATH . 'includes/frontend/class-mds-rm-customizer.php';

function check_customizer( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$customizer = new WP_Customize_Manager();
MDS_RM_Customizer::customize_register( $customizer );
foreach ( array(
	'mds_rm_release_section' => array( 'Layout', 'Featured image', 'Content typography' ),
	'mds_rm_artist_section'  => array( 'Layout', 'Portrait', 'Biography', 'Contact information' ),
) as $section => $expected_headings ) {
	$controls = array_filter( $customizer->controls, static fn( $control ) => $section === $control->section );
	usort( $controls, static fn( $left, $right ) => $left->priority <=> $right->priority );
	$headings = array_values( array_filter( array_column( $controls, 'group_heading' ) ) );
	check_customizer( $expected_headings === $headings, 'Fallback group headings must appear in section order.' );
	check_customizer( count( $controls ) === count( array_unique( array_column( $controls, 'priority' ) ) ), 'Each fallback control must have a distinct place in its section.' );
}
foreach ( array_keys( $customizer->settings ) as $id ) {
	check_customizer( ! preg_match( '/fallback_(meta|links|button|icon|table)_/', $id ), 'Block styling must not be registered in the Customizer.' );
}
check_customizer( 'radio' === $customizer->controls['mds_rm_layout_style']->component_type, 'Layout must use a native choice control.' );
check_customizer( 'range' === $customizer->controls['mds_rm_artist_fallback_width']->component_type, 'Numeric settings must use native range controls.' );
check_customizer( 'default' === $customizer->settings['mds_rm_release_fallback_image_shadow']['default'], 'The image shadow must default to the existing appearance.' );
foreach ( array( 'mds_rm_release_fallback_content_color', 'mds_rm_artist_fallback_contact_background' ) as $id ) {
	check_customizer( 'color' === $customizer->controls[ $id ]->component_type, 'Both sections must use native color controls.' );
	check_customizer( array( MDS_RM_Customizer::class, 'sanitize_fallback_color' ) === $customizer->settings[ $id ]['sanitize_callback'], 'Color settings must accept alpha colors.' );
}
foreach ( array( '', '#abc', '#123456', 'rgba(12,34,56,0.5)', 'transparent' ) as $color ) {
	check_customizer( $color === MDS_RM_Customizer::sanitize_fallback_color( $color ), 'Valid and inherited colors must be retained.' );
}
check_customizer( null === MDS_RM_Customizer::sanitize_fallback_color( 'red;display:none' ), 'Unsafe colors must be rejected.' );
check_customizer( null === MDS_RM_Customizer::sanitize_fallback_color( array() ), 'Non-scalar colors must be rejected.' );
check_customizer( 'default' === MDS_RM_Customizer::sanitize_shadow( 'default' ) && null === MDS_RM_Customizer::sanitize_shadow( 'other' ), 'Shadow choices must be restricted.' );
$GLOBALS['theme_mods'] = array();
check_customizer( ! str_contains( MDS_RM_Customizer::get_artist_fallback_style_attribute(), '--mds-artist-image-radius:' ), 'Unset new settings must retain stylesheet and theme defaults.' );
check_customizer( ! str_contains( MDS_RM_Customizer::get_release_fallback_style_attribute(), '--mds-fallback-image-shadow:' ), 'Unset shadow must retain the default.' );
$GLOBALS['theme_mods'] = array(
	'mds_rm_release_fallback_meta_background' => 'rgba(12,34,56,0.5)',
	'mds_rm_release_fallback_content_color' => 'rgba(12,34,56,0.5)',
	'mds_rm_release_fallback_content_line_height' => 180,
	'mds_rm_release_fallback_image_shadow' => 'none',
	'mds_rm_artist_fallback_table_background' => '#123456',
	'mds_rm_artist_fallback_image_radius' => 10,
);
check_customizer( str_contains( MDS_RM_Customizer::get_release_fallback_style_attribute(), '--mds-fallback-content-color:rgba(12,34,56,0.5)' ), 'Release content color must retain alpha.' );
check_customizer( ! str_contains( MDS_RM_Customizer::get_release_fallback_style_attribute(), '--mds-meta-' ), 'Old Customizer block styles must not affect Release output.' );
check_customizer( str_contains( MDS_RM_Customizer::get_release_fallback_style_attribute(), '--mds-fallback-content-line-height:1.8' ), 'Release line height must be converted to a CSS number.' );
check_customizer( str_contains( MDS_RM_Customizer::get_release_fallback_style_attribute(), '--mds-fallback-image-shadow:none' ), 'The shadow control must affect the Release image.' );
check_customizer( ! str_contains( MDS_RM_Customizer::get_artist_fallback_style_attribute(), '--mds-artist-table-' ), 'Old Customizer table styles must not affect Artist output.' );
check_customizer( str_contains( MDS_RM_Customizer::get_artist_fallback_style_attribute(), '--mds-artist-image-radius:10%' ), 'Artist image radius must use percentages.' );
echo "Fallback Customizer checks passed.\n";

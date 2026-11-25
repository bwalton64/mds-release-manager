<?php
/**
 * Standalone checks for settings-save validation.
 *
 * Run with: php tests/settings-save.php
 */

declare(strict_types=1);
define( 'ABSPATH', __DIR__ );

class WP_Error {
	public $code;
	public function __construct( $code, $_message, $_data ) {
		$this->code = $code;
	}
}
class WP_REST_Response {
	public $data;
	public function __construct( $data ) {
		$this->data = $data;
	}
}
class MDS_RM_Sync {
	public static function purge_cache() {}
}
class MDS_RM_Release_Meta {
	public static function fields() { return array(); }
	public static function style_fields() { return array(); }
}
class MDS_RM_Release_Links {
	public static function style_fields() { return array(); }
}
class MDS_RM_Release_Links_Block {
	public static function style_value( $value, $type ) { return ''; }
}
class MDS_RM_Discography {
	public static function table_style_variables( $value ) { return ''; }
}
class MDS_RM_Logger {
	public static function diagnostic( $message ) {}
	public static function report_failure( $message ) {}
}
class Fake_Request {
	private $params;
	public function __construct( array $params ) { $this->params = $params; }
	public function get_body_params() { return $this->params; }
}
function __( $message, $domain ) { return $message; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function wp_unslash( $value ) { return $value; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_option( $key, $default = false ) { return $GLOBALS['updates'][ $key ] ?? $default; }
function update_option( $key, $value ) { $GLOBALS['updates'][ $key ] = $value; }
function apply_filters( $hook, $value ) { return $GLOBALS['filters'][ $hook ] ?? $value; }
function esc_attr( $value ) { return (string) $value; }
function rest_ensure_response( $value ) { return new WP_REST_Response( $value ); }

require __DIR__ . '/../vendor/autoload.php';
use MDS\ReleaseManager\Api\RestApi;
use MDS\ReleaseManager\Frontend\Assets;
class_alias( 'MDS_RM_Sync', 'MDS\\ReleaseManager\\Core\\Sync' );
class_alias( 'MDS_RM_Release_Meta', 'MDS\\ReleaseManager\\Blocks\\ReleaseMeta' );
class_alias( 'MDS_RM_Release_Links', 'MDS\\ReleaseManager\\Blocks\\ReleaseLinks' );
class_alias( 'MDS_RM_Release_Links_Block', 'MDS\\ReleaseManager\\Blocks\\ReleaseLinksBlock' );
class_alias( 'MDS_RM_Discography', 'MDS\\ReleaseManager\\Frontend\\Discography' );
class_alias( 'MDS_RM_Logger', 'MDS\\ReleaseManager\\Core\\Logger' );

$GLOBALS['updates'] = array();
$defaults = array( 'release-links' => array( 'newTab' => false ) );
$result = RestApi::save_settings_api(
	new Fake_Request(
		array(
			'mds_rm_block_defaults' => json_encode( $defaults ),
		)
	)
);
if ( ! $result instanceof WP_REST_Response || $GLOBALS['updates']['mds_rm_block_defaults'] !== $defaults ) {
	throw new RuntimeException( 'Valid block defaults were not saved.' );
}
$GLOBALS['updates'] = array();
$result = RestApi::save_settings_api( new Fake_Request( array( 'mds_rm_block_defaults' => '{"release-links":{"newTab":"false"}}' ) ) );
if ( ! $result instanceof WP_Error || 'mds_rm_invalid_block_defaults' !== $result->code || $GLOBALS['updates'] ) {
	throw new RuntimeException( 'Invalid defaults must be rejected before any option is changed.' );
}
$GLOBALS['updates'] = array();
$result = RestApi::save_settings_api( new Fake_Request( array() ) );
if ( ! $result instanceof WP_REST_Response || isset( $GLOBALS['updates']['mds_rm_block_defaults'] ) || isset( $GLOBALS['updates']['mds_rm_bandcamp_template'] ) || isset( $GLOBALS['updates']['mds_rm_providers'] ) ) {
	throw new RuntimeException( 'Omitted defaults and providers must not be overwritten.' );
}
$GLOBALS['updates'] = array();
$result = RestApi::save_settings_api( new Fake_Request( array( 'mds_rm_providers' => 'invalid' ) ) );
if ( ! $result instanceof WP_Error || 'mds_rm_invalid_providers' !== $result->code || $GLOBALS['updates'] ) {
	throw new RuntimeException( 'Invalid providers must be rejected before any option is changed.' );
}
$GLOBALS['updates'] = array();
$result = RestApi::save_settings_api(
	new Fake_Request(
		array(
			'mds_rm_template_defaults' => '{"release":{"layout":"invalid"}}',
		)
	)
);
if ( ! $result instanceof WP_Error || 'mds_rm_invalid_template_defaults' !== $result->code || $GLOBALS['updates'] ) {
	throw new RuntimeException( 'Invalid template defaults must be rejected before any option is changed.' );
}
$GLOBALS['updates'] = array();
$result = RestApi::save_settings_api(
	new Fake_Request(
		array(
			'mds_rm_template_defaults' => json_encode( array( 'release' => array( 'layout' => 'stacked', 'sticky_image' => true ), 'artist' => array( 'layout' => 'split' ) ) ),
		)
	)
);
if ( ! $result instanceof WP_REST_Response || 'stacked' !== ( $GLOBALS['updates']['mds_rm_template_defaults']['release']['layout'] ?? '' ) || 'split' !== ( $GLOBALS['updates']['mds_rm_template_defaults']['artist']['layout'] ?? '' ) ) {
	throw new RuntimeException( 'Valid template defaults were not saved.' );
}
$GLOBALS['updates'] = array();
$result = RestApi::save_settings_api(
	new Fake_Request(
		array(
			'mds_rm_fa_support' => 'internal',
		)
	)
);
if ( ! $result instanceof WP_REST_Response || 'cdn' !== ( $GLOBALS['updates']['mds_rm_fa_support'] ?? '' ) ) {
	throw new RuntimeException( 'Legacy internal FA source must normalize to cdn.' );
}
if ( 'cdn' !== Assets::resolve_font_awesome_source( 'auto' ) ) {
	throw new RuntimeException( 'Auto FA mode must resolve to cdn when no FA plugin is detected.' );
}
$GLOBALS['filters']['mds_rm_detected_font_awesome_plugin'] = 'Font Awesome (Official)';
if ( 'plugin' !== Assets::resolve_font_awesome_source( 'auto' ) ) {
	throw new RuntimeException( 'Auto FA mode must defer to plugin when a FA plugin is detected.' );
}
unset( $GLOBALS['filters']['mds_rm_detected_font_awesome_plugin'] );
$tag = Assets::filter_font_awesome_tag(
	'<link rel="stylesheet" id="mds-font-awesome-css" href="' . Assets::FA_CDN_URL . '" media="all" />',
	'mds-font-awesome',
	Assets::FA_CDN_URL,
	'all'
);
if ( ! str_contains( $tag, 'integrity="' . Assets::FA_CDN_INTEGRITY . '"' ) || ! str_contains( $tag, 'crossorigin="anonymous"' ) ) {
	throw new RuntimeException( 'Default cdnjs Font Awesome link tag must include SRI integrity and crossorigin attributes.' );
}
echo "Settings save checks passed.\n";

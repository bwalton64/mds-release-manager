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
function rest_ensure_response( $value ) { return new WP_REST_Response( $value ); }

require __DIR__ . '/../includes/core/class-mds-rm-audio-players.php';
require __DIR__ . '/../includes/blocks/class-mds-rm-block-defaults.php';
require __DIR__ . '/../includes/api/class-mds-rm-rest-api.php';

$GLOBALS['updates'] = array();
$defaults = array( 'release-links' => array( 'newTab' => false, 'showAudio' => true ) );
$result = MDS_RM_REST_API::save_settings_api(
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
$result = MDS_RM_REST_API::save_settings_api( new Fake_Request( array( 'mds_rm_block_defaults' => '{"release-links":{"newTab":"false"}}' ) ) );
if ( ! $result instanceof WP_Error || 'mds_rm_invalid_block_defaults' !== $result->code || $GLOBALS['updates'] ) {
	throw new RuntimeException( 'Invalid defaults must be rejected before any option is changed.' );
}
$GLOBALS['updates'] = array();
$result = MDS_RM_REST_API::save_settings_api( new Fake_Request( array() ) );
if ( ! $result instanceof WP_REST_Response || isset( $GLOBALS['updates']['mds_rm_block_defaults'] ) || isset( $GLOBALS['updates']['mds_rm_bandcamp_template'] ) || isset( $GLOBALS['updates']['mds_rm_providers'] ) ) {
	throw new RuntimeException( 'Omitted defaults and providers must not be overwritten.' );
}
$GLOBALS['updates'] = array();
$result = MDS_RM_REST_API::save_settings_api( new Fake_Request( array( 'mds_rm_providers' => 'invalid' ) ) );
if ( ! $result instanceof WP_Error || 'mds_rm_invalid_providers' !== $result->code || $GLOBALS['updates'] ) {
	throw new RuntimeException( 'Invalid providers must be rejected before any option is changed.' );
}
$GLOBALS['updates'] = array();
$result = MDS_RM_REST_API::save_settings_api(
	new Fake_Request(
		array(
			'mds_rm_audio_players'         => array( 'bandcamp', 'html5' ),
			'mds_rm_audio_player_settings' => 'not-json',
		)
	)
);
if ( ! $result instanceof WP_Error || 'mds_rm_invalid_audio_player_settings' !== $result->code || $GLOBALS['updates'] ) {
	throw new RuntimeException( 'Invalid audio player settings must be rejected before any option is changed.' );
}
$GLOBALS['updates'] = array();
$result = MDS_RM_REST_API::save_settings_api(
	new Fake_Request(
		array(
			'mds_rm_audio_players' => array( 'bandcamp', 'html5' ),
		)
	)
);
if ( ! $result instanceof WP_REST_Response || array( 'bandcamp' ) !== $GLOBALS['updates']['mds_rm_audio_players'] ) {
	throw new RuntimeException( 'Only one active audio player may be saved at a time.' );
}
echo "Settings save checks passed.\n";

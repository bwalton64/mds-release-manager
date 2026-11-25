<?php
/**
 * Standalone checks for block defaults. Run with PHP: php tests/block-defaults.php
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

class WP_Error {
	public $code;
	public function __construct( $code, $_message, $_data ) {
		$this->code = $code;
	}
}
class MDS_RM_Logger {
	public static function diagnostic( $message ) {}
}
function __( $message, $domain ) {
	return $message;
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
	return 'mds_rm_block_defaults' === $name ? $GLOBALS['defaults'] : $fallback;
}

require __DIR__ . '/../vendor/autoload.php';
use MDS\ReleaseManager\Blocks\BlockDefaults;

function check_defaults( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$GLOBALS['defaults'] = array(
	'release-meta' => array(
		'metaStyles' => array( 'color' => '#123456', 'gap' => '20px', 'padding' => '10px' ),
		'items'      => array(
			array( 'field' => 'record_label', 'enabled' => true, 'icon' => '', 'title' => 'Label' ),
			array( 'field' => 'upc_ean', 'enabled' => true, 'icon' => '', 'title' => 'UPC' ),
		),
	),
	'release-links' => array( 'newTab' => false ),
	'release-player' => array(
		'provider'        => 'local',
		'showArtwork'     => false,
		'previewSeconds'  => 20,
		'backgroundColor' => '#111827',
		'shadow'          => 'elevated',
		'maxWidth'        => '480px',
	),
	'artist-discography' => array(
		'tableStyles' => array( 'striped' => true, 'body-color' => '#123456' ),
		'style'       => array( 'color' => array( 'text' => '#123456', 'background' => '#ffffff' ), 'spacing' => array( 'padding' => '10px' ) ),
	),
);
check_defaults( true === BlockDefaults::validate( $GLOBALS['defaults'] ), 'Valid defaults must be accepted.' );
$result = BlockDefaults::resolve(
	'release-meta',
	array(
		'metaStyles'    => array( 'color' => '#ffffff', 'padding' => array( 'top' => '3px' ) ),
		'styles'        => array( 'desktop' => array( 'gap' => '7px' ) ),
		'itemOverrides' => array( 'record_label' => array( 'enabled' => false ) ),
		'itemOrder'     => array( 'upc_ean', 'record_label' ),
	)
);
check_defaults( '#ffffff' === $result['metaStyles']['color'], 'Explicit style must win.' );
check_defaults( ! isset( $result['metaStyles']['gap'] ), 'Saved legacy desktop styles must win.' );
check_defaults( array( 'top' => '3px', 'right' => '10px', 'bottom' => '10px', 'left' => '10px' ) === $result['metaStyles']['padding'], 'Spacing sides must inherit independently.' );
check_defaults( null === BlockDefaults::resolve( 'release-meta', array( 'metaStyles' => array( 'color' => null ) ) )['metaStyles']['color'], 'A block must be able to ignore an individual site default.' );
check_defaults( 'upc_ean' === $result['items'][0]['field'] && false === $result['items'][1]['enabled'], 'Field order and individual overrides must be applied.' );
$legacy = array( array( 'field' => 'upc_ean', 'enabled' => false ) );
$result = BlockDefaults::resolve( 'release-meta', array( 'items' => $legacy ) );
check_defaults( $result['items'] === $legacy, 'Saved legacy lists must remain complete overrides.' );
$result = BlockDefaults::resolve( 'release-links', array( 'newTab' => true ) );
check_defaults( true === $result['newTab'], 'Explicit boolean values must win.' );
$result = BlockDefaults::resolve( 'release-links', array() );
check_defaults( false === $result['newTab'], 'Missing boolean values must inherit.' );
$result = BlockDefaults::resolve( 'release-links', array( 'itemOrder' => array( 'Spotify', 'Bandcamp' ) ) );
check_defaults( array( 'Spotify', 'Bandcamp' ) === array_column( $result['items'], 'name' ), 'Explicit link order must work without a site-wide list.' );
$result = BlockDefaults::resolve( 'artist-discography', array( 'tableStyles' => array( 'striped' => false ) ) );
check_defaults( false === $result['tableStyles']['striped'] && '#123456' === $result['tableStyles']['body-color'], 'Discography values must inherit independently.' );
$result = BlockDefaults::resolve( 'artist-discography', array( 'textColor' => 'accent' ) );
check_defaults( ! isset( $result['style']['color']['text'] ) && '#ffffff' === $result['style']['color']['background'], 'A saved WordPress color preset must override its site default.' );
$result = BlockDefaults::resolve( 'artist-discography', array( 'style' => array( 'spacing' => array( 'padding' => array( 'top' => '3px' ) ) ) ) );
check_defaults( array( 'top' => '3px', 'right' => '10px', 'bottom' => '10px', 'left' => '10px' ) === $result['style']['spacing']['padding'], 'Native wrapper spacing sides must inherit independently.' );
$invalid = array( 'release-meta' => array( 'metaStyles' => array( 'color' => 'red;display:none' ) ) );
check_defaults( BlockDefaults::validate( $invalid ) instanceof WP_Error, 'Invalid style values must be rejected.' );
check_defaults( BlockDefaults::validate( array( 'release-links' => array( 'newTab' => 'false' ) ) ) instanceof WP_Error, 'Invalid boolean values must be rejected.' );
$player = BlockDefaults::resolve( 'release-player', array( 'showArtwork' => true, 'backgroundColor' => '' ) );
check_defaults( true === $player['showArtwork'] && '#111827' === $player['backgroundColor'] && 20 === $player['previewSeconds'] && 'elevated' === $player['shadow'], 'Release Player defaults must merge with explicit block overrides.' );
check_defaults( BlockDefaults::validate( array( 'release-player' => array( 'shadow' => 'invalid' ) ) ) instanceof WP_Error, 'Invalid Release Player shadow must be rejected.' );
check_defaults( BlockDefaults::validate( array( 'release-player' => array( 'previewSeconds' => 999 ) ) ) instanceof WP_Error, 'Out-of-range Release Player previewSeconds must be rejected.' );
echo "Block defaults checks passed.\n";

<?php
/**
 * Standalone checks for extension tab registration.
 *
 * Run with: php tests/settings-tabs.php
 */

declare(strict_types=1);
define( 'ABSPATH', __DIR__ );

class MDS_RM_Logger {
	public static $failures = array();
	public static function report_failure( $message ) {
		self::$failures[] = $message;
	}
}
function apply_filters( $hook, $value ) {
	return $GLOBALS['tabs'][ $hook ] ?? $value;
}
function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) );
}

require __DIR__ . '/../vendor/autoload.php';
use MDS\ReleaseManager\Admin\AdminInterface;
use MDS\ReleaseManager\Admin\NetworkAdmin;
class_alias( 'MDS_RM_Logger', 'MDS\\ReleaseManager\\Core\\Logger' );

$render = static function () {};
$GLOBALS['tabs'] = array(
	'mds_rm_site_settings_tabs' => array(
		'example' => array( 'label' => 'Example', 'render' => $render ),
		'release-meta' => array( 'label' => 'Collision', 'render' => $render ),
		'bad slug' => array( 'label' => 'Invalid', 'render' => $render ),
		'missing' => array( 'label' => 'No callback' ),
	),
	'mds_rm_network_settings_tabs' => array(
		'store' => array( 'label' => 'Store', 'render' => $render ),
		'network' => array( 'label' => 'Collision', 'render' => $render ),
	),
);
$site = AdminInterface::extension_tabs( 'mds_rm_site_settings_tabs', array( 'release-meta' ) );
$network = NetworkAdmin::extension_tabs();
if ( array( 'example' ) !== array_keys( $site ) || array( 'store' ) !== array_keys( $network ) || 4 !== count( MDS_RM_Logger::$failures ) ) {
	throw new RuntimeException( 'Invalid or reserved extension tabs were not rejected.' );
}
echo "Extension tab checks passed.\n";

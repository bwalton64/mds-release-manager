<?php
/**
 * Plugin Name: MDS Release Manager
 * Description: Comprehensive Artist Release Manager.
 * Version: 2.0.1
 * Requires at least: 7.0
 * Requires PHP: 8.0
 * Author: Walton International Business Group
 * Author URI: https://mustydungeon.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Update URI: https://github.com/bwalton64/mds-release-manager
 * Text Domain: mds-release-manager
 * Domain Path: /languages
 * Network: true
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'MDS_RM_VERSION' ) ) {
	define( 'MDS_RM_VERSION', '2.0.1' );
}
if ( ! defined( 'MDS_RM_API_VERSION' ) ) {
	define( 'MDS_RM_API_VERSION', '2.0.0' );
}
if ( ! defined( 'MDS_RM_FILE' ) ) {
	define( 'MDS_RM_FILE', __FILE__ );
}
if ( ! defined( 'MDS_RM_PATH' ) ) {
	define( 'MDS_RM_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'MDS_RM_URL' ) ) {
	define( 'MDS_RM_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'MDS_RM_BASENAME' ) ) {
	define( 'MDS_RM_BASENAME', plugin_basename( __FILE__ ) );
}

// Legacy bootstrap constant aliases retained for companion-plugin compatibility.
if ( ! defined( 'MDS_RELEASE_MANAGER_VERSION' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Legacy BC constant alias.
	define( 'MDS_RELEASE_MANAGER_VERSION', MDS_RM_VERSION ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Legacy backward-compatibility alias.
}
if ( ! defined( 'MDS_RM_MAIN_FILE' ) ) {
	define( 'MDS_RM_MAIN_FILE', MDS_RM_FILE );
}

$mds_rm_autoload = MDS_RM_PATH . 'vendor/autoload.php';
if ( is_readable( $mds_rm_autoload ) ) {
	require_once $mds_rm_autoload;
}

if ( class_exists( \MDS\ReleaseManager\Plugin::class ) ) {
	\MDS\ReleaseManager\Plugin::register_hooks();
}

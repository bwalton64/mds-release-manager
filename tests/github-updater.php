<?php
/**
 * Standalone checks for GitHubUpdater (private & public GitHub Releases + ExtensionApi).
 *
 * Run with: php tests/github-updater.php
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
define( 'MDS_RM_VERSION', '2.0.0' );

function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}
function esc_html( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}
function esc_html__( $value, $_domain = '' ) {
	return esc_html( $value );
}
function is_wp_error( $value ) {
	return false;
}
function get_site_transient( $key ) {
	return $GLOBALS['transients'][ $key ] ?? false;
}
function set_site_transient( $key, $value, $_ttl ) {
	$GLOBALS['transients'][ $key ] = $value;
	return true;
}
function delete_site_transient( $key ) {
	unset( $GLOBALS['transients'][ $key ] );
	return true;
}
function apply_filters( $hook, $value ) {
	return $GLOBALS['filters'][ $hook ] ?? $value;
}
function wp_remote_get( $url, $args ) {
	$GLOBALS['last_http'] = array( 'url' => $url, 'args' => $args );
	return $GLOBALS['mock_response'];
}
function wp_remote_retrieve_response_code( $response ) {
	return $response['code'] ?? 200;
}
function wp_remote_retrieve_body( $response ) {
	return $response['body'] ?? '';
}

require __DIR__ . '/../vendor/autoload.php';

use MDS\ReleaseManager\Api\ExtensionApi;
use MDS\ReleaseManager\Core\GitHubUpdater;

function assert_updater( bool $cond, string $msg ): void {
	if ( ! $cond ) {
		throw new RuntimeException( $msg );
	}
}

$sample_payload = array(
	'tag_name'     => 'v2.1.0',
	'draft'        => false,
	'prerelease'   => false,
	'html_url'     => 'https://github.com/bwalton64/mds-release-manager/releases/tag/v2.1.0',
	'published_at' => '2026-12-01T12:00:00Z',
	'body'         => 'Added GitHub Releases updater.',
	'assets'       => array(
		array(
			'name'                 => 'mds-release-manager-2.1.0.zip',
			'url'                  => 'https://api.github.com/repos/bwalton64/mds-release-manager/releases/assets/98765',
			'browser_download_url' => 'https://github.com/bwalton64/mds-release-manager/releases/download/v2.1.0/mds-release-manager-2.1.0.zip',
		),
	),
);

// 1. Public repo payload selects browser_download_url.
$public = GitHubUpdater::parse_release_payload( $sample_payload, 'mds-release-manager', false );
assert_updater( '2.1.0' === $public['version'], 'Tag v2.1.0 must normalize to 2.1.0.' );
assert_updater( str_contains( $public['package'], 'releases/download/v2.1.0/' ), 'Public mode must use browser_download_url.' );

// 2. Private repo payload selects authenticated API asset URL.
$private = GitHubUpdater::parse_release_payload( $sample_payload, 'mds-release-manager', true );
assert_updater( 'https://api.github.com/repos/bwalton64/mds-release-manager/releases/assets/98765' === $private['package'], 'Private mode must use API asset URL.' );

// 3. Drafts & prereleases are skipped.
$draft = $sample_payload;
$draft['draft'] = true;
assert_updater( null === GitHubUpdater::parse_release_payload( $draft, 'mds-release-manager', false ), 'Draft releases must be ignored.' );

// 4. Register via ExtensionApi + test private auth + update check + details modal.
GitHubUpdater::reset();
$GLOBALS['transients']    = array();
$GLOBALS['filters']       = array( 'mds_rm_github_token' => 'github_pat_secret_123' );
$GLOBALS['mock_response'] = array( 'code' => 200, 'body' => json_encode( $sample_payload ) );

assert_updater(
	ExtensionApi::register_github_updater(
		array(
			'basename' => 'mds-release-manager/mds-release-manager.php',
			'slug'     => 'mds-release-manager',
			'repo'     => 'bwalton64/mds-release-manager',
			'version'  => '2.0.0',
			'name'     => 'MDS Release Manager',
		)
	),
	'ExtensionApi::register_github_updater() must succeed.'
);

$update = GitHubUpdater::filter_update( false, array( 'Version' => '2.0.0' ), 'mds-release-manager/mds-release-manager.php' );
assert_updater( is_array( $update ) && '2.1.0' === $update['version'], 'Newer release 2.1.0 must trigger update.' );
assert_updater( 'Bearer github_pat_secret_123' === ( $GLOBALS['last_http']['args']['headers']['Authorization'] ?? '' ), 'Private API request must include Bearer token.' );

$same = GitHubUpdater::filter_update( false, array( 'Version' => '2.1.0' ), 'mds-release-manager/mds-release-manager.php' );
assert_updater( false === $same, 'Installed 2.1.0 must not offer update for 2.1.0.' );

// 5. Download auth only attaches to registered repo asset URLs and NEVER leaks to other URLs.
$auth_args = GitHubUpdater::authenticate_download( array( 'headers' => array() ), 'https://api.github.com/repos/bwalton64/mds-release-manager/releases/assets/98765' );
assert_updater( 'Bearer github_pat_secret_123' === ( $auth_args['headers']['Authorization'] ?? '' ), 'Asset download must attach Bearer token.' );
assert_updater( 'application/octet-stream' === ( $auth_args['headers']['Accept'] ?? '' ), 'Asset download must attach Accept: application/octet-stream.' );

$other_args = GitHubUpdater::authenticate_download( array( 'headers' => array() ), 'https://example.com/other.zip' );
assert_updater( ! isset( $other_args['headers']['Authorization'] ), 'Token must NEVER leak to non-GitHub URLs.' );

$other_repo = GitHubUpdater::authenticate_download( array( 'headers' => array() ), 'https://api.github.com/repos/other/repo/releases/assets/1' );
assert_updater( ! isset( $other_repo['headers']['Authorization'] ), 'Token must NEVER leak to unregistered GitHub repos.' );

// 6. plugins_api details modal returns expected object.
$info = GitHubUpdater::filter_plugin_info( false, 'plugin_information', (object) array( 'slug' => 'mds-release-manager' ) );
assert_updater( is_object( $info ) && '2.1.0' === $info->version && str_contains( $info->sections['changelog'], 'Added GitHub Releases updater.' ), 'plugins_api modal must return release changelog.' );

echo "GitHubUpdater checks passed.\n";

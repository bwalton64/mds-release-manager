<?php
/**
 * Standalone checks for the Release Links audio extension filter.
 * Run with PHP: php tests/release-links-audio.php
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

$GLOBALS['filter_calls']  = array();
$GLOBALS['filter_return'] = null;

class MDS_RM_Logger {
	public static function diagnostic( $message ) {}
	public static function report_failure( $message ) {}
}
class MDS_RM_Template_Loader {
	public static function kses_shortcode_html( $html ): string {
		return 'KSES:' . $html;
	}
}
function apply_filters( $hook, $value, ...$args ) {
	$GLOBALS['filter_calls'][] = array( $hook, $value, $args );
	return null !== $GLOBALS['filter_return'] ? $GLOBALS['filter_return'] : $value;
}
function get_option( $name, $fallback = false ) {
	return 'mds_rm_audio_players' === $name ? array( 'html5' ) : $fallback;
}
function get_post_meta( $post_id, $key, $single ) {
	return 'audio_preview_url' === $key ? 'https://example.test/preview.mp3' : '';
}
function esc_url( $value ) {
	return $value;
}

require __DIR__ . '/../includes/blocks/class-mds-rm-release-links.php';

function check_audio( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$audio = new ReflectionMethod( MDS_RM_Release_Links::class, 'audio' );
$audio->setAccessible( true );

// Extension markup wins and is routed through the iframe-aware allowlist.
$GLOBALS['filter_return'] = '<iframe src="https://bandcamp.com/EmbeddedPlayer/album=1/"></iframe>';
$result                   = $audio->invoke( null, 42, array( 'showAudio' => true ) );
check_audio( 'KSES:' . $GLOBALS['filter_return'] === $result, 'Extension audio markup must win and be sanitized.' );
check_audio( false === strpos( $result, '<audio' ), 'Built-in players must be skipped when an extension supplies markup.' );

// The filter receives the empty default, the post ID, and the attributes.
$call = end( $GLOBALS['filter_calls'] );
check_audio( 'mds_rm_release_links_audio_html' === $call[0], 'Audio filter hook name changed.' );
check_audio( '' === $call[1], 'Audio filter default value must be an empty string.' );
check_audio( array( 42, array( 'showAudio' => true ) ) === $call[2], 'Audio filter must receive the post ID and attributes.' );

// Whitespace-only and non-string filter results fall back to the HTML5 player.
foreach ( array( '', "  \n", null, false ) as $noise ) {
	$GLOBALS['filter_return'] = $noise;
	$result                   = $audio->invoke( null, 42, array() );
	check_audio( '<audio controls src="https://example.test/preview.mp3"></audio>' === $result, 'Empty filter results must fall back to the HTML5 player.' );
}

echo "Release Links audio filter tests passed.\n";

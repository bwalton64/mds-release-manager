<?php
/**
 * Standalone checks for the Audio Player Manager registry, single-select dispatch,
 * automatic HTML5 fallback, and legacy Release Links audio extension filter.
 * Run with PHP: php tests/release-links-audio.php
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

$GLOBALS['filter_calls']          = array();
$GLOBALS['filter_return']         = null;
$GLOBALS['registered_extensions'] = array();
$GLOBALS['active_players_option'] = array( 'html5' );
$GLOBALS['player_settings_opt']   = array();
$GLOBALS['preview_url']           = 'https://example.test/preview.mp3';

class WP_Error {
	public $code;
	public function __construct( $code, $_message = '', $_data = array() ) {
		$this->code = $code;
	}
}
class MDS_RM_Logger {
	public static function diagnostic( $message ) {}
	public static function report_failure( $message ) {}
}
class MDS_RM_Template_Loader {
	public static function kses_shortcode_html( $html ): string {
		return 'KSES:' . $html;
	}
}
class MDS_RM_Release_Links_Block {
	public static function style_value( $value, $type ) {
		return is_string( $value ) && preg_match( '/^#[0-9a-f]{6}$/i', $value ) ? strtolower( $value ) : '';
	}
}
function __( $text, $_domain = '' ) {
	return $text;
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function sanitize_text_field( $str ) {
	return trim( strip_tags( (string) $str ) );
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function apply_filters( $hook, $value, ...$args ) {
	$GLOBALS['filter_calls'][] = array( $hook, $value, $args );
	if ( 'mds_rm_register_audio_players' === $hook ) {
		return array_merge( $value, $GLOBALS['registered_extensions'] );
	}
	return null !== $GLOBALS['filter_return'] ? $GLOBALS['filter_return'] : $value;
}
function get_option( $name, $fallback = false ) {
	if ( 'mds_rm_audio_players' === $name ) {
		return $GLOBALS['active_players_option'];
	}
	if ( 'mds_rm_audio_player_settings' === $name ) {
		return $GLOBALS['player_settings_opt'];
	}
	return $fallback;
}
function get_post_meta( $post_id, $key, $single ) {
	return 'audio_preview_url' === $key ? $GLOBALS['preview_url'] : '';
}
function esc_url( $value ) {
	return $value;
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../includes/legacy-class-aliases.php';
class_alias( 'MDS_RM_Logger', 'MDS\\ReleaseManager\\Core\\Logger' );
class_alias( 'MDS_RM_Template_Loader', 'MDS\\ReleaseManager\\Core\\TemplateLoader' );
class_alias( 'MDS_RM_Release_Links_Block', 'MDS\\ReleaseManager\\Blocks\\ReleaseLinksBlock' );

function check_audio( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$audio = new ReflectionMethod( MDS_RM_Release_Links::class, 'audio' );
$audio->setAccessible( true );

// 1. Legacy extension markup filter wins when no registered third-party player overrides it.
$GLOBALS['filter_return'] = '<iframe src="https://bandcamp.com/EmbeddedPlayer/album=1/"></iframe>';
$result                   = $audio->invoke( null, 42, array( 'showAudio' => true ) );
check_audio( 'KSES:' . $GLOBALS['filter_return'] === $result, 'Extension audio markup must win and be sanitized.' );
check_audio( false === strpos( $result, '<audio' ), 'Built-in players must be skipped when an extension supplies markup.' );

// The filter receives the empty default, the post ID, and the attributes.
$call = end( $GLOBALS['filter_calls'] );
check_audio( 'mds_rm_release_links_audio_html' === $call[0], 'Audio filter hook name changed.' );
check_audio( '' === $call[1], 'Audio filter default value must be an empty string.' );
check_audio( array( 42, array( 'showAudio' => true ) ) === $call[2], 'Audio filter must receive the post ID and attributes.' );

// 2. Whitespace-only and non-string filter results fall back to the HTML5 player.
foreach ( array( '', "  \n", null, false ) as $noise ) {
	$GLOBALS['filter_return'] = $noise;
	$result                   = $audio->invoke( null, 42, array() );
	check_audio( '<audio controls src="https://example.test/preview.mp3"></audio>' === $result, 'Empty filter results must fall back to the HTML5 player.' );
}

// 3. Registered third-party player via Audio Player Manager (`mds_rm_register_audio_players`).
$GLOBALS['filter_return']         = null;
$GLOBALS['bandcamp_configured']   = true;
$GLOBALS['received_bc_settings']  = null;
$GLOBALS['registered_extensions'] = array(
	'bandcamp' => array(
		'label'           => 'Bandcamp Album Player',
		'description'     => 'Embedded Bandcamp player.',
		'icon'            => 'dashicons dashicons-album',
		'render_callback' => static function ( int $post_id, array $settings, array $_attrs ): string {
			$GLOBALS['received_bc_settings'] = $settings;
			if ( ! $GLOBALS['bandcamp_configured'] ) {
				return '';
			}
			return '<iframe src="https://bandcamp.com/EmbeddedPlayer/album=' . $post_id . '/bgcol=' . ltrim( $settings['bgcol'], '#' ) . '/"></iframe>';
		},
		'fields'          => array(
			'bgcol' => array(
				'type'    => 'color',
				'label'   => 'Background Color',
				'default' => '#ffffff',
			),
			'size'  => array(
				'type'    => 'select',
				'label'   => 'Size',
				'default' => 'small',
				'options' => array(
					'small' => 'Small',
					'large' => 'Large',
				),
			),
		),
	),
);

// When HTML5 is the selected active player in site-wide settings, the 3rd-party player is skipped.
$GLOBALS['active_players_option'] = array( 'html5' );
$result                           = $audio->invoke( null, 42, array() );
check_audio( '<audio controls src="https://example.test/preview.mp3"></audio>' === $result, 'When HTML5 is selected, third-party players must not render.' );

// When Bandcamp is the selected active player and properly configured on the release, Bandcamp renders with its saved/default settings.
$GLOBALS['active_players_option'] = array( 'bandcamp' );
$GLOBALS['player_settings_opt']   = array( 'bandcamp' => array( 'bgcol' => '#123456' ) );
$result                           = $audio->invoke( null, 42, array() );
check_audio( 'KSES:<iframe src="https://bandcamp.com/EmbeddedPlayer/album=42/bgcol=123456/"></iframe>' === $result, 'Configured active third-party player must render with its merged settings.' );
check_audio( array( 'bgcol' => '#123456', 'size' => 'small' ) === $GLOBALS['received_bc_settings'], 'Default field values must merge with saved player settings.' );

// When Bandcamp is selected in site-wide settings, but NOT configured on this release, it automatically falls back to the core HTML5 player.
$GLOBALS['bandcamp_configured'] = false;
$result                         = $audio->invoke( null, 42, array() );
check_audio( '<audio controls src="https://example.test/preview.mp3"></audio>' === $result, 'Unconfigured third-party player on a release must automatically fall back to the core HTML5 player.' );

// Legacy multi-checkbox array containing `html5` and `blocks_for_bandcamp` normalizes to `bandcamp` as the active player.
$GLOBALS['bandcamp_configured']   = true;
$GLOBALS['active_players_option'] = array( 'html5', 'blocks_for_bandcamp' );
check_audio( 'bandcamp' === MDS_RM_Audio_Players::get_active_player(), 'Legacy multi-checkbox option with blocks_for_bandcamp must resolve to bandcamp.' );

echo "Release Links audio filter tests passed.\n";

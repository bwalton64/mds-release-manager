<?php
/**
 * Standalone tests for the Unified Audio API registry, priority fallback,
 * manifest normalization, native timed preview flags, and transient caching.
 * Run with PHP: php tests/audio-api.php
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

$GLOBALS['mds_test_options']    = array();
$GLOBALS['mds_test_transients'] = array();
$GLOBALS['mds_test_post_meta']  = array();
$GLOBALS['mds_test_providers']  = array();
$GLOBALS['mds_test_logs']       = array();

function __( string $text, string $_domain = '' ): string {
	return $text;
}
function sanitize_key( string $key ): string {
	return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
}
function sanitize_text_field( string $str ): string {
	return trim( strip_tags( $str ) );
}
function esc_url_raw( string $url ): string {
	return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
}
function wp_parse_url( string $url, int $component = -1 ) {
	return parse_url( $url, $component );
}
function get_locale(): string {
	return 'en_US';
}
function determine_locale(): string {
	return 'en_US';
}
function did_action( string $_hook ): int {
	return 0;
}
function get_option( string $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['mds_test_options'] ) ? $GLOBALS['mds_test_options'][ $name ] : $default;
}
function get_transient( string $key ) {
	return array_key_exists( $key, $GLOBALS['mds_test_transients'] ) ? $GLOBALS['mds_test_transients'][ $key ] : false;
}
function set_transient( string $key, $value, int $_ttl = 0 ): bool {
	$GLOBALS['mds_test_transients'][ $key ] = $value;
	return true;
}
function delete_transient( string $key ): bool {
	unset( $GLOBALS['mds_test_transients'][ $key ] );
	return true;
}
function get_post_meta( int $post_id, string $key, bool $_single = true ) {
	return $GLOBALS['mds_test_post_meta'][ $post_id ][ $key ] ?? '';
}
function get_the_title( int $post_id ): string {
	return 'Release #' . $post_id;
}
function get_the_post_thumbnail_url( int $post_id, string $_size = 'medium' ): string {
	return 'https://example.test/art-' . $post_id . '.jpg';
}
function apply_filters( string $hook, $value, ...$_args ) {
	if ( 'mds_rm_audio_providers' === $hook ) {
		return array_merge( $value, $GLOBALS['mds_test_providers'] );
	}
	return $value;
}

require __DIR__ . '/../vendor/autoload.php';

use MDS\ReleaseManager\Core\Audio\AudioApi;
use MDS\ReleaseManager\Core\Audio\AudioProviderInterface;

function assert_true( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
}

final class MockAudioProvider implements AudioProviderInterface {
	private string $slug;
	private string $label;
	private int $priority;
	/** @var callable */
	private $supports_cb;
	/** @var callable */
	private $resolve_cb;
	public int $resolve_calls = 0;

	public function __construct( string $slug, string $label, int $priority, callable $supports_cb, callable $resolve_cb ) {
		$this->slug        = $slug;
		$this->label       = $label;
		$this->priority    = $priority;
		$this->supports_cb = $supports_cb;
		$this->resolve_cb  = $resolve_cb;
	}

	public function get_slug(): string {
		return $this->slug;
	}
	public function get_label(): string {
		return $this->label;
	}
	public function get_priority(): int {
		return $this->priority;
	}
	public function supports_release( int $post_id ): bool {
		return (bool) ( $this->supports_cb )( $post_id );
	}
	public function resolve( int $post_id, array $context = array() ): ?array {
		++$this->resolve_calls;
		return ( $this->resolve_cb )( $post_id, $context );
	}
}

// 1. Built-in LocalPreviewProvider resolves audio_preview_url.
$GLOBALS['mds_test_post_meta'][101] = array(
	'audio_preview_url' => 'https://cdn.example.test/sample.m4a',
);
assert_true( AudioApi::has_audio( 101 ), 'LocalPreviewProvider should support release with audio_preview_url.' );
assert_true( ! AudioApi::has_audio( 102 ), 'Release without audio meta should return has_audio=false.' );

$local_manifest = AudioApi::resolve( 101 );
assert_true( is_array( $local_manifest ), 'Local manifest should resolve.' );
assert_true( 'local' === $local_manifest['provider'], 'Provider should be local.' );
assert_true( true === $local_manifest['isTimedPreview'], 'Local preview should default to isTimedPreview=true.' );
assert_true( 'audio/mp4' === $local_manifest['tracks'][0]['mimeType'], 'MIME type should detect .m4a as audio/mp4.' );
assert_true( 'https://example.test/art-101.jpg' === $local_manifest['artworkUrl'], 'Artwork should fall back to featured image.' );

// 2. Priority ordering, provider failure fallback, and native timed preview preservation.
$broken_provider = new MockAudioProvider(
	'broken',
	'Broken Provider',
	5,
	static fn( int $id ): bool => 200 === $id,
	static function (): ?array {
		throw new RuntimeException( 'Simulated upstream timeout' );
	}
);
$apple_provider = new MockAudioProvider(
	'apple_music',
	'Apple Music',
	20,
	static fn( int $id ): bool => 200 === $id,
	static fn( int $id ): array => array(
		'isTimedPreview' => true,
		'ttl'            => 7200,
		'tracks'         => array(
			array(
				'title'          => 'Intro',
				'duration'       => 30,
				'streamUrl'      => 'https://audio-ssl.itunes.apple.com/preview-1.m4a',
				'mimeType'       => 'audio/mp4',
				'isTimedPreview' => true,
			),
		),
	)
);
$bandcamp_provider = new MockAudioProvider(
	'bandcamp',
	'Bandcamp',
	30,
	static fn( int $id ): bool => 200 === $id,
	static fn( int $id ): array => array(
		'isTimedPreview' => false,
		'ttl'            => 1800,
		'tracks'         => array(
			array(
				'title'          => 'Full Track',
				'duration'       => 240,
				'streamUrl'      => 'https://t4.bcbits.com/stream/abc/mp3-128/1',
				'mimeType'       => 'audio/mpeg',
				'isTimedPreview' => false,
			),
		),
	)
);

$GLOBALS['mds_test_providers'] = array(
	'broken'      => $broken_provider,
	'apple_music' => $apple_provider,
	'bandcamp'    => $bandcamp_provider,
);

$manifest = AudioApi::resolve( 200 );
assert_true( is_array( $manifest ) && 'apple_music' === $manifest['provider'], 'Should fall back from throwing provider to Apple Music.' );
assert_true( true === $manifest['isTimedPreview'], 'Apple Music manifest must preserve isTimedPreview=true.' );
assert_true( 1 === $apple_provider->resolve_calls, 'Apple Music should be called once.' );

// 3. Cached manifest avoids repeat provider calls.
$cached = AudioApi::resolve( 200 );
assert_true( 1 === $apple_provider->resolve_calls, 'Second resolve should hit transient cache.' );
assert_true( 'apple_music' === $cached['provider'], 'Cached manifest should match.' );

// 4. Custom priority order + cache invalidation switches active provider.
$GLOBALS['mds_test_options'][ AudioApi::OPTION_PROVIDER_PRIORITY ] = array( 'bandcamp', 'apple_music', 'local' );
AudioApi::invalidate_release( 200 );
$bc_manifest = AudioApi::resolve( 200 );
assert_true( is_array( $bc_manifest ) && 'bandcamp' === $bc_manifest['provider'], 'Custom priority should prefer Bandcamp.' );
assert_true( false === $bc_manifest['isTimedPreview'], 'Bandcamp full stream must preserve isTimedPreview=false.' );

// 5. Disabled provider is skipped.
$GLOBALS['mds_test_options'][ AudioApi::OPTION_DISABLED_PROVIDERS ] = array( 'bandcamp' );
AudioApi::invalidate_release( 200 );
$after_disable = AudioApi::resolve( 200 );
assert_true( is_array( $after_disable ) && 'apple_music' === $after_disable['provider'], 'Disabled provider must be skipped.' );

echo "AudioApi tests passed.\n";

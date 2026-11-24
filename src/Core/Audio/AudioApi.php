<?php
/**
 * Unified Audio API registry, priority fallback resolver, and manifest cache.
 *
 * @package MDS\ReleaseManager
 * @since   1.2.0
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Core\Audio;

use MDS\ReleaseManager\Config;
use MDS\ReleaseManager\Core\Logger;
use MDS\ReleaseManager\Core\TemplateLoader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central orchestrator for resolving normalized audio preview manifests across
 * built-in and extension audio providers.
 *
 * @since 1.2.0
 */
final class AudioApi {

	/**
	 * Option storing ordered provider slugs for fallback priority.
	 *
	 * @var string
	 */
	public const OPTION_PROVIDER_PRIORITY = Config::OPTION_AUDIO_PROVIDER_PRIORITY;

	/**
	 * Option storing explicitly disabled provider slugs.
	 *
	 * @var string
	 */
	public const OPTION_DISABLED_PROVIDERS = Config::OPTION_AUDIO_DISABLED_PROVIDERS;

	/**
	 * Default manifest cache TTL in seconds (1 hour).
	 *
	 * @var int
	 */
	public const DEFAULT_TTL = 3600;

	/**
	 * Maximum allowed manifest cache TTL in seconds (24 hours).
	 *
	 * @var int
	 */
	public const MAX_TTL = 86400;

	/**
	 * Minimum safety buffer in seconds before a cached manifest's `expiresAt` is treated as stale.
	 *
	 * @var int
	 */
	private const EXPIRY_BUFFER = 30;

	/**
	 * Per-request memoized provider registry (including disabled providers).
	 *
	 * @var array<string, AudioProviderInterface>|null
	 */
	private static ?array $providers_cache = null;

	/**
	 * Registers cache invalidation hooks for Release updates.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'save_post_release', array( self::class, 'handle_release_change' ), 20, 1 );
		add_action( 'deleted_post', array( self::class, 'handle_release_change' ), 20, 1 );
	}

	/**
	 * Invalidates cached audio manifests when a Release post is saved or deleted.
	 *
	 * @since 1.2.0
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function handle_release_change( int $post_id ): void {
		if ( $post_id <= 0 ) {
			return;
		}
		if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( function_exists( 'get_post_type' ) && Config::CPT_RELEASE !== get_post_type( $post_id ) ) {
			return;
		}

		self::invalidate_release( $post_id );
	}

	/**
	 * Clears per-request provider memoization (useful in tests or after option updates).
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function reset_cache(): void {
		self::$providers_cache = null;
	}

	/**
	 * Returns all registered audio providers ordered by configured priority.
	 *
	 * Extensions register providers implementing `AudioProviderInterface` via the
	 * `mds_rm_audio_providers` filter.
	 *
	 * @since 1.2.0
	 *
	 * @param bool $include_disabled Whether to include providers disabled in settings.
	 * @return array<string, AudioProviderInterface> Ordered providers keyed by slug.
	 */
	public static function get_providers( bool $include_disabled = false ): array {
		$all = self::discover_providers();

		$disabled = self::get_disabled_slugs();
		$filtered = $include_disabled
			? $all
			: array_filter(
				$all,
				static fn( AudioProviderInterface $provider, string $slug ): bool => ! in_array( $slug, $disabled, true ),
				ARRAY_FILTER_USE_BOTH
			);

		$order = self::get_priority_order();
		$rank  = array_flip( $order );

		uasort(
			$filtered,
			static function ( AudioProviderInterface $a, AudioProviderInterface $b ) use ( $rank ): int {
				$slug_a = $a->get_slug();
				$slug_b = $b->get_slug();
				$has_a  = isset( $rank[ $slug_a ] );
				$has_b  = isset( $rank[ $slug_b ] );

				if ( $has_a && $has_b ) {
					return $rank[ $slug_a ] <=> $rank[ $slug_b ];
				}
				if ( $has_a !== $has_b ) {
					return $has_a ? -1 : 1;
				}

				$prio = $a->get_priority() <=> $b->get_priority();
				return 0 !== $prio ? $prio : strcmp( $slug_a, $slug_b );
			}
		);

		return $filtered;
	}

	/**
	 * Returns serializable metadata for all registered audio providers.
	 *
	 * @since 1.2.0
	 *
	 * @return array<int, array{slug: string, label: string, priority: int, enabled: bool}> Provider descriptors.
	 */
	public static function get_provider_descriptors(): array {
		$disabled = self::get_disabled_slugs();
		$result   = array();
		$index    = 0;

		foreach ( self::get_providers( true ) as $slug => $provider ) {
			++$index;
			$result[] = array(
				'slug'     => $slug,
				'label'    => $provider->get_label(),
				'priority' => $index * 10,
				'enabled'  => ! in_array( $slug, $disabled, true ),
			);
		}

		return $result;
	}

	/**
	 * Performs a fast local check (no remote HTTP requests) for whether any enabled
	 * provider can potentially serve audio for a release.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $post_id  Release post ID.
	 * @param string $provider Optional explicit provider slug to check.
	 * @return bool True when at least one enabled provider supports the release.
	 */
	public static function has_audio( int $post_id, string $provider = '' ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		$providers = self::get_providers( false );
		if ( '' !== $provider ) {
			$slug = sanitize_key( $provider );
			if ( ! isset( $providers[ $slug ] ) ) {
				return false;
			}
			return $providers[ $slug ]->supports_release( $post_id );
		}

		foreach ( $providers as $candidate ) {
			if ( $candidate->supports_release( $post_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolves a normalized audio preview manifest for a release.
	 *
	 * Tries providers in configured priority order (or a single explicit provider)
	 * and caches valid manifests in WordPress transients respecting each provider's TTL.
	 *
	 * @since 1.2.0
	 *
	 * @param int                  $post_id Release post ID.
	 * @param array<string, mixed> $args {
	 *     Optional resolution arguments.
	 *
	 *     @type string $provider       Explicit provider slug to query.
	 *     @type bool   $fresh          Bypass transient cache and resolve live. Default false.
	 *     @type bool   $allow_fallback Fall back to remaining providers when `$provider` fails. Default true.
	 * }
	 * @return array<string, mixed>|null Normalized AudioManifest, or null if no audio is available.
	 */
	public static function resolve( int $post_id, array $args = array() ): ?array {
		if ( $post_id <= 0 ) {
			return null;
		}

		$requested_provider = isset( $args['provider'] ) && is_string( $args['provider'] )
			? sanitize_key( $args['provider'] )
			: '';
		$fresh              = ! empty( $args['fresh'] );
		$allow_fallback     = ! array_key_exists( 'allow_fallback', $args ) || (bool) $args['allow_fallback'];

		$cache_key = self::cache_key( $post_id, '' !== $requested_provider ? $requested_provider : 'auto' );

		if ( ! $fresh && function_exists( 'get_transient' ) ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && self::is_manifest_fresh( $cached ) ) {
				return $cached;
			}
		}

		$candidates = self::select_candidates( $requested_provider, $allow_fallback );
		$manifest   = null;

		foreach ( $candidates as $slug => $provider ) {
			try {
				if ( ! $provider->supports_release( $post_id ) ) {
					continue;
				}
				$raw = $provider->resolve( $post_id, $args );
				if ( ! is_array( $raw ) ) {
					continue;
				}
				$normalized = self::normalize_manifest( $raw, $provider, $post_id );
				if ( null !== $normalized ) {
					$manifest = $normalized;
					break;
				}
			} catch ( \Throwable $e ) {
				if ( class_exists( Logger::class ) ) {
					Logger::report_failure(
						sprintf( 'Audio provider "%s" failed for release %d: %s', $slug, $post_id, $e->getMessage() )
					);
				}
			}
		}

		/**
		 * Filters the resolved normalized audio manifest for a release.
		 *
		 * @since 1.2.0
		 *
		 * @param array<string, mixed>|null $manifest Normalized manifest or null.
		 * @param int                       $post_id  Release post ID.
		 * @param array<string, mixed>      $args     Resolution arguments.
		 */
		$filtered = apply_filters( 'mds_rm_audio_manifest', $manifest, $post_id, $args );
		$manifest = is_array( $filtered ) ? $filtered : null;

		if ( null !== $manifest && function_exists( 'set_transient' ) ) {
			$ttl = isset( $manifest['ttl'] ) && is_int( $manifest['ttl'] ) ? $manifest['ttl'] : self::DEFAULT_TTL;
			if ( $ttl > 0 ) {
				set_transient( $cache_key, $manifest, min( max( 60, $ttl ), self::MAX_TTL ) );
			}
		}

		return $manifest;
	}

	/**
	 * Invalidates cached audio manifests for a single release across all providers.
	 *
	 * @since 1.2.0
	 *
	 * @param int $post_id Release post ID.
	 * @return void
	 */
	public static function invalidate_release( int $post_id ): void {
		if ( $post_id <= 0 || ! function_exists( 'delete_transient' ) ) {
			return;
		}

		delete_transient( self::cache_key( $post_id, 'auto' ) );

		foreach ( array_keys( self::get_providers( true ) ) as $slug ) {
			delete_transient( self::cache_key( $post_id, $slug ) );
		}
	}

	/**
	 * Validates and normalizes a raw provider manifest into the canonical schema.
	 *
	 * @since 1.2.0
	 *
	 * @param array<string, mixed>   $raw      Raw manifest returned by a provider.
	 * @param AudioProviderInterface $provider Provider instance that generated it.
	 * @param int                    $post_id  Release post ID.
	 * @return array<string, mixed>|null Normalized manifest, or null if it has neither tracks nor embedHtml.
	 */
	public static function normalize_manifest( array $raw, AudioProviderInterface $provider, int $post_id ): ?array {
		$tracks = array();
		if ( isset( $raw['tracks'] ) && is_array( $raw['tracks'] ) ) {
			$idx = 0;
			foreach ( $raw['tracks'] as $track ) {
				if ( ! is_array( $track ) ) {
					continue;
				}
				$stream_url = isset( $track['streamUrl'] ) && is_string( $track['streamUrl'] )
					? self::clean_url( $track['streamUrl'] )
					: '';
				if ( '' === $stream_url ) {
					continue;
				}
				++$idx;
				$duration    = isset( $track['duration'] ) && is_numeric( $track['duration'] )
					? max( 0.0, (float) $track['duration'] )
					: 0.0;
				$track_title = isset( $track['title'] ) && is_string( $track['title'] ) && '' !== trim( $track['title'] )
					? sanitize_text_field( $track['title'] )
					: sprintf(
						/* translators: %d: 1-based track number. */
						__( 'Track %d', 'mds-release-manager' ),
						$idx
					);

				$tracks[] = array(
					'index'          => isset( $track['index'] ) && is_numeric( $track['index'] ) ? max( 1, (int) $track['index'] ) : $idx,
					'title'          => $track_title,
					'artist'         => isset( $track['artist'] ) && is_string( $track['artist'] ) ? sanitize_text_field( $track['artist'] ) : '',
					'duration'       => $duration,
					'streamUrl'      => $stream_url,
					'mimeType'       => isset( $track['mimeType'] ) && is_string( $track['mimeType'] ) && '' !== trim( $track['mimeType'] )
						? sanitize_text_field( $track['mimeType'] )
						: 'audio/mpeg',
					'isTimedPreview' => array_key_exists( 'isTimedPreview', $track )
						? (bool) $track['isTimedPreview']
						: ( ! empty( $raw['isTimedPreview'] ) ),
					'sourceUrl'      => isset( $track['sourceUrl'] ) && is_string( $track['sourceUrl'] ) ? self::clean_url( $track['sourceUrl'] ) : '',
				);
			}
		}

		$embed_html = '';
		if ( isset( $raw['embedHtml'] ) && is_string( $raw['embedHtml'] ) && '' !== trim( $raw['embedHtml'] ) ) {
			$embed_html = class_exists( TemplateLoader::class )
				? TemplateLoader::kses_shortcode_html( $raw['embedHtml'] )
				: $raw['embedHtml'];
		}

		if ( empty( $tracks ) && '' === $embed_html ) {
			return null;
		}

		$ttl = isset( $raw['ttl'] ) && is_numeric( $raw['ttl'] )
			? min( max( 0, (int) $raw['ttl'] ), self::MAX_TTL )
			: self::DEFAULT_TTL;

		$now        = time();
		$expires_at = isset( $raw['expiresAt'] ) && is_numeric( $raw['expiresAt'] )
			? (int) $raw['expiresAt']
			: ( $ttl > 0 ? $now + $ttl : $now );

		$title = isset( $raw['title'] ) && is_string( $raw['title'] ) && '' !== trim( $raw['title'] )
			? sanitize_text_field( $raw['title'] )
			: ( function_exists( 'get_the_title' ) ? (string) get_the_title( $post_id ) : '' );

		$artwork_url = isset( $raw['artworkUrl'] ) && is_string( $raw['artworkUrl'] )
			? self::clean_url( $raw['artworkUrl'] )
			: '';
		if ( '' === $artwork_url && function_exists( 'get_the_post_thumbnail_url' ) ) {
			$thumb = get_the_post_thumbnail_url( $post_id, 'medium' );
			if ( is_string( $thumb ) ) {
				$artwork_url = self::clean_url( $thumb );
			}
		}

		$artist = isset( $raw['artist'] ) && is_string( $raw['artist'] )
			? sanitize_text_field( $raw['artist'] )
			: self::resolve_release_artist_label( $post_id );

		return array(
			'provider'       => $provider->get_slug(),
			'providerLabel'  => isset( $raw['providerLabel'] ) && is_string( $raw['providerLabel'] ) && '' !== trim( $raw['providerLabel'] )
				? sanitize_text_field( $raw['providerLabel'] )
				: $provider->get_label(),
			'releaseId'      => $post_id,
			'title'          => $title,
			'artist'         => $artist,
			'artworkUrl'     => $artwork_url,
			'sourceUrl'      => isset( $raw['sourceUrl'] ) && is_string( $raw['sourceUrl'] ) ? self::clean_url( $raw['sourceUrl'] ) : '',
			'isTimedPreview' => array_key_exists( 'isTimedPreview', $raw ) ? (bool) $raw['isTimedPreview'] : true,
			'embedHtml'      => $embed_html,
			'ttl'            => $ttl,
			'expiresAt'      => $expires_at,
			'tracks'         => $tracks,
		);
	}

	/**
	 * Discovers built-in and extension audio providers via `mds_rm_audio_providers`.
	 *
	 * @return array<string, AudioProviderInterface> Valid provider instances keyed by slug.
	 */
	private static function discover_providers(): array {
		$use_cache = function_exists( 'did_action' ) && did_action( 'init' ) > 0;
		if ( $use_cache && null !== self::$providers_cache ) {
			return self::$providers_cache;
		}

		$local    = new LocalPreviewProvider();
		$defaults = array(
			$local->get_slug() => $local,
		);

		/**
		 * Filters the registered unified audio preview providers.
		 *
		 * Companion plugins (such as `mds-audio-providers`) append instances
		 * implementing `MDS\ReleaseManager\Core\Audio\AudioProviderInterface`.
		 *
		 * @since 1.2.0
		 *
		 * @param array<string, AudioProviderInterface> $providers Provider instances keyed by slug.
		 */
		$filtered = apply_filters( 'mds_rm_audio_providers', $defaults );
		if ( ! is_array( $filtered ) ) {
			return $defaults;
		}

		$valid = array();
		foreach ( $filtered as $candidate ) {
			if ( ! $candidate instanceof AudioProviderInterface ) {
				continue;
			}
			$slug = sanitize_key( $candidate->get_slug() );
			if ( '' === $slug ) {
				continue;
			}
			$valid[ $slug ] = $candidate;
		}

		if ( ! isset( $valid[ $local->get_slug() ] ) ) {
			$valid[ $local->get_slug() ] = $local;
		}

		if ( $use_cache ) {
			self::$providers_cache = $valid;
		}

		return $valid;
	}

	/**
	 * Selects the ordered candidate providers for a resolution request.
	 *
	 * @param string $requested_provider Optional explicit provider slug.
	 * @param bool   $allow_fallback     Whether to append remaining providers after the requested one.
	 * @return array<string, AudioProviderInterface> Ordered candidates.
	 */
	private static function select_candidates( string $requested_provider, bool $allow_fallback ): array {
		$enabled = self::get_providers( false );
		if ( '' === $requested_provider ) {
			return $enabled;
		}

		$candidates = array();
		if ( isset( $enabled[ $requested_provider ] ) ) {
			$candidates[ $requested_provider ] = $enabled[ $requested_provider ];
		}

		if ( $allow_fallback ) {
			foreach ( $enabled as $slug => $provider ) {
				if ( ! isset( $candidates[ $slug ] ) ) {
					$candidates[ $slug ] = $provider;
				}
			}
		}

		return $candidates;
	}

	/**
	 * Returns the configured provider priority slug list.
	 *
	 * @return string[] Ordered provider slugs.
	 */
	private static function get_priority_order(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}
		$stored = get_option( self::OPTION_PROVIDER_PRIORITY, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$order = array();
		foreach ( $stored as $item ) {
			if ( is_string( $item ) ) {
				$slug = sanitize_key( $item );
				if ( '' !== $slug && ! in_array( $slug, $order, true ) ) {
					$order[] = $slug;
				}
			}
		}
		return $order;
	}

	/**
	 * Returns the list of explicitly disabled provider slugs.
	 *
	 * @return string[] Disabled provider slugs.
	 */
	private static function get_disabled_slugs(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}
		$stored = get_option( self::OPTION_DISABLED_PROVIDERS, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$disabled = array();
		foreach ( $stored as $item ) {
			if ( is_string( $item ) ) {
				$slug = sanitize_key( $item );
				if ( '' !== $slug && ! in_array( $slug, $disabled, true ) ) {
					$disabled[] = $slug;
				}
			}
		}
		return $disabled;
	}

	/**
	 * Builds the transient cache key for a release and provider scope.
	 *
	 * @param int    $post_id Release post ID.
	 * @param string $scope   Provider slug or `auto`.
	 * @return string Transient key.
	 */
	private static function cache_key( int $post_id, string $scope ): string {
		$suffix = 'audio_' . $post_id . '_' . sanitize_key( $scope );
		return Config::transient_key( $suffix );
	}

	/**
	 * Checks whether a cached manifest is still valid and not about to expire.
	 *
	 * @param array<string, mixed> $manifest Cached manifest.
	 * @return bool True when still usable.
	 */
	private static function is_manifest_fresh( array $manifest ): bool {
		if ( empty( $manifest['tracks'] ) && empty( $manifest['embedHtml'] ) ) {
			return false;
		}
		$expires_at = isset( $manifest['expiresAt'] ) && is_numeric( $manifest['expiresAt'] )
			? (int) $manifest['expiresAt']
			: 0;

		return $expires_at > ( time() + self::EXPIRY_BUFFER );
	}

	/**
	 * Resolves a human-readable artist label for a release post when omitted by a provider.
	 *
	 * @param int $post_id Release post ID.
	 * @return string Artist display string, or empty string.
	 */
	private static function resolve_release_artist_label( int $post_id ): string {
		if ( ! function_exists( 'get_post_meta' ) ) {
			return '';
		}
		$artist_ids = get_post_meta( $post_id, 'artist_ids', true );
		if ( ! is_array( $artist_ids ) || empty( $artist_ids ) ) {
			return '';
		}

		$names = array();
		foreach ( $artist_ids as $artist_id ) {
			$id = (int) $artist_id;
			if ( $id > 0 && function_exists( 'get_the_title' ) ) {
				$name = trim( (string) get_the_title( $id ) );
				if ( '' !== $name ) {
					$names[] = $name;
				}
			}
		}

		return implode( ', ', $names );
	}

	/**
	 * Sanitizes an HTTP(S) URL string.
	 *
	 * @param string $url Raw URL.
	 * @return string Clean URL, or empty string.
	 */
	private static function clean_url( string $url ): string {
		$trimmed = trim( $url );
		if ( '' === $trimmed ) {
			return '';
		}
		$clean = function_exists( 'esc_url_raw' ) ? esc_url_raw( $trimmed ) : $trimmed;
		return is_string( $clean ) ? $clean : '';
	}
}

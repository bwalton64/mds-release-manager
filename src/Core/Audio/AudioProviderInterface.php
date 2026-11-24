<?php
/**
 * Audio Provider interface for the unified MDS Release Manager Audio API.
 *
 * @package MDS\ReleaseManager
 * @since   1.2.0
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Core\Audio;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract implemented by all built-in and extension audio preview providers.
 *
 * Providers supply normalized audio preview manifests (preferring native
 * server-timed previews such as Apple Music, Deezer, SoundCloud `/preview`,
 * or Spotify `preview_url` when available) without transcoding or hosting
 * audio files on the WordPress server.
 *
 * @since 1.2.0
 */
interface AudioProviderInterface {

	/**
	 * Returns the unique sanitized slug for this provider (e.g. `local`, `apple_music`, `bandcamp`).
	 *
	 * @since 1.2.0
	 *
	 * @return string Provider slug.
	 */
	public function get_slug(): string;

	/**
	 * Returns the human-readable provider display name.
	 *
	 * @since 1.2.0
	 *
	 * @return string Display label.
	 */
	public function get_label(): string;

	/**
	 * Returns the default resolution priority (lower numbers are tried first).
	 *
	 * @since 1.2.0
	 *
	 * @return int Default priority.
	 */
	public function get_priority(): int;

	/**
	 * Performs a fast, local-only check (no remote HTTP requests) to determine
	 * whether this provider can potentially resolve audio for the given release.
	 *
	 * @since 1.2.0
	 *
	 * @param int $post_id Release post ID.
	 * @return bool True when the release has the required identifier/meta for this provider.
	 */
	public function supports_release( int $post_id ): bool;

	/**
	 * Resolves a raw audio manifest for the given release.
	 *
	 * Returned arrays are validated and normalized by `AudioApi::normalize_manifest()`.
	 * Return `null` when no playable audio preview is available from this provider.
	 *
	 * @since 1.2.0
	 *
	 * @param int                  $post_id Release post ID.
	 * @param array<string, mixed> $context Optional resolution context (`fresh`, etc.).
	 * @return array<string, mixed>|null Raw audio manifest array, or null if unavailable.
	 */
	public function resolve( int $post_id, array $context = array() ): ?array;
}

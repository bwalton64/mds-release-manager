<?php
/**
 * Built-in local Audio Preview URL provider for the unified Audio API.
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
 * Resolves the core `audio_preview_url` post meta field into a normalized AudioManifest.
 *
 * @since 1.2.0
 */
final class LocalPreviewProvider implements AudioProviderInterface {

	/**
	 * {@inheritDoc}
	 */
	public function get_slug(): string {
		return 'local';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Local Audio Preview', 'mds-release-manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 10;
	}

	/**
	 * Checks whether the release has a non-empty `audio_preview_url` meta value.
	 *
	 * @since 1.2.0
	 *
	 * @param int $post_id Release post ID.
	 * @return bool True when a local preview URL is present.
	 */
	public function supports_release( int $post_id ): bool {
		return '' !== $this->get_preview_url( $post_id );
	}

	/**
	 * Resolves the release's `audio_preview_url` into a single-track manifest.
	 *
	 * @since 1.2.0
	 *
	 * @param int                  $post_id Release post ID.
	 * @param array<string, mixed> $context Optional resolution context.
	 * @return array<string, mixed>|null Raw manifest array, or null.
	 */
	public function resolve( int $post_id, array $context = array() ): ?array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Interface contract.
		$url = $this->get_preview_url( $post_id );
		if ( '' === $url ) {
			return null;
		}

		$title = function_exists( 'get_the_title' ) ? (string) get_the_title( $post_id ) : '';
		$mime  = self::detect_mime_type( $url );

		return array(
			'provider'       => $this->get_slug(),
			'providerLabel'  => $this->get_label(),
			'releaseId'      => $post_id,
			'title'          => $title,
			'isTimedPreview' => true,
			'ttl'            => 86400,
			'tracks'         => array(
				array(
					'index'          => 1,
					'title'          => '' !== $title ? $title : __( 'Audio Preview', 'mds-release-manager' ),
					'duration'       => 0.0,
					'streamUrl'      => $url,
					'mimeType'       => $mime,
					'isTimedPreview' => true,
				),
			),
		);
	}

	/**
	 * Reads and validates the `audio_preview_url` meta value for a release.
	 *
	 * @param int $post_id Release post ID.
	 * @return string Sanitized URL, or empty string.
	 */
	private function get_preview_url( int $post_id ): string {
		if ( $post_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return '';
		}

		$raw = get_post_meta( $post_id, 'audio_preview_url', true );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return '';
		}

		$clean = function_exists( 'esc_url_raw' ) ? esc_url_raw( trim( $raw ) ) : trim( $raw );
		return is_string( $clean ) ? $clean : '';
	}

	/**
	 * Infers a standard audio MIME type from a URL path extension.
	 *
	 * @param string $url Audio file URL.
	 * @return string MIME type string.
	 */
	private static function detect_mime_type( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$ext  = is_string( $path ) ? strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) : '';

		return match ( $ext ) {
			'm4a', 'mp4', 'aac' => 'audio/mp4',
			'ogg', 'oga'        => 'audio/ogg',
			'wav'               => 'audio/wav',
			'webm'              => 'audio/webm',
			'm3u8'              => 'application/x-mpegURL',
			default             => 'audio/mpeg',
		};
	}
}

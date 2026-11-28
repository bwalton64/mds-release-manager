<?php
/**
 * Free Core manual Tracklist & basic identifier storage (`_mds_rm_tracklist`).
 *
 * Transparently falls back to legacy `_mds_md_tracklist` when present so existing
 * sites migrate with zero data loss. Extensions (e.g. MDS Metadata Pro) can filter
 * `mds_rm_sanitize_track_row` to preserve extended fields like `iswc` and `splits`.
 *
 * @package   MDS\ReleaseManager
 * @copyright 2026 Musty Dungeon Studios (Walton International Business Group LLC)
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages canonical manual tracklist metadata for Release posts.
 *
 * @since 2.2.0
 */
final class TracklistStore {

	/**
	 * Primary Core meta key for structured tracklists.
	 *
	 * @var string
	 */
	public const META_TRACKLIST = '_mds_rm_tracklist';

	/**
	 * Legacy companion meta key checked on read for zero-downtime migration.
	 *
	 * @var string
	 */
	public const LEGACY_META_TRACKLIST = '_mds_md_tracklist';

	/**
	 * Normalizes an ISO 3901 ISRC to 12 uppercase alphanumeric characters.
	 *
	 * @param string $isrc Raw ISRC string.
	 * @return string
	 */
	public static function normalize_isrc( string $isrc ): string {
		return strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', trim( $isrc ) ) ?? '' );
	}

	/**
	 * Validates ISO 3901 ISRC structure (`CCXXXYYNNNNN`).
	 *
	 * @param string $isrc Raw or normalized ISRC.
	 * @return bool
	 */
	public static function is_valid_isrc( string $isrc ): bool {
		$clean = self::normalize_isrc( $isrc );
		return 1 === preg_match( '/^[A-Z]{2}[A-Z0-9]{3}\d{7}$/', $clean );
	}

	/**
	 * Reads the structured tracklist for a Release post.
	 *
	 * @param int $post_id Release post ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_tracklist( int $post_id ): array {
		if ( $post_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return array();
		}
		$raw = get_post_meta( $post_id, self::META_TRACKLIST, true );
		if ( '' === $raw || false === $raw || ( is_array( $raw ) && empty( $raw ) ) ) {
			$raw = get_post_meta( $post_id, self::LEGACY_META_TRACKLIST, true );
		}
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		return is_array( $raw ) ? self::sanitize_tracklist( $raw ) : array();
	}

	/**
	 * Saves the structured tracklist for a Release post.
	 *
	 * @param int   $post_id Release post ID.
	 * @param mixed $raw     Raw array or JSON string.
	 * @return array<int, array<string, mixed>>
	 */
	public static function save_tracklist( int $post_id, $raw ): array {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		$clean = self::sanitize_tracklist( is_array( $raw ) ? $raw : array() );
		if ( $post_id > 0 && function_exists( 'update_post_meta' ) ) {
			if ( empty( $clean ) ) {
				delete_post_meta( $post_id, self::META_TRACKLIST );
			} else {
				update_post_meta( $post_id, self::META_TRACKLIST, wp_slash( (string) wp_json_encode( $clean, JSON_INVALID_UTF8_SUBSTITUTE ) ) );
			}
			do_action( 'mds_rm_tracklist_saved', $post_id, $clean );
		}
		return $clean;
	}

	/**
	 * Sanitizes a tracklist array.
	 *
	 * @param array<int, mixed> $rows Raw track rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function sanitize_tracklist( array $rows ): array {
		$out = array();
		foreach ( $rows as $idx => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$title = sanitize_text_field( (string) ( $row['title'] ?? '' ) );
			if ( '' === $title ) {
				continue;
			}
			$clean = array(
				'disc'     => max( 1, (int) ( $row['disc'] ?? 1 ) ),
				'number'   => max( 1, (int) ( $row['number'] ?? ( $idx + 1 ) ) ),
				'title'    => $title,
				'duration' => max( 0, (int) ( $row['duration'] ?? 0 ) ),
				'isrc'     => self::normalize_isrc( (string) ( $row['isrc'] ?? '' ) ),
			);

			/**
			 * Filters a sanitized Core tracklist row so extensions can preserve additional
			 * fields (such as `iswc` and PRO `splits`).
			 *
			 * @since 2.2.0
			 * @param array<string, mixed> $clean Sanitized base row.
			 * @param array<string, mixed> $row   Original raw row.
			 */
			$filtered = apply_filters( 'mds_rm_sanitize_track_row', $clean, $row );
			$out[]    = is_array( $filtered ) ? $filtered : $clean;
		}
		return $out;
	}
}

<?php
/**
 * Free Core manual Song Lyrics storage (`_mds_rm_lyrics`) and LRC parser.
 *
 * Enforces copyright ownership guardrails (`owned`, `licensed`, `link_only`) and
 * transparently falls back to legacy `_mds_md_lyrics` for zero-downtime migration.
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
 * Stores and parses per-track song lyrics in Free Core.
 *
 * @since 2.2.0
 */
final class LyricsStore {

	/**
	 * Core post meta key for structured lyrics.
	 *
	 * @var string
	 */
	public const META_LYRICS = '_mds_rm_lyrics';

	/**
	 * Legacy companion meta key checked on read.
	 *
	 * @var string
	 */
	public const LEGACY_META_LYRICS = '_mds_md_lyrics';

	/**
	 * Valid ownership modes.
	 *
	 * @var string[]
	 */
	public const OWNERSHIP_MODES = array( 'owned', 'licensed', 'link_only' );

	/**
	 * Reads structured lyrics for a Release post.
	 *
	 * @param int $post_id Release post ID.
	 * @return array{schema_version:int,tracks:array<string,array<string,mixed>>}
	 */
	public static function get( int $post_id ): array {
		if ( $post_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return self::sanitize( array() );
		}
		$raw = get_post_meta( $post_id, self::META_LYRICS, true );
		if ( '' === $raw || false === $raw || ( is_array( $raw ) && empty( $raw ) ) ) {
			$raw = get_post_meta( $post_id, self::LEGACY_META_LYRICS, true );
		}
		return self::sanitize( $raw );
	}

	/**
	 * Saves structured lyrics for a Release post.
	 *
	 * @param int   $post_id Release post ID.
	 * @param mixed $raw     Raw array or JSON string.
	 * @return array{schema_version:int,tracks:array<string,array<string,mixed>>}
	 */
	public static function save( int $post_id, $raw ): array {
		$clean = self::sanitize( $raw );
		if ( $post_id > 0 && function_exists( 'update_post_meta' ) ) {
			if ( empty( $clean['tracks'] ) ) {
				delete_post_meta( $post_id, self::META_LYRICS );
			} else {
				update_post_meta( $post_id, self::META_LYRICS, wp_slash( (string) wp_json_encode( $clean, JSON_INVALID_UTF8_SUBSTITUTE ) ) );
			}
			do_action( 'mds_rm_lyrics_saved', $post_id, $clean );
		}
		return $clean;
	}

	/**
	 * Resolves canonical track key (ISRC when present, else `track_{number}`).
	 *
	 * @param array<string, mixed> $track Track row.
	 * @param int                  $index 0-based index.
	 * @return string
	 */
	public static function track_key( array $track, int $index = 0 ): string {
		$isrc = TracklistStore::normalize_isrc( (string) ( $track['isrc'] ?? '' ) );
		if ( '' !== $isrc ) {
			return $isrc;
		}
		$num = isset( $track['number'] ) && (int) $track['number'] > 0 ? (int) $track['number'] : ( $index + 1 );
		return 'track_' . $num;
	}

	/**
	 * Sanitizes a lyrics envelope.
	 *
	 * @param mixed $raw Raw lyrics envelope or JSON string.
	 * @return array{schema_version:int,tracks:array<string,array<string,mixed>>}
	 */
	public static function sanitize( $raw ): array {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		$raw    = is_array( $raw ) ? $raw : array();
		$source = isset( $raw['tracks'] ) && is_array( $raw['tracks'] ) ? $raw['tracks'] : array();
		$tracks = array();

		foreach ( $source as $key => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$clean_key = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $key ) ?? '';
			if ( '' === $clean_key ) {
				continue;
			}
			$ownership = (string) ( $entry['ownership'] ?? 'owned' );
			if ( ! in_array( $ownership, self::OWNERSHIP_MODES, true ) ) {
				$ownership = 'owned';
			}
			$lang_clean = strtolower( preg_replace( '/[^a-zA-Z\-]/', '', (string) ( $entry['language'] ?? 'en' ) ) ?? 'en' );

			$translations = array();
			if ( isset( $entry['translations'] ) && is_array( $entry['translations'] ) ) {
				foreach ( $entry['translations'] as $lang => $text ) {
					$lcode = strtolower( preg_replace( '/[^a-zA-Z\-]/', '', (string) $lang ) ?? '' );
					$ltext = self::sanitize_multiline( (string) $text );
					if ( '' !== $lcode && '' !== $ltext ) {
						$translations[ $lcode ] = $ltext;
					}
				}
			}

			$tracks[ $clean_key ] = array(
				'ownership'    => $ownership,
				'language'     => '' !== $lang_clean ? $lang_clean : 'en',
				'explicit'     => ! empty( $entry['explicit'] ),
				'instrumental' => ! empty( $entry['instrumental'] ),
				'plain'        => self::sanitize_multiline( (string) ( $entry['plain'] ?? '' ) ),
				'synced_lrc'   => self::sanitize_multiline( (string) ( $entry['synced_lrc'] ?? '' ) ),
				'external_url' => esc_url_raw( (string) ( $entry['external_url'] ?? '' ) ),
				'translations' => $translations,
			);
		}

		return array(
			'schema_version' => 1,
			'tracks'         => $tracks,
		);
	}

	/**
	 * Parses `[mm:ss.xx]` LRC timestamps into sorted entries.
	 *
	 * @param string $lrc Raw LRC text.
	 * @return array<int, array{time:float,text:string}>
	 */
	public static function parse_lrc( string $lrc ): array {
		$lines = preg_split( '/\r\n|\r|\n/', trim( $lrc ) );
		if ( ! is_array( $lines ) ) {
			return array();
		}
		$parsed = array();
		foreach ( $lines as $line ) {
			if ( preg_match_all( '/\[(\d{1,2}):(\d{2})(?:\.(\d{1,3}))?\]/', $line, $matches, PREG_SET_ORDER ) ) {
				$text = trim( preg_replace( '/\[(\d{1,2}):(\d{2})(?:\.(\d{1,3}))?\]/', '', $line ) ?? '' );
				foreach ( $matches as $m ) {
					$mins     = (int) $m[1];
					$secs     = (int) $m[2];
					$frac_str = $m[3] ?? '0';
					$frac     = 3 === strlen( $frac_str ) ? ( (int) $frac_str ) / 1000.0 : ( (int) $frac_str ) / 100.0;
					$parsed[] = array(
						'time' => round( ( $mins * 60 ) + $secs + $frac, 3 ),
						'text' => $text,
					);
				}
			}
		}
		usort( $parsed, static fn( array $a, array $b ): int => $a['time'] <=> $b['time'] );
		return $parsed;
	}

	/**
	 * Sanitizes multiline text while preserving line breaks.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private static function sanitize_multiline( string $text ): string {
		$normalized = str_replace( array( "\r\n", "\r" ), "\n", $text );
		if ( function_exists( 'sanitize_textarea_field' ) ) {
			return trim( sanitize_textarea_field( $normalized ) );
		}
		return trim( wp_strip_all_tags( $normalized ) );
	}
}

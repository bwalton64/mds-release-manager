<?php
/**
 * Free Core baseline Schema.org JSON-LD publisher (`MusicAlbum`, `MusicRecording`, `MusicGroup`)
 * with collision-free Yoast SEO & Rank Math interop and neutral extension filters.
 *
 * @package   MDS\ReleaseManager
 * @copyright 2026 Musty Dungeon Studios (Walton International Business Group LLC)
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Frontend;

use MDS\ReleaseManager\Core\TracklistStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emits baseline Schema.org JSON-LD for Release and Artist posts in Free Core.
 *
 * Extensions (e.g. MDS Metadata Pro) filter `mds_rm_schema_release` and `mds_rm_schema_artist`
 * to enrich nodes with `iswcCode`, `sameAs` entity graphs, and `MusicComposition.lyric`.
 *
 * @since 2.2.0
 */
final class SchemaPublisher {

	/**
	 * Registers Schema.org output hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'wp_head', array( self::class, 'output_standalone_schema' ), 25 );
		add_filter( 'rank_math/json_ld', array( self::class, 'inject_rank_math' ), 20 );
	}

	/**
	 * Outputs standalone JSON-LD when neither Yoast SEO nor Rank Math is handling the graph.
	 *
	 * @return void
	 */
	public static function output_standalone_schema(): void {
		if ( defined( 'RANK_MATH_VERSION' ) || defined( 'WPSEO_VERSION' ) ) {
			return;
		}
		$node = self::build_current_node();
		if ( null === $node ) {
			return;
		}
		$payload = array_merge( array( '@context' => 'https://schema.org' ), $node );
		$json    = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( is_string( $json ) && '' !== $json ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-LD encoded via wp_json_encode().
			echo "\n<script type=\"application/ld+json\" class=\"mds-rm-schema\">" . $json . "</script>\n";
		}
	}

	/**
	 * Injects Release/Artist schema into Rank Math's `@graph`.
	 *
	 * @param array<string, mixed> $data Existing Rank Math JSON-LD graph.
	 * @return array<string, mixed>
	 */
	public static function inject_rank_math( array $data ): array {
		$node = self::build_current_node();
		if ( null !== $node ) {
			$data['mds_rm_music_entity'] = $node;
		}
		return $data;
	}

	/**
	 * Builds the Schema.org node for the current singular Release or Artist post.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function build_current_node(): ?array {
		if ( ! function_exists( 'is_singular' ) ) {
			return null;
		}
		if ( is_singular( 'release' ) ) {
			return self::build_release_schema( (int) get_the_ID() );
		}
		if ( is_singular( 'artist' ) ) {
			return self::build_artist_schema( (int) get_the_ID() );
		}
		return null;
	}

	/**
	 * Builds baseline `MusicAlbum` JSON-LD for a Release post.
	 *
	 * @param int $post_id Release post ID.
	 * @return array<string, mixed>
	 */
	public static function build_release_schema( int $post_id ): array {
		$node = array(
			'@type' => 'MusicAlbum',
			'name'  => get_the_title( $post_id ),
			'url'   => (string) get_permalink( $post_id ),
		);

		$date = (string) get_post_meta( $post_id, 'release_date', true );
		if ( '' !== $date ) {
			$node['datePublished'] = $date;
		}
		$barcode = (string) get_post_meta( $post_id, 'barcode', true );
		if ( '' !== $barcode ) {
			$node['gtin'] = $barcode;
		}
		$image = get_the_post_thumbnail_url( $post_id, 'full' );
		if ( is_string( $image ) && '' !== $image ) {
			$node['image'] = $image;
		}

		$tracks = TracklistStore::get_tracklist( $post_id );
		if ( ! empty( $tracks ) ) {
			$track_nodes = array();
			foreach ( $tracks as $t ) {
				$rec = array(
					'@type' => 'MusicRecording',
					'name'  => (string) $t['title'],
				);
				if ( ! empty( $t['isrc'] ) ) {
					$rec['isrcCode'] = (string) $t['isrc'];
				}
				if ( ! empty( $t['duration'] ) ) {
					$sec             = (int) $t['duration'];
					$rec['duration'] = sprintf( 'PT%dM%dS', (int) floor( $sec / 60 ), $sec % 60 );
				}
				$track_nodes[] = $rec;
			}
			$node['track'] = $track_nodes;
		}

		$filtered = apply_filters( 'mds_rm_schema_release', $node, $post_id );
		return is_array( $filtered ) ? $filtered : $node;
	}

	/**
	 * Builds baseline `MusicGroup` JSON-LD for an Artist post.
	 *
	 * @param int $post_id Artist post ID.
	 * @return array<string, mixed>
	 */
	public static function build_artist_schema( int $post_id ): array {
		$node = array(
			'@type' => 'MusicGroup',
			'name'  => get_the_title( $post_id ),
			'url'   => (string) get_permalink( $post_id ),
		);

		$image = get_the_post_thumbnail_url( $post_id, 'full' );
		if ( is_string( $image ) && '' !== $image ) {
			$node['image'] = $image;
		}

		$filtered = apply_filters( 'mds_rm_schema_artist', $node, $post_id );
		return is_array( $filtered ) ? $filtered : $node;
	}
}

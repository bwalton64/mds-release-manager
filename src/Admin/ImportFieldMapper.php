<?php
/**
 * Smart heuristic and optional AI-assisted CSV field mapper for Release imports.
 *
 * Inspects column header synonyms (MDS Export, DistroKid, TuneCore, CD Baby,
 * Bandcamp, Symphonic) and sample cell contents (UPCs, dates, streaming URLs,
 * media URLs, Release Types) with optional Gemini/OpenAI or custom filter AI assist.
 *
 * @package MDS\ReleaseManager
 * @since   2.1.0
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps arbitrary import columns to canonical MDS Release Manager fields.
 *
 * Includes built-in header-synonym and sample-data heuristic detection plus
 * neutral extension filters (`mds_rm_import_ai_status`, `mds_rm_import_ai_map_columns`)
 * so companion extensions (such as MDS Metadata) can provide AI-assisted mapping.
 *
 * @since 2.1.0
 */
final class ImportFieldMapper {

	/**
	 * Returns all valid target fields (core fields + configured streaming providers).
	 *
	 * @return array<string, array{label: string, required: bool, group: string}>
	 */
	public static function target_fields(): array {
		$fields = array(
			'upc_ean'              => array(
				'label'    => __( 'UPC / EAN (Required)', 'mds-release-manager' ),
				'required' => true,
				'group'    => 'core',
			),
			'release_type'         => array(
				'label'    => __( 'Release Type — Single, EP, Album, Digital (Required)', 'mds-release-manager' ),
				'required' => true,
				'group'    => 'core',
			),
			'title'                => array(
				'label'    => __( 'Release Title', 'mds-release-manager' ),
				'required' => false,
				'group'    => 'core',
			),
			'artist'               => array(
				'label'    => __( 'Artist Name', 'mds-release-manager' ),
				'required' => false,
				'group'    => 'core',
			),
			'release_date'         => array(
				'label'    => __( 'Release Date', 'mds-release-manager' ),
				'required' => false,
				'group'    => 'core',
			),
			'record_label'         => array(
				'label'    => __( 'Record Label', 'mds-release-manager' ),
				'required' => false,
				'group'    => 'metadata',
			),
			'distributor'          => array(
				'label'    => __( 'Distributor', 'mds-release-manager' ),
				'required' => false,
				'group'    => 'metadata',
			),
			'buy_now_link'         => array(
				'label'    => __( 'Buy Now Link (URL)', 'mds-release-manager' ),
				'required' => false,
				'group'    => 'commerce',
			),
			'tags'                 => array(
				'label'    => __( 'Release Tags (comma-separated)', 'mds-release-manager' ),
				'required' => false,
				'group'    => 'metadata',
			),
			'featured_image_url'   => array(
				'label'    => __( 'Featured Image / Cover Art URL', 'mds-release-manager' ),
				'required' => false,
				'group'    => 'media',
			),
			'audio_preview_url'    => array(
				'label'    => __( 'Audio Preview URL (.mp3)', 'mds-release-manager' ),
				'required' => false,
				'group'    => 'media',
			),
			'about'                => array(
				'label'    => __( 'About / Description', 'mds-release-manager' ),
				'required' => false,
				'group'    => 'core',
			),
			'streaming_links_json' => array(
				'label'    => __( 'Streaming Links (JSON Object)', 'mds-release-manager' ),
				'required' => false,
				'group'    => 'streaming',
			),
		);

		foreach ( self::provider_names() as $provider_name ) {
			$fields[ 'provider:' . $provider_name ] = array(
				/* translators: %s: Streaming provider name (e.g. Spotify). */
				'label'    => sprintf( __( '%s Link (URL)', 'mds-release-manager' ), $provider_name ),
				'required' => false,
				'group'    => 'streaming',
			);
		}

		return $fields;
	}

	/**
	 * Returns configured streaming provider names plus built-in defaults.
	 *
	 * @return string[]
	 */
	public static function provider_names(): array {
		$names      = array( 'Spotify', 'Apple Music', 'Amazon Music', 'YouTube Music', 'SoundCloud', 'Bandcamp' );
		$configured = function_exists( 'get_option' ) ? get_option( 'mds_rm_providers', array() ) : array();
		if ( is_array( $configured ) ) {
			foreach ( $configured as $entry ) {
				$name = is_array( $entry ) ? trim( (string) ( $entry['name'] ?? '' ) ) : '';
				if ( '' !== $name && ! self::in_array_ci( $name, $names ) ) {
					$names[] = $name;
				}
			}
		}
		return $names;
	}

	/**
	 * Reports whether an extension has registered AI column-mapping assistance.
	 *
	 * Core never makes direct external LLM calls; companion plugins (such as MDS Metadata)
	 * register themselves via `mds_rm_import_ai_status` and `mds_rm_import_ai_map_columns`.
	 *
	 * @return array{available: bool, provider: string, label: string}
	 */
	public static function ai_status(): array {
		$default = array(
			'available' => false,
			'provider'  => 'none',
			'label'     => __( 'Not Configured', 'mds-release-manager' ),
		);

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the availability and provider label for AI-assisted import column mapping.
			 *
			 * @since 2.1.0
			 * @param array{available: bool, provider: string, label: string} $status Default status.
			 */
			$filtered = apply_filters( 'mds_rm_import_ai_status', $default );
			if ( is_array( $filtered ) && ! empty( $filtered['available'] ) ) {
				return array(
					'available' => true,
					'provider'  => sanitize_key( (string) ( $filtered['provider'] ?? 'custom' ) ),
					'label'     => (string) ( $filtered['label'] ?? __( 'AI Provider', 'mds-release-manager' ) ),
				);
			}
		}

		if (
			function_exists( 'has_filter' )
			&& ( has_filter( 'mds_rm_import_ai_map_columns' ) || has_filter( 'mds_rm_csv_ai_map_columns' ) )
		) {
			return array(
				'available' => true,
				'provider'  => 'custom',
				'label'     => __( 'Custom AI Provider', 'mds-release-manager' ),
			);
		}

		return $default;
	}

	/**
	 * Suggests column-to-field mappings using header synonyms and sample-data content sniffing.
	 *
	 * @param string[]             $headers     CSV column headers.
	 * @param array<int, string[]> $sample_rows Up to 10 sample rows aligned with `$headers`.
	 * @return array<int, array{header: string, target: string, confidence: string, reason: string}>
	 */
	public static function suggest_mapping( array $headers, array $sample_rows = array() ): array {
		$providers    = self::provider_names();
		$candidates   = array();
		$col_count    = count( $headers );
		$results      = array();
		$used_targets = array();

		for ( $i = 0; $i < $col_count; ++$i ) {
			$header  = trim( (string) $headers[ $i ] );
			$samples = array();
			foreach ( $sample_rows as $row ) {
				if ( isset( $row[ $i ] ) && '' !== trim( (string) $row[ $i ] ) ) {
					$samples[] = trim( (string) $row[ $i ] );
				}
			}
			$match = self::score_column( $header, $samples, $providers );
			if ( null !== $match ) {
				$candidates[] = array(
					'index'      => $i,
					'target'     => $match['target'],
					'score'      => $match['score'],
					'confidence' => $match['confidence'],
					'reason'     => $match['reason'],
				);
			}
			$results[ $i ] = array(
				'header'     => $header,
				'target'     => '',
				'confidence' => 'none',
				'reason'     => '',
			);
		}

		// Assign highest-scoring candidates first so each target is mapped at most once.
		usort(
			$candidates,
			static fn( array $a, array $b ): int => $b['score'] <=> $a['score']
		);
		foreach ( $candidates as $cand ) {
			if ( isset( $used_targets[ $cand['target'] ] ) ) {
				continue;
			}
			$used_targets[ $cand['target'] ] = true;
			$idx                             = $cand['index'];
			$results[ $idx ]['target']       = $cand['target'];
			$results[ $idx ]['confidence']   = $cand['confidence'];
			$results[ $idx ]['reason']       = $cand['reason'];
		}

		return array_values( $results );
	}

	/**
	 * Invokes optional AI assistance (custom filter, Gemini, or OpenAI) to map CSV headers.
	 *
	 * Sends only column headers and up to 3 truncated sample rows; validates every
	 * returned target key against `target_fields()`.
	 *
	 * @param string[]             $headers     CSV column headers.
	 * @param array<int, string[]> $sample_rows Sample rows (max 3 sent).
	 * @return array<string, mixed>|\WP_Error Mapping response or error.
	 */
	public static function ai_suggest_mapping( array $headers, array $sample_rows = array() ) {
		$status = self::ai_status();
		if ( ! $status['available'] ) {
			return new \WP_Error(
				'mds_rm_ai_unconfigured',
				__( 'No AI column-mapping provider is active.', 'mds-release-manager' ),
				array( 'status' => 400 )
			);
		}

		$valid_targets = self::target_fields();
		$trimmed_rows  = array();
		foreach ( array_slice( $sample_rows, 0, 3 ) as $row ) {
			$clean_row = array();
			foreach ( $headers as $idx => $_h ) {
				$cell        = isset( $row[ $idx ] ) ? trim( (string) $row[ $idx ] ) : '';
				$clean_row[] = strlen( $cell ) > 120 ? substr( $cell, 0, 120 ) . '…' : $cell;
			}
			$trimmed_rows[] = $clean_row;
		}

		$raw_map = null;
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters AI-generated import column mappings.
			 *
			 * Return an associative array mapping column index (int) or header name (string)
			 * to a valid target key from `ImportFieldMapper::target_fields()`, or `''` to skip.
			 *
			 * @since 2.1.0
			 * @param array<int|string, string>|null $raw_map       Default null.
			 * @param string[]                       $headers       Column headers.
			 * @param array<int, string[]>           $trimmed_rows  Up to 3 truncated sample rows.
			 * @param array<string, array>           $valid_targets Allowed target field keys and labels.
			 */
			$raw_map = apply_filters( 'mds_rm_import_ai_map_columns', null, $headers, $trimmed_rows, $valid_targets );

			if ( null === $raw_map && function_exists( 'has_filter' ) && has_filter( 'mds_rm_csv_ai_map_columns' ) ) {
				/**
				 * Legacy filter alias for AI-generated CSV column mappings.
				 *
				 * @since 2.0.2
				 */
				$raw_map = apply_filters( 'mds_rm_csv_ai_map_columns', null, $headers, $trimmed_rows, $valid_targets );
			}
		}

		if ( is_wp_error( $raw_map ) ) {
			return $raw_map;
		}
		if ( ! is_array( $raw_map ) ) {
			return new \WP_Error(
				'mds_rm_ai_invalid_response',
				__( 'The AI provider returned an unreadable mapping response. You can still use the built-in Smart Mapper.', 'mds-release-manager' ),
				array( 'status' => 502 )
			);
		}

		$heuristic = self::suggest_mapping( $headers, $sample_rows );
		$used      = array();
		$merged    = array();

		foreach ( $headers as $idx => $header ) {
			$candidate = $raw_map[ $header ] ?? ( $raw_map[ (string) $idx ] ?? ( $raw_map[ $idx ] ?? '' ) );
			$candidate = is_string( $candidate ) ? trim( $candidate ) : '';
			if ( '' !== $candidate && isset( $valid_targets[ $candidate ] ) && ! isset( $used[ $candidate ] ) ) {
				$used[ $candidate ] = true;
				$merged[]           = array(
					'header'     => $header,
					'target'     => $candidate,
					'confidence' => 'ai',
					'reason'     => sprintf(
						/* translators: %s: AI provider label (e.g. Google Gemini). */
						__( 'Mapped by %s', 'mds-release-manager' ),
						$status['label']
					),
				);
				continue;
			}
			$fallback = $heuristic[ $idx ] ?? array(
				'header'     => $header,
				'target'     => '',
				'confidence' => 'none',
				'reason'     => '',
			);
			if ( '' !== $fallback['target'] && isset( $used[ $fallback['target'] ] ) ) {
				$fallback['target']     = '';
				$fallback['confidence'] = 'none';
				$fallback['reason']     = '';
			} elseif ( '' !== $fallback['target'] ) {
				$used[ $fallback['target'] ] = true;
			}
			$merged[] = $fallback;
		}

		return array(
			'provider' => $status['label'],
			'mapping'  => $merged,
		);
	}

	/**
	 * Scores a single CSV column against header synonyms and sample cell patterns.
	 *
	 * @param string   $header    Column header text.
	 * @param string[] $samples   Non-empty sample values from this column.
	 * @param string[] $providers Configured streaming provider names.
	 * @return array{target: string, score: int, confidence: string, reason: string}|null
	 */
	private static function score_column( string $header, array $samples, array $providers ): ?array {
		$norm = strtolower( trim( (string) preg_replace( '/[^a-z0-9]+/i', ' ', $header ) ) );

		// 1. Exact & high-confidence header synonyms.
		$exact_map = array(
			'upc ean'            => 'upc_ean',
			'upc'                => 'upc_ean',
			'ean'                => 'upc_ean',
			'gtin'               => 'upc_ean',
			'barcode'            => 'upc_ean',
			'upc code'           => 'upc_ean',
			'ean code'           => 'upc_ean',
			'release title'      => 'title',
			'album title'        => 'title',
			'album'              => 'title',
			'title'              => 'title',
			'release name'       => 'title',
			'product title'      => 'title',
			'artist'             => 'artist',
			'artist name'        => 'artist',
			'primary artist'     => 'artist',
			'band'               => 'artist',
			'performer'          => 'artist',
			'release date'       => 'release_date',
			'street date'        => 'release_date',
			'original release'   => 'release_date',
			'publish date'       => 'release_date',
			'date'               => 'release_date',
			'release type'       => 'release_type',
			'release format'     => 'release_type',
			'format'             => 'release_type',
			'type'               => 'release_type',
			'record label'       => 'record_label',
			'label'              => 'record_label',
			'imprint'            => 'record_label',
			'distributor'        => 'distributor',
			'distribution'       => 'distributor',
			'aggregator'         => 'distributor',
			'buy now link'       => 'buy_now_link',
			'buy now'            => 'buy_now_link',
			'buy link'           => 'buy_now_link',
			'purchase url'       => 'buy_now_link',
			'store url'          => 'buy_now_link',
			'shop url'           => 'buy_now_link',
			'tags'               => 'tags',
			'release tags'       => 'tags',
			'genre'              => 'tags',
			'genres'             => 'tags',
			'keywords'           => 'tags',
			'featured image url' => 'featured_image_url',
			'cover art'          => 'featured_image_url',
			'cover url'          => 'featured_image_url',
			'artwork url'        => 'featured_image_url',
			'artwork'            => 'featured_image_url',
			'image url'          => 'featured_image_url',
			'audio preview url'  => 'audio_preview_url',
			'preview url'        => 'audio_preview_url',
			'audio preview'      => 'audio_preview_url',
			'audio url'          => 'audio_preview_url',
			'mp3 url'            => 'audio_preview_url',
			'about'              => 'about',
			'description'        => 'about',
			'notes'              => 'about',
			'liner notes'        => 'about',
			'bio'                => 'about',
			'streaming links'    => 'streaming_links_json',
		);

		if ( isset( $exact_map[ $norm ] ) ) {
			return array(
				'target'     => $exact_map[ $norm ],
				'score'      => 100,
				'confidence' => 'high',
				'reason'     => __( 'Exact header match', 'mds-release-manager' ),
			);
		}

		// 2. Streaming provider columns (e.g. "Spotify Link", "Apple Music URL", "Bandcamp").
		foreach ( $providers as $provider ) {
			$p_norm = strtolower( trim( (string) preg_replace( '/[^a-z0-9]+/i', ' ', $provider ) ) );
			if ( '' !== $p_norm && ( $norm === $p_norm || $norm === $p_norm . ' link' || $norm === $p_norm . ' url' ) ) {
				return array(
					'target'     => 'provider:' . $provider,
					'score'      => 98,
					'confidence' => 'high',
					'reason'     => __( 'Provider column match', 'mds-release-manager' ),
				);
			}
		}

		// 3. Partial header keyword hints.
		$keyword_rules = array(
			'upc_ean'            => array( 'upc', 'ean', 'gtin', 'barcode' ),
			'featured_image_url' => array( 'cover', 'artwork', 'thumbnail' ),
			'audio_preview_url'  => array( 'preview', 'mp3', 'audio' ),
			'buy_now_link'       => array( 'buy', 'purchase', 'store', 'shop' ),
			'release_date'       => array( 'date', 'released' ),
			'record_label'       => array( 'label', 'imprint' ),
			'distributor'        => array( 'distrib', 'aggregat' ),
			'release_type'       => array( 'format', 'type' ),
			'artist'             => array( 'artist', 'band', 'performer' ),
			'title'              => array( 'title', 'album', 'release' ),
		);
		foreach ( $keyword_rules as $target => $needles ) {
			foreach ( $needles as $needle ) {
				if ( '' !== $norm && str_contains( $norm, $needle ) ) {
					return array(
						'target'     => $target,
						'score'      => 75,
						'confidence' => 'medium',
						'reason'     => __( 'Header keyword match', 'mds-release-manager' ),
					);
				}
			}
		}

		// 4. Sample-data content sniffing (works even on unnamed/cryptic distributor headers).
		if ( empty( $samples ) ) {
			return null;
		}
		$count = count( $samples );

		$upc_hits    = 0;
		$type_hits   = 0;
		$date_hits   = 0;
		$img_hits    = 0;
		$audio_hits  = 0;
		$json_hits   = 0;
		$domain_hits = array();

		$domain_map = array(
			'open.spotify.com'  => 'Spotify',
			'music.apple.com'   => 'Apple Music',
			'itunes.apple.com'  => 'Apple Music',
			'bandcamp.com'      => 'Bandcamp',
			'soundcloud.com'    => 'SoundCloud',
			'music.youtube.com' => 'YouTube Music',
			'music.amazon.com'  => 'Amazon Music',
		);

		foreach ( $samples as $val ) {
			$digits = preg_replace( '/[^0-9]/', '', $val );
			if ( is_string( $digits ) && preg_match( '/\A[0-9]{11,14}\z/', $digits ) && preg_match( '/\A[0-9\-\s]+\z/', $val ) ) {
				++$upc_hits;
			}
			if ( in_array( strtolower( $val ), array( 'single', 'ep', 'album', 'digital' ), true ) ) {
				++$type_hits;
			}
			if ( preg_match( '/\A\d{4}-\d{2}-\d{2}/', $val ) || preg_match( '/\A\d{1,2}\/\d{1,2}\/\d{2,4}\z/', $val ) ) {
				++$date_hits;
			}
			if ( preg_match( '/\Ahttps?:\/\/.+\.(?:jpg|jpeg|png|webp|gif)(?:\?.*)?\z/i', $val ) ) {
				++$img_hits;
			}
			if ( preg_match( '/\Ahttps?:\/\/.+\.(?:mp3|m4a|wav|ogg|flac)(?:\?.*)?\z/i', $val ) ) {
				++$audio_hits;
			}
			if ( str_starts_with( $val, '{' ) && is_array( json_decode( $val, true ) ) ) {
				++$json_hits;
			}
			$lower = strtolower( $val );
			foreach ( $domain_map as $domain => $provider_name ) {
				if ( str_contains( $lower, $domain ) ) {
					$domain_hits[ $provider_name ] = ( $domain_hits[ $provider_name ] ?? 0 ) + 1;
				}
			}
		}

		$threshold = max( 1, (int) ceil( $count * 0.6 ) );
		if ( $upc_hits >= $threshold ) {
			return array(
				'target'     => 'upc_ean',
				'score'      => 85,
				'confidence' => 'medium',
				'reason'     => __( 'Detected 12–13 digit UPC/EAN values', 'mds-release-manager' ),
			);
		}
		if ( $json_hits >= $threshold ) {
			return array(
				'target'     => 'streaming_links_json',
				'score'      => 85,
				'confidence' => 'medium',
				'reason'     => __( 'Detected JSON streaming links', 'mds-release-manager' ),
			);
		}
		if ( $audio_hits >= $threshold ) {
			return array(
				'target'     => 'audio_preview_url',
				'score'      => 85,
				'confidence' => 'medium',
				'reason'     => __( 'Detected audio file URLs', 'mds-release-manager' ),
			);
		}
		if ( $img_hits >= $threshold ) {
			return array(
				'target'     => 'featured_image_url',
				'score'      => 80,
				'confidence' => 'medium',
				'reason'     => __( 'Detected image URLs', 'mds-release-manager' ),
			);
		}
		foreach ( $domain_hits as $provider_name => $hits ) {
			if ( $hits >= $threshold ) {
				$matched_provider = self::find_provider_ci( $provider_name, $providers );
				if ( '' !== $matched_provider ) {
					return array(
						'target'     => 'provider:' . $matched_provider,
						'score'      => 88,
						'confidence' => 'medium',
						'reason'     => sprintf(
							/* translators: %s: Streaming provider name. */
							__( 'Detected %s URLs in sample rows', 'mds-release-manager' ),
							$matched_provider
						),
					);
				}
			}
		}
		if ( $type_hits >= $threshold ) {
			return array(
				'target'     => 'release_type',
				'score'      => 82,
				'confidence' => 'medium',
				'reason'     => __( 'Detected Single/EP/Album/Digital values', 'mds-release-manager' ),
			);
		}
		if ( $date_hits >= $threshold ) {
			return array(
				'target'     => 'release_date',
				'score'      => 70,
				'confidence' => 'medium',
				'reason'     => __( 'Detected date values', 'mds-release-manager' ),
			);
		}

		return null;
	}

	/**
	 * Case-insensitive `in_array` helper.
	 *
	 * @param string   $needle   Value to search.
	 * @param string[] $haystack Array to search.
	 * @return bool
	 */
	private static function in_array_ci( string $needle, array $haystack ): bool {
		return '' !== self::find_provider_ci( $needle, $haystack );
	}

	/**
	 * Finds a matching provider name case-insensitively.
	 *
	 * @param string   $needle   Candidate provider name.
	 * @param string[] $haystack Configured provider names.
	 * @return string Matching canonical provider name or empty string.
	 */
	private static function find_provider_ci( string $needle, array $haystack ): string {
		$lower = strtolower( $needle );
		foreach ( $haystack as $item ) {
			if ( strtolower( $item ) === $lower ) {
				return $item;
			}
		}
		return '';
	}
}

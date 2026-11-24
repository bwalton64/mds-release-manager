<?php
/**
 * Modular, mobile-first Unified Release Player block and shortcode renderer.
 *
 * @package MDS\ReleaseManager
 * @since   1.2.0
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Blocks;

use MDS\ReleaseManager\Config;
use MDS\ReleaseManager\Core\Audio\AudioApi;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the mobile-first Release Player shell backed by the JIT Unified Audio API.
 *
 * @since 1.2.0
 */
final class ReleasePlayer {

	/**
	 * Default player attributes.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'releaseId'         => 0,
			'upc'               => '',
			'provider'          => '',
			'showArtwork'       => true,
			'showMeta'          => true,
			'showProgress'      => true,
			'showTime'          => true,
			'showTracklist'     => false,
			'showProviderBadge' => true,
			'samplerMode'       => true,
			'previewSeconds'    => 20,
			'crossfadeMs'       => 1500,
			'compact'           => false,
			'accentColor'       => '',
		);
	}

	/**
	 * Enqueues the Release Player stylesheet and vanilla JS engine.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		$css_rel = 'css/release-player.css';
		$js_rel  = 'js/release-player.js';

		if ( file_exists( MDS_RM_PATH . $css_rel ) ) {
			wp_enqueue_style(
				'mds-rm-release-player',
				MDS_RM_URL . $css_rel,
				array(),
				(string) filemtime( MDS_RM_PATH . $css_rel )
			);
		}

		if ( file_exists( MDS_RM_PATH . $js_rel ) ) {
			wp_enqueue_script(
				'mds-rm-release-player',
				MDS_RM_URL . $js_rel,
				array(),
				(string) filemtime( MDS_RM_PATH . $js_rel ),
				true
			);
			wp_add_inline_script(
				'mds-rm-release-player',
				'window.mdsRmPlayerI18n = ' . wp_json_encode(
					array(
						'play'        => __( 'Play preview', 'mds-release-manager' ),
						'pause'       => __( 'Pause preview', 'mds-release-manager' ),
						'previous'    => __( 'Previous track', 'mds-release-manager' ),
						'next'        => __( 'Next track', 'mds-release-manager' ),
						'loading'     => __( 'Loading preview…', 'mds-release-manager' ),
						'unavailable' => __( 'Audio preview unavailable.', 'mds-release-manager' ),
						'tracklist'   => __( 'Tracks', 'mds-release-manager' ),
						'previewVia'  => __( 'Preview via', 'mds-release-manager' ),
						'seekLabel'   => __( 'Seek audio position', 'mds-release-manager' ),
					)
				) . ';',
				'before'
			);
		}
	}

	/**
	 * Shortcode callback for `[release-player]`.
	 *
	 * @since 1.2.0
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 * @return string Rendered player HTML.
	 */
	public static function shortcode( array|string $atts = array() ): string {
		$atts = is_array( $atts ) ? $atts : array();

		$map = array(
			'release_id'          => 'releaseId',
			'upc'                 => 'upc',
			'provider'            => 'provider',
			'show_artwork'        => 'showArtwork',
			'show_meta'           => 'showMeta',
			'show_progress'       => 'showProgress',
			'show_time'           => 'showTime',
			'show_tracklist'      => 'showTracklist',
			'show_provider_badge' => 'showProviderBadge',
			'sampler_mode'        => 'samplerMode',
			'preview_seconds'     => 'previewSeconds',
			'crossfade_ms'        => 'crossfadeMs',
			'compact'             => 'compact',
			'accent_color'        => 'accentColor',
		);

		$normalized = array();
		foreach ( $atts as $key => $value ) {
			$lower  = strtolower( (string) $key );
			$target = $map[ $lower ] ?? (string) $key;
			if ( in_array( $target, array( 'showArtwork', 'showMeta', 'showProgress', 'showTime', 'showTracklist', 'showProviderBadge', 'samplerMode', 'compact' ), true ) ) {
				$normalized[ $target ] = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
			} else {
				$normalized[ $target ] = $value;
			}
		}

		return self::render( $normalized );
	}

	/**
	 * Renders the mobile-first Release Player HTML shell.
	 *
	 * Performs zero remote HTTP requests during page render; audio streams are
	 * resolved Just-in-Time via the REST endpoint when the listener taps Play.
	 *
	 * @since 1.2.0
	 *
	 * @param array<string, mixed> $attributes      Block or shortcode attributes.
	 * @param \WP_Block|null       $block           Optional Gutenberg block instance.
	 * @param int                  $context_post_id Optional context post ID.
	 * @return string Rendered HTML, or empty string when no audio provider supports the release.
	 */
	public static function render( array $attributes = array(), ?\WP_Block $block = null, int $context_post_id = 0 ): string {
		$attrs   = array_merge( self::defaults(), $attributes );
		$release = ReleaseMeta::resolve_release( $attrs, $block, $context_post_id );

		if ( ! $release instanceof \WP_Post ) {
			return '';
		}

		$release_id = (int) $release->ID;
		$provider   = is_string( $attrs['provider'] ) ? sanitize_key( $attrs['provider'] ) : '';

		if ( ! AudioApi::has_audio( $release_id, $provider ) ) {
			return '';
		}

		self::enqueue_assets();

		$show_artwork   = ! empty( $attrs['showArtwork'] );
		$show_meta      = ! empty( $attrs['showMeta'] );
		$show_progress  = ! empty( $attrs['showProgress'] );
		$show_time      = ! empty( $attrs['showTime'] );
		$show_tracklist = ! empty( $attrs['showTracklist'] );
		$show_badge     = ! empty( $attrs['showProviderBadge'] );
		$sampler_mode   = ! empty( $attrs['samplerMode'] );
		$compact        = ! empty( $attrs['compact'] );
		$preview_secs   = max( 10, min( 60, (int) $attrs['previewSeconds'] ) );
		$crossfade_ms   = max( 0, min( 4000, (int) $attrs['crossfadeMs'] ) );

		$accent = '';
		if ( is_string( $attrs['accentColor'] ) && '' !== trim( $attrs['accentColor'] ) ) {
			$accent = ReleaseLinksBlock::style_value( trim( $attrs['accentColor'] ), 'color' );
		}

		$title   = get_the_title( $release_id );
		$artist  = self::get_artist_label( $release_id );
		$artwork = $show_artwork ? get_the_post_thumbnail_url( $release_id, 'thumbnail' ) : '';
		$rest    = rest_url( Config::REST_NAMESPACE . '/releases/' . $release_id . '/audio' );

		$classes = array( 'mds-rm-player' );
		if ( $compact ) {
			$classes[] = 'mds-rm-player--compact';
		}

		$style_attr = '' !== $accent ? ' style="--mds-player-accent:' . esc_attr( $accent ) . ';"' : '';

		ob_start();
		?>
		<div
			class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
			data-release-id="<?php echo esc_attr( (string) $release_id ); ?>"
			data-rest-url="<?php echo esc_url( $rest ); ?>"
			data-provider="<?php echo esc_attr( $provider ); ?>"
			data-sampler="<?php echo $sampler_mode ? '1' : '0'; ?>"
			data-preview-seconds="<?php echo esc_attr( (string) $preview_secs ); ?>"
			data-crossfade-ms="<?php echo esc_attr( (string) $crossfade_ms ); ?>"
			data-show-tracklist="<?php echo $show_tracklist ? '1' : '0'; ?>"
			data-show-badge="<?php echo $show_badge ? '1' : '0'; ?>"
			role="region"
			aria-label="<?php echo esc_attr( sprintf( /* translators: %s: Release title. */ __( 'Audio preview player for %s', 'mds-release-manager' ), $title ) ); ?>"
			<?php echo $style_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above via esc_attr(). ?>
		>
			<div class="mds-rm-player__main">
				<?php if ( $show_artwork && is_string( $artwork ) && '' !== $artwork ) : ?>
					<div class="mds-rm-player__art">
						<img src="<?php echo esc_url( $artwork ); ?>" alt="<?php echo esc_attr( $title ); ?>" loading="lazy" decoding="async" />
					</div>
				<?php endif; ?>

				<div class="mds-rm-player__controls">
					<button
						type="button"
						class="mds-rm-player__btn mds-rm-player__btn--prev"
						data-action="prev"
						aria-label="<?php esc_attr_e( 'Previous track', 'mds-release-manager' ); ?>"
						hidden
					>
						<span aria-hidden="true">&#9198;</span>
					</button>

					<button
						type="button"
						class="mds-rm-player__btn mds-rm-player__btn--play"
						data-action="toggle"
						aria-label="<?php esc_attr_e( 'Play preview', 'mds-release-manager' ); ?>"
						aria-pressed="false"
					>
						<span class="mds-rm-player__icon mds-rm-player__icon--play" aria-hidden="true">&#9654;</span>
						<span class="mds-rm-player__icon mds-rm-player__icon--pause" aria-hidden="true" hidden>&#10074;&#10074;</span>
						<span class="mds-rm-player__spinner" aria-hidden="true" hidden></span>
					</button>

					<button
						type="button"
						class="mds-rm-player__btn mds-rm-player__btn--next"
						data-action="next"
						aria-label="<?php esc_attr_e( 'Next track', 'mds-release-manager' ); ?>"
						hidden
					>
						<span aria-hidden="true">&#9197;</span>
					</button>
				</div>

				<div class="mds-rm-player__body">
					<?php if ( $show_meta ) : ?>
						<div class="mds-rm-player__meta">
							<span class="mds-rm-player__title"><?php echo esc_html( $title ); ?></span>
							<?php if ( '' !== $artist ) : ?>
								<span class="mds-rm-player__artist"><?php echo esc_html( $artist ); ?></span>
							<?php endif; ?>
						</div>
					<?php endif; ?>

					<?php if ( $show_progress || $show_time ) : ?>
						<div class="mds-rm-player__timeline">
							<?php if ( $show_progress ) : ?>
								<input
									type="range"
									class="mds-rm-player__scrubber"
									min="0"
									max="100"
									step="0.5"
									value="0"
									aria-label="<?php esc_attr_e( 'Seek audio position', 'mds-release-manager' ); ?>"
									disabled
								/>
							<?php endif; ?>
							<?php if ( $show_time ) : ?>
								<span class="mds-rm-player__time" aria-hidden="true">0:00 / 0:00</span>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>

				<?php if ( $show_tracklist ) : ?>
					<button
						type="button"
						class="mds-rm-player__btn mds-rm-player__btn--tracks"
						data-action="tracklist"
						aria-expanded="false"
						aria-label="<?php esc_attr_e( 'Toggle tracklist', 'mds-release-manager' ); ?>"
						hidden
					>
						<span aria-hidden="true">&#9776;</span>
					</button>
				<?php endif; ?>
			</div>

			<div class="mds-rm-player__footer" hidden>
				<span class="mds-rm-player__badge" hidden></span>
				<span class="mds-rm-player__status" role="status" aria-live="polite"></span>
			</div>

			<?php if ( $show_tracklist ) : ?>
				<ol class="mds-rm-player__tracklist" hidden></ol>
			<?php endif; ?>

			<div class="mds-rm-player__embed" hidden></div>
		</div>
		<?php
		return trim( (string) ob_get_clean() );
	}

	/**
	 * Resolves the artist display name(s) for a release post.
	 *
	 * @param int $release_id Release post ID.
	 * @return string Artist label or empty string.
	 */
	private static function get_artist_label( int $release_id ): string {
		$artist_ids = get_post_meta( $release_id, 'artist_ids', true );
		if ( is_array( $artist_ids ) && ! empty( $artist_ids ) ) {
			$names = array();
			foreach ( $artist_ids as $aid ) {
				$name = trim( (string) get_the_title( (int) $aid ) );
				if ( '' !== $name ) {
					$names[] = $name;
				}
			}
			if ( ! empty( $names ) ) {
				return implode( ', ', $names );
			}
		}

		$artist_uuid = get_post_meta( $release_id, 'artist_id', true );
		if ( is_string( $artist_uuid ) && '' !== $artist_uuid ) {
			$posts = get_posts(
				array(
					'post_type'      => Config::CPT_ARTIST,
					'posts_per_page' => 1,
					'post_status'    => 'publish',
					'meta_key'       => 'artist_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Single artist lookup by UUID.
					'meta_value'     => $artist_uuid, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Single artist lookup by UUID.
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			if ( ! empty( $posts ) ) {
				return trim( (string) get_the_title( (int) $posts[0] ) );
			}
		}

		return '';
	}
}

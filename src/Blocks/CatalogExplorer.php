<?php
/**
 * Server-side renderer and shortcode handler for the Catalog / Discography Explorer.
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Blocks;

use MDS\ReleaseManager\Core\CatalogQuery;
use MDS\ReleaseManager\Frontend\ThemeScheme;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the interactive Catalog / Discography Explorer (`mds-rm/catalog-explorer` / `[catalog-explorer]`).
 *
 * @since 2.1.0
 */
final class CatalogExplorer {

	/**
	 * Enqueues the catalog explorer stylesheet and interactive frontend script.
	 *
	 * @since 2.1.0
	 */
	public static function enqueue_assets(): void {
		$css_rel = 'css/catalog-explorer.css';
		if ( file_exists( MDS_RM_PATH . $css_rel ) ) {
			wp_enqueue_style(
				'mds-rm-catalog-explorer',
				MDS_RM_URL . $css_rel,
				array(),
				(string) filemtime( MDS_RM_PATH . $css_rel )
			);
			wp_add_inline_style( 'mds-rm-catalog-explorer', ThemeScheme::css_variables() );
		}

		$js_rel = 'js/catalog-explorer.js';
		if ( file_exists( MDS_RM_PATH . $js_rel ) ) {
			wp_enqueue_script(
				'mds-rm-catalog-explorer',
				MDS_RM_URL . $js_rel,
				array(),
				(string) filemtime( MDS_RM_PATH . $js_rel ),
				true
			);
		}
	}

	/**
	 * Shortcode callback for `[catalog-explorer]`.
	 *
	 * @since 2.1.0
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 * @return string Rendered HTML.
	 */
	public static function shortcode( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'artist_id'    => '',
				'per_page'     => 12,
				'columns'      => 3,
				'pagination'   => 'numbered',
				'default_sort' => 'date_desc',
				'show_search'  => '1',
				'show_filters' => '1',
				'show_sort'    => '1',
				'show_audio'   => '1',
				'show_links'   => '1',
			),
			is_array( $atts ) ? $atts : array(),
			'catalog-explorer'
		);

		$to_bool = static fn( mixed $v ): bool => ! in_array( strtolower( trim( (string) $v ) ), array( '0', 'false', 'no', 'off', '' ), true );

		return self::render(
			array(
				'artistId'    => (string) $atts['artist_id'],
				'perPage'     => absint( $atts['per_page'] ),
				'columns'     => absint( $atts['columns'] ),
				'pagination'  => (string) $atts['pagination'],
				'defaultSort' => (string) $atts['default_sort'],
				'showSearch'  => $to_bool( $atts['show_search'] ),
				'showFilters' => $to_bool( $atts['show_filters'] ),
				'showSort'    => $to_bool( $atts['show_sort'] ),
				'showAudio'   => $to_bool( $atts['show_audio'] ),
				'showLinks'   => $to_bool( $atts['show_links'] ),
			)
		);
	}

	/**
	 * Renders the Catalog / Discography Explorer HTML (initial server-rendered grid + JSON hydration config).
	 *
	 * @since 2.1.0
	 * @param array<string, mixed> $attributes Block or shortcode attributes.
	 * @return string Rendered HTML.
	 */
	public static function render( array $attributes = array() ): string {
		self::enqueue_assets();

		$archive_defaults = ( function_exists( 'is_post_type_archive' ) && is_post_type_archive( 'release' ) )
			? \MDS\ReleaseManager\Frontend\TemplateDefaults::get( 'archive' )
			: array();

		$scoped_artist_input = trim( (string) ( $attributes['artistId'] ?? '' ) );
		$scoped_artist_uuid  = CatalogQuery::resolve_artist_uuid( $scoped_artist_input );
		$per_page            = min( 48, max( 1, (int) ( $attributes['perPage'] ?? ( $archive_defaults['per_page'] ?? 12 ) ) ) );
		$raw_columns         = (int) ( $attributes['columns'] ?? ( $archive_defaults['columns'] ?? 3 ) );
		$columns             = in_array( $raw_columns, array( 2, 3, 4, 5 ), true ) ? $raw_columns : 3;
		$raw_pagination      = (string) ( $attributes['pagination'] ?? ( $archive_defaults['pagination'] ?? 'numbered' ) );
		$pagination          = in_array( $raw_pagination, array( 'numbered', 'load_more', 'none' ), true ) ? $raw_pagination : 'numbered';
		$raw_default_sort    = (string) ( $attributes['defaultSort'] ?? ( $archive_defaults['default_sort'] ?? 'date_desc' ) );
		$default_sort        = in_array( $raw_default_sort, CatalogQuery::VALID_SORTS, true ) ? $raw_default_sort : 'date_desc';
		$show_search         = array_key_exists( 'showSearch', $attributes ) ? (bool) $attributes['showSearch'] : ( ! array_key_exists( 'show_search', $archive_defaults ) || (bool) $archive_defaults['show_search'] );
		$show_filters        = ! isset( $attributes['showFilters'] ) || (bool) $attributes['showFilters'];
		$show_sort           = array_key_exists( 'showSort', $attributes ) ? (bool) $attributes['showSort'] : ( ! array_key_exists( 'show_sort', $archive_defaults ) || (bool) $archive_defaults['show_sort'] );
		$show_audio          = array_key_exists( 'showAudio', $attributes ) ? (bool) $attributes['showAudio'] : ( ! array_key_exists( 'show_audio', $archive_defaults ) || (bool) $archive_defaults['show_audio'] );
		$show_links          = array_key_exists( 'showLinks', $attributes ) ? (bool) $attributes['showLinks'] : ( ! array_key_exists( 'show_links', $archive_defaults ) || (bool) $archive_defaults['show_links'] );

		// Read initial URL query params for bookmarkable SSR state (read-only frontend filter params).
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$initial_args = array(
			'search'   => isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '',
			'artist'   => '' !== $scoped_artist_uuid ? $scoped_artist_uuid : ( isset( $_GET['artist'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['artist'] ) ) : '' ),
			'type'     => isset( $_GET['type'] ) ? sanitize_title( wp_unslash( (string) $_GET['type'] ) ) : '',
			'year'     => isset( $_GET['year'] ) ? absint( wp_unslash( (string) $_GET['year'] ) ) : 0,
			'tag'      => isset( $_GET['tag'] ) ? sanitize_title( wp_unslash( (string) $_GET['tag'] ) ) : '',
			'label'    => isset( $_GET['label'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['label'] ) ) : '',
			'format'   => isset( $_GET['format'] ) ? sanitize_title( wp_unslash( (string) $_GET['format'] ) ) : '',
			'sort'     => isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( (string) $_GET['sort'] ) ) : $default_sort,
			'page'     => isset( $_GET['cpage'] ) ? max( 1, absint( wp_unslash( (string) $_GET['cpage'] ) ) ) : 1,
			'per_page' => $per_page,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result = CatalogQuery::query( $initial_args );
		$facets = $show_filters ? CatalogQuery::get_facets( $scoped_artist_uuid ) : array();
		$uid    = wp_unique_id( 'mds-catalog-' );

		$client_config = array(
			'restUrl'      => esc_url_raw( rest_url( 'mds/v1/catalog' ) ),
			'audioBaseUrl' => esc_url_raw( rest_url( 'mds/v1/releases' ) ),
			'scopedArtist' => $scoped_artist_uuid,
			'perPage'      => $per_page,
			'columns'      => $columns,
			'pagination'   => $pagination,
			'defaultSort'  => $default_sort,
			'showAudio'    => $show_audio,
			'showLinks'    => $show_links,
			'state'        => $result['args'],
			'total'        => $result['total'],
			'pages'        => $result['pages'],
			'labels'       => array(
				/* translators: 1: visible count, 2: total count. */
				'showing'     => __( 'Showing %1$d of %2$d releases', 'mds-release-manager' ),
				'noResults'   => __( 'No releases match your current filters.', 'mds-release-manager' ),
				'clearAll'    => __( 'Clear all filters', 'mds-release-manager' ),
				'loadMore'    => __( 'Load More Releases', 'mds-release-manager' ),
				'loading'     => __( 'Loading…', 'mds-release-manager' ),
				'prev'        => __( 'Previous', 'mds-release-manager' ),
				'next'        => __( 'Next', 'mds-release-manager' ),
				'play'        => __( 'Play preview', 'mds-release-manager' ),
				'pause'       => __( 'Pause preview', 'mds-release-manager' ),
				'buyNow'      => __( 'Buy Now', 'mds-release-manager' ),
				'viewRelease' => __( 'View Release', 'mds-release-manager' ),
				'audioError'  => __( 'Preview unavailable', 'mds-release-manager' ),
			),
		);

		ob_start();
		?>
		<div
			id="<?php echo esc_attr( $uid ); ?>"
			class="mds-catalog-explorer mds-catalog-explorer--cols-<?php echo esc_attr( (string) $columns ); ?>"
			data-mds-catalog-config="<?php echo esc_attr( (string) wp_json_encode( $client_config ) ); ?>"
		>
			<?php if ( $show_search || $show_filters || $show_sort ) : ?>
				<div class="mds-catalog-toolbar">
					<?php if ( $show_search ) : ?>
						<div class="mds-catalog-search">
							<label for="<?php echo esc_attr( $uid . '-search' ); ?>" class="screen-reader-text">
								<?php esc_html_e( 'Search releases by title, artist, or UPC/EAN', 'mds-release-manager' ); ?>
							</label>
							<input
								type="search"
								id="<?php echo esc_attr( $uid . '-search' ); ?>"
								class="mds-catalog-search__input"
								placeholder="<?php esc_attr_e( 'Search title, artist, or UPC/EAN…', 'mds-release-manager' ); ?>"
								value="<?php echo esc_attr( (string) $result['args']['search'] ); ?>"
								data-mds-filter="search"
							/>
						</div>
					<?php endif; ?>

					<div class="mds-catalog-toolbar__right">
						<?php if ( $show_filters ) : ?>
							<button
								type="button"
								class="mds-catalog-filter-toggle"
								aria-expanded="false"
								aria-controls="<?php echo esc_attr( $uid . '-filters' ); ?>"
							>
								<span class="dashicons dashicons-filter" aria-hidden="true"></span>
								<span><?php esc_html_e( 'Filters', 'mds-release-manager' ); ?></span>
							</button>
						<?php endif; ?>

						<?php if ( $show_sort ) : ?>
							<div class="mds-catalog-sort">
								<label for="<?php echo esc_attr( $uid . '-sort' ); ?>" class="screen-reader-text">
									<?php esc_html_e( 'Sort releases', 'mds-release-manager' ); ?>
								</label>
								<select id="<?php echo esc_attr( $uid . '-sort' ); ?>" class="mds-catalog-select" data-mds-filter="sort">
									<option value="date_desc" <?php selected( $result['args']['sort'], 'date_desc' ); ?>><?php esc_html_e( 'Newest First', 'mds-release-manager' ); ?></option>
									<option value="date_asc" <?php selected( $result['args']['sort'], 'date_asc' ); ?>><?php esc_html_e( 'Oldest First', 'mds-release-manager' ); ?></option>
									<option value="title_asc" <?php selected( $result['args']['sort'], 'title_asc' ); ?>><?php esc_html_e( 'Title (A–Z)', 'mds-release-manager' ); ?></option>
									<option value="artist_asc" <?php selected( $result['args']['sort'], 'artist_asc' ); ?>><?php esc_html_e( 'Artist (A–Z)', 'mds-release-manager' ); ?></option>
								</select>
							</div>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( $show_filters ) : ?>
				<div id="<?php echo esc_attr( $uid . '-filters' ); ?>" class="mds-catalog-filters">
					<?php if ( '' === $scoped_artist_uuid && ! empty( $facets['artists'] ) ) : ?>
						<select class="mds-catalog-select" aria-label="<?php esc_attr_e( 'Filter by Artist', 'mds-release-manager' ); ?>" data-mds-filter="artist">
							<option value=""><?php esc_html_e( 'All Artists', 'mds-release-manager' ); ?></option>
							<?php foreach ( $facets['artists'] as $opt ) : ?>
								<option value="<?php echo esc_attr( $opt['value'] ); ?>" <?php selected( $result['args']['artist'], $opt['value'] ); ?>><?php echo esc_html( $opt['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>

					<?php if ( ! empty( $facets['types'] ) ) : ?>
						<select class="mds-catalog-select" aria-label="<?php esc_attr_e( 'Filter by Release Type', 'mds-release-manager' ); ?>" data-mds-filter="type">
							<option value=""><?php esc_html_e( 'All Release Types', 'mds-release-manager' ); ?></option>
							<?php foreach ( $facets['types'] as $opt ) : ?>
								<option value="<?php echo esc_attr( $opt['value'] ); ?>" <?php selected( $result['args']['type'], $opt['value'] ); ?>><?php echo esc_html( $opt['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>

					<?php if ( ! empty( $facets['years'] ) ) : ?>
						<select class="mds-catalog-select" aria-label="<?php esc_attr_e( 'Filter by Year', 'mds-release-manager' ); ?>" data-mds-filter="year">
							<option value="0"><?php esc_html_e( 'All Years', 'mds-release-manager' ); ?></option>
							<?php foreach ( $facets['years'] as $yr ) : ?>
								<option value="<?php echo esc_attr( (string) $yr ); ?>" <?php selected( (int) $result['args']['year'], (int) $yr ); ?>><?php echo esc_html( (string) $yr ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>

					<?php if ( ! empty( $facets['tags'] ) ) : ?>
						<select class="mds-catalog-select" aria-label="<?php esc_attr_e( 'Filter by Tag', 'mds-release-manager' ); ?>" data-mds-filter="tag">
							<option value=""><?php esc_html_e( 'All Tags', 'mds-release-manager' ); ?></option>
							<?php foreach ( $facets['tags'] as $opt ) : ?>
								<option value="<?php echo esc_attr( $opt['value'] ); ?>" <?php selected( $result['args']['tag'], $opt['value'] ); ?>><?php echo esc_html( $opt['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>

					<?php if ( ! empty( $facets['labels'] ) ) : ?>
						<select class="mds-catalog-select" aria-label="<?php esc_attr_e( 'Filter by Record Label', 'mds-release-manager' ); ?>" data-mds-filter="label">
							<option value=""><?php esc_html_e( 'All Record Labels', 'mds-release-manager' ); ?></option>
							<?php foreach ( $facets['labels'] as $lbl ) : ?>
								<option value="<?php echo esc_attr( $lbl ); ?>" <?php selected( $result['args']['label'], $lbl ); ?>><?php echo esc_html( $lbl ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>

					<?php if ( ! empty( $facets['formats'] ) ) : ?>
						<select class="mds-catalog-select" aria-label="<?php esc_attr_e( 'Filter by Media Format', 'mds-release-manager' ); ?>" data-mds-filter="format">
							<option value=""><?php esc_html_e( 'All Media Formats', 'mds-release-manager' ); ?></option>
							<?php foreach ( $facets['formats'] as $opt ) : ?>
								<option value="<?php echo esc_attr( $opt['value'] ); ?>" <?php selected( $result['args']['format'], $opt['value'] ); ?>><?php echo esc_html( $opt['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="mds-catalog-status-bar">
				<span class="mds-catalog-count" aria-live="polite">
					<?php
					if ( $result['total'] > 0 ) {
						printf(
							/* translators: 1: visible count, 2: total count. */
							esc_html__( 'Showing %1$d of %2$d releases', 'mds-release-manager' ),
							count( $result['items'] ),
							(int) $result['total']
						);
					} else {
						esc_html_e( 'No releases match your current filters.', 'mds-release-manager' );
					}
					?>
				</span>
				<div class="mds-catalog-active-chips" data-mds-chips></div>
			</div>

			<div class="mds-catalog-grid" data-mds-grid>
				<?php foreach ( $result['items'] as $card ) : ?>
					<?php echo self::render_card_html( $card, $show_audio, $show_links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Card markup is fully escaped inside render_card_html(). ?>
				<?php endforeach; ?>
			</div>

			<div class="mds-catalog-pagination" data-mds-pagination>
				<?php if ( $result['pages'] > 1 ) : ?>
					<?php if ( 'load_more' === $pagination ) : ?>
						<?php if ( $result['page'] < $result['pages'] ) : ?>
							<button type="button" class="mds-catalog-load-more" data-mds-load-more>
								<?php esc_html_e( 'Load More Releases', 'mds-release-manager' ); ?>
							</button>
						<?php endif; ?>
					<?php else : ?>
						<nav class="mds-catalog-pages" aria-label="<?php esc_attr_e( 'Catalog Pagination', 'mds-release-manager' ); ?>">
							<?php for ( $p = 1; $p <= $result['pages']; ++$p ) : ?>
								<button
									type="button"
									class="mds-catalog-page-btn<?php echo $p === $result['page'] ? ' is-active' : ''; ?>"
									data-mds-page="<?php echo esc_attr( (string) $p ); ?>"
									<?php echo $p === $result['page'] ? 'aria-current="page"' : ''; ?>
								>
									<?php echo esc_html( (string) $p ); ?>
								</button>
							<?php endfor; ?>
						</nav>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Renders a single server-side release card matching the client-side JS card template.
	 *
	 * @since 2.1.0
	 * @param array<string, mixed> $card       Hydrated release card payload.
	 * @param bool                 $show_audio Whether to render the inline audio preview button.
	 * @param bool                 $show_links Whether to render quick streaming/Buy Now links.
	 * @return string Escaped card HTML.
	 */
	private static function render_card_html( array $card, bool $show_audio, bool $show_links ): string {
		$target_attr = ! empty( $card['isExternal'] ) ? ' target="_blank" rel="noopener noreferrer"' : '';
		ob_start();
		?>
		<article class="mds-catalog-card" data-release-id="<?php echo esc_attr( (string) $card['id'] ); ?>">
			<div class="mds-catalog-card__media">
				<a href="<?php echo esc_url( (string) $card['url'] ); ?>" class="mds-catalog-card__cover-link"<?php echo $target_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static attribute string. ?>>
					<?php if ( ! empty( $card['coverUrl'] ) ) : ?>
						<img src="<?php echo esc_url( (string) $card['coverUrl'] ); ?>" alt="<?php echo esc_attr( (string) $card['title'] ); ?>" loading="lazy" class="mds-catalog-card__img" />
					<?php else : ?>
						<span class="mds-catalog-card__placeholder" aria-hidden="true"><span class="mds-brand-icon"></span></span>
					<?php endif; ?>
				</a>
				<?php if ( ! empty( $card['releaseType'] ) ) : ?>
					<span class="mds-catalog-card__type"><?php echo esc_html( (string) $card['releaseType'] ); ?></span>
				<?php endif; ?>
				<?php if ( $show_audio && ! empty( $card['hasAudio'] ) ) : ?>
					<button
						type="button"
						class="mds-catalog-card__play"
						data-mds-play-id="<?php echo esc_attr( (string) $card['id'] ); ?>"
						<?php
						/* translators: %s: Release title. */
						$play_label = sprintf( __( 'Play preview for %s', 'mds-release-manager' ), (string) $card['title'] );
						?>
						aria-label="<?php echo esc_attr( $play_label ); ?>"
					>
						<span class="mds-catalog-card__play-icon" aria-hidden="true">&#9654;</span>
					</button>
				<?php endif; ?>
			</div>
			<div class="mds-catalog-card__body">
				<h3 class="mds-catalog-card__title">
					<a href="<?php echo esc_url( (string) $card['url'] ); ?>"<?php echo $target_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static attribute string. ?>>
						<?php echo esc_html( (string) $card['title'] ); ?>
					</a>
				</h3>
				<div class="mds-catalog-card__meta">
					<?php if ( ! empty( $card['artistName'] ) ) : ?>
						<?php if ( ! empty( $card['artistUrl'] ) ) : ?>
							<a href="<?php echo esc_url( (string) $card['artistUrl'] ); ?>" class="mds-catalog-card__artist"><?php echo esc_html( (string) $card['artistName'] ); ?></a>
						<?php else : ?>
							<span class="mds-catalog-card__artist"><?php echo esc_html( (string) $card['artistName'] ); ?></span>
						<?php endif; ?>
					<?php endif; ?>
					<?php if ( ! empty( $card['year'] ) ) : ?>
						<span class="mds-catalog-card__year"><?php echo esc_html( (string) $card['year'] ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( $show_links && ( ! empty( $card['buyNowUrl'] ) || ! empty( $card['streamingLinks'] ) ) ) : ?>
					<div class="mds-catalog-card__actions">
						<?php if ( ! empty( $card['buyNowUrl'] ) ) : ?>
							<a href="<?php echo esc_url( (string) $card['buyNowUrl'] ); ?>" class="mds-catalog-card__btn is-primary" target="_blank" rel="noopener noreferrer">
								<?php esc_html_e( 'Buy Now', 'mds-release-manager' ); ?>
							</a>
						<?php endif; ?>
						<?php
						$shown_providers = 0;
						foreach ( (array) $card['streamingLinks'] as $prov_name => $prov_url ) :
							if ( $shown_providers >= 2 ) {
								break;
							}
							++$shown_providers;
							?>
							<a href="<?php echo esc_url( (string) $prov_url ); ?>" class="mds-catalog-card__btn" target="_blank" rel="noopener noreferrer">
								<?php echo esc_html( (string) $prov_name ); ?>
							</a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		</article>
		<?php
		return (string) ob_get_clean();
	}
}

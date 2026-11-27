<?php
/**
 * Release Archive template for Classic/Hybrid themes when the Release Archive
 * Catalog Explorer layout is enabled.
 *
 * Honors the Designated Archive Page's title and optional intro content as well
 * as all Release Archive styling controls configured in Default Templates.
 *
 * @package   MDS\ReleaseManager
 * @copyright 2026 Musty Dungeon Studios (Walton International Business Group LLC)
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mds_archive         = \MDS\ReleaseManager\Frontend\TemplateDefaults::get( 'archive' );
$mds_archive_style   = \MDS\ReleaseManager\Frontend\TemplateDefaults::get_archive_style_attribute();
$mds_archive_page_id = \MDS\ReleaseManager\Frontend\TemplateDefaults::get_archive_page_id();
$mds_archive_title   = post_type_archive_title( '', false );
$mds_archive_intro   = '';

if ( $mds_archive_page_id > 0 && 'publish' === get_post_status( $mds_archive_page_id ) ) {
	$mds_page_obj = get_post( $mds_archive_page_id );
	if ( $mds_page_obj instanceof \WP_Post ) {
		if ( '' !== trim( (string) $mds_page_obj->post_title ) ) {
			$mds_archive_title = (string) $mds_page_obj->post_title;
		}
		if ( ! empty( $mds_archive['show_page_intro'] ) && '' !== trim( (string) $mds_page_obj->post_content ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WordPress content filter.
			$mds_archive_intro = (string) apply_filters( 'the_content', (string) $mds_page_obj->post_content );
		}
	}
}

$mds_archive_html = \MDS\ReleaseManager\Blocks\CatalogExplorer::render(
	array(
		'perPage'          => (int) ( $mds_archive['per_page'] ?? 12 ),
		'columns'          => (int) ( $mds_archive['columns'] ?? 3 ),
		'defaultSort'      => (string) ( $mds_archive['default_sort'] ?? 'date_desc' ),
		'pagination'       => (string) ( $mds_archive['pagination'] ?? 'numbered' ),
		'showSearch'       => ! empty( $mds_archive['show_search'] ),
		'showArtistFilter' => ! empty( $mds_archive['show_artist_filter'] ),
		'showTypeFilter'   => ! empty( $mds_archive['show_type_filter'] ),
		'showFormatFilter' => ! empty( $mds_archive['show_format_filter'] ),
		'showYearFilter'   => ! empty( $mds_archive['show_year_filter'] ),
		'showSort'         => ! empty( $mds_archive['show_sort'] ),
		'showAudio'        => ! empty( $mds_archive['show_audio'] ),
		'showLinks'        => ! empty( $mds_archive['show_links'] ),
	)
);

$mds_hover_class = ! empty( $mds_archive['hover_lift'] ) ? ' mds-archive--hover-lift' : '';

get_header();
?>
<main id="primary" class="site-main mds-catalog-archive-wrap<?php echo esc_attr( $mds_hover_class ); ?>" style="<?php echo esc_attr( $mds_archive_style ); ?>">
	<?php if ( ! empty( $mds_archive['show_page_title'] ) && '' !== (string) $mds_archive_title ) : ?>
		<h1 class="mds-catalog-archive-title"><?php echo esc_html( (string) $mds_archive_title ); ?></h1>
	<?php endif; ?>
	<?php if ( '' !== $mds_archive_intro ) : ?>
		<div class="mds-catalog-archive-intro"><?php echo wp_kses_post( $mds_archive_intro ); ?></div>
	<?php endif; ?>
	<?php echo $mds_archive_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside CatalogExplorer::render(). ?>
</main>
<?php
get_footer();

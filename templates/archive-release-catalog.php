<?php
/**
 * Optional archive template for `/release/` when "Use Catalog Explorer on /release/ Archive" is enabled.
 *
 * Supports both Block Themes (Twenty Twenty-Five, etc.) and Classic Themes cleanly.
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mds_archive_html = \MDS\ReleaseManager\Blocks\CatalogExplorer::render(
	array(
		'perPage'    => 12,
		'columns'    => 3,
		'pagination' => 'numbered',
	)
);

if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
	?>
	<!doctype html>
	<html <?php language_attributes(); ?>>
	<head>
		<meta charset="<?php bloginfo( 'charset' ); ?>" />
		<?php wp_head(); ?>
	</head>
	<body <?php body_class(); ?>>
	<?php wp_body_open(); ?>
	<div class="wp-site-blocks">
		<?php echo do_blocks( '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core block template part output. ?>
		<main class="wp-block-group mds-catalog-archive-wrap">
			<h1 class="mds-catalog-archive-title"><?php post_type_archive_title(); ?></h1>
			<?php echo $mds_archive_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside CatalogExplorer::render(). ?>
		</main>
		<?php echo do_blocks( '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core block template part output. ?>
	</div>
	<?php wp_footer(); ?>
	</body>
	</html>
	<?php
	return;
}

get_header();
?>
<main id="primary" class="site-main mds-catalog-archive-wrap">
	<h1 class="mds-catalog-archive-title"><?php post_type_archive_title(); ?></h1>
	<?php echo $mds_archive_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside CatalogExplorer::render(). ?>
</main>
<?php
get_footer();

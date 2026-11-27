<?php
/**
 * Registers native WordPress 6.7+ / 7.x Block Templates for Release Archive,
 * Single Release, and Single Artist via `register_block_template()`.
 *
 * Exposes plugin-supplied templates directly in Appearance -> Editor -> Templates
 * and the built-in WordPress Template Selector while allowing themes and site
 * owners to customize or override them cleanly.
 *
 * @package   MDS\ReleaseManager
 * @copyright 2026 Musty Dungeon Studios (Walton International Business Group LLC)
 * @license   GPL-3.0-or-later
 * @since     2.2.0
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers native WordPress block templates for MDS post types.
 *
 * @since 2.2.0
 */
final class BlockTemplates {

	/**
	 * Hooks template registration onto `init` after post types are registered.
	 *
	 * @since 2.2.0
	 */
	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'register' ), 20 );
	}

	/**
	 * Registers all 3 MDS native block templates when running WordPress 6.7+ / 7.x.
	 *
	 * @since 2.2.0
	 */
	public static function register(): void {
		if ( ! function_exists( 'register_block_template' ) ) {
			return;
		}

		register_block_template(
			'mds-release-manager//archive-release',
			array(
				'title'       => __( 'Release Archive', 'mds-release-manager' ),
				'description' => __( 'Displays the filterable, searchable Release Archive catalog grid.', 'mds-release-manager' ),
				'content'     => self::archive_release_markup(),
				'post_types'  => array( 'release' ),
			)
		);

		register_block_template(
			'mds-release-manager//single-release',
			array(
				'title'       => __( 'Single Release', 'mds-release-manager' ),
				'description' => __( 'Default layout for an individual Release page.', 'mds-release-manager' ),
				'content'     => self::single_release_markup(),
				'post_types'  => array( 'release' ),
			)
		);

		register_block_template(
			'mds-release-manager//single-artist',
			array(
				'title'       => __( 'Single Artist', 'mds-release-manager' ),
				'description' => __( 'Default layout for an individual Artist profile page.', 'mds-release-manager' ),
				'content'     => self::single_artist_markup(),
				'post_types'  => array( 'artist' ),
			)
		);
	}

	/**
	 * Block markup for `mds-release-manager//archive-release`.
	 *
	 * @return string Block template HTML.
	 */
	private static function archive_release_markup(): string {
		return implode(
			"\n",
			array(
				'<!-- wp:template-part {"slug":"header","tagName":"header"} /-->',
				'<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->',
				'<main class="wp-block-group">',
				'<!-- wp:query-title {"type":"archive","showPrefix":false} /-->',
				'<!-- wp:mds-rm/catalog-explorer /-->',
				'</main>',
				'<!-- /wp:group -->',
				'<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->',
			)
		);
	}

	/**
	 * Block markup for `mds-release-manager//single-release`.
	 *
	 * @return string Block template HTML.
	 */
	private static function single_release_markup(): string {
		return implode(
			"\n",
			array(
				'<!-- wp:template-part {"slug":"header","tagName":"header"} /-->',
				'<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->',
				'<main class="wp-block-group">',
				'<!-- wp:post-title {"level":1} /-->',
				'<!-- wp:post-content {"layout":{"type":"constrained"}} /-->',
				'</main>',
				'<!-- /wp:group -->',
				'<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->',
			)
		);
	}

	/**
	 * Block markup for `mds-release-manager//single-artist`.
	 *
	 * @return string Block template HTML.
	 */
	private static function single_artist_markup(): string {
		return implode(
			"\n",
			array(
				'<!-- wp:template-part {"slug":"header","tagName":"header"} /-->',
				'<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->',
				'<main class="wp-block-group">',
				'<!-- wp:post-title {"level":1} /-->',
				'<!-- wp:post-content {"layout":{"type":"constrained"}} /-->',
				'</main>',
				'<!-- /wp:group -->',
				'<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->',
			)
		);
	}
}

<?php
/**
 * Shortcodes for the plugin.
 *
 * @package MDS_Release_Manager
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin's shortcode hooks.
 *
 * @since 0.9.0
 */
final class MDS_RM_Shortcodes {

	/**
	 * Registers the release metadata and release links shortcodes.
	 *
	 * @since 0.9.0
	 */
	public static function register_hooks() {
		add_shortcode( 'release-meta', array( MDS_RM_Release_Meta::class, 'shortcode' ) );
		add_shortcode( 'release-links', array( MDS_RM_Release_Links::class, 'shortcode' ) );
		add_shortcode( 'artist-discography', array( MDS_RM_Discography::class, 'shortcode' ) );
	}

	/**
	 * Legacy entry point for rendering release metadata.
	 *
	 * @since 0.9.0
	 * @param string $upc              The release UPC.
	 * @param bool   $include_shortcode Retained for compatibility; links are no longer embedded.
	 * @return string The rendered release metadata.
	 */
	public static function mds_rm_render_meta_bar_html( $upc, $include_shortcode = true ) {
		return MDS_RM_Release_Meta::render( array( 'upc' => $upc ) );
	}
}

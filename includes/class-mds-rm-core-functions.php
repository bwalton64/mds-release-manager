<?php
declare(strict_types=1);

/**
 * Shared core functions for the MDS Release Manager plugin.
 *
 * @package MDS_Release_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides legacy entry points for the extracted helpers.
 *
 * @since 0.9.0
 */
final class MDS_RM_Core_Functions {

	/**
	 * Compatibility entry point for plugin debug logging.
	 *
	 * @since 0.9.0
	 * @param mixed $message The value to log.
	 */
	public static function mds_rm_log( $message ) {
		MDS_RM_Logger::log( $message );
	}

	/**
	 * Purges the LiteSpeed cache when its purge hook is available.
	 *
	 * @see MDS_RM_Sync::purge_cache()
	 * @since 0.9.0
	 */
	public static function mds_rm_purge_cache() {
		MDS_RM_Sync::purge_cache();
	}

	/**
	 * Retrieves editable asset contents stored in the database.
	 *
	 * @since 0.9.0
	 * @return array The saved asset contents.
	 */
	public static function mds_rm_get_db_templates() {
		return MDS_RM_Template_Loader::get_db_templates();
	}

	/**
	 * Load a PHP template and return its rendered output.
	 *
	 * @since 0.9.0
	 * @param string $template_name Template path relative to the plugin directory.
	 * @param array  $args          Variables passed to the template.
	 * @return string Rendered template.
	 */
	public static function mds_rm_render_template( $template_name, $args = array() ) {
		return MDS_RM_Template_Loader::render( $template_name, $args );
	}

	/**
	 * Sanitize shortcode output while preserving supported audio embeds.
	 *
	 * @since 0.9.0
	 * @param string $html Shortcode output.
	 * @return string Sanitized HTML.
	 */
	public static function mds_rm_kses_shortcode_html( $html ) {
		return MDS_RM_Template_Loader::kses_shortcode_html( $html );
	}

	/**
	 * Enqueues a stylesheet from its saved contents or plugin file.
	 *
	 * @since 0.9.0
	 * @param string $handle   The registered stylesheet handle.
	 * @param string $rel_path The stylesheet path relative to the plugin directory.
	 * @param array  $deps     The stylesheet dependencies.
	 */
	public static function mds_rm_enqueue_editable_style( $handle, $rel_path, $deps = array() ) {
		MDS_RM_Template_Loader::enqueue_editable_style( $handle, $rel_path, $deps );
	}

	/**
	 * Enqueues a script from its saved contents or plugin file.
	 *
	 * @since 0.9.0
	 * @param string $handle   The registered script handle.
	 * @param string $rel_path The script path relative to the plugin directory.
	 * @param array  $deps     The script dependencies.
	 * @param bool   $in_footer Whether to enqueue the script in the footer.
	 */
	public static function mds_rm_enqueue_editable_script( $handle, $rel_path, $deps = array(), $in_footer = true ) {
		MDS_RM_Template_Loader::enqueue_editable_script( $handle, $rel_path, $deps, $in_footer );
	}

	/**
	 * Ensures a taxonomy term exists on the current blog.
	 *
	 * Used during CSV import and broadcast synchronization.
	 *
	 * @see MDS_RM_Sync::ensure_term_exists()
	 * @since 0.9.0
	 * @param string $term_name The term name.
	 * @param string $taxonomy  The taxonomy name.
	 * @return int|null The term ID, or null if creation failed.
	 */
	public static function mds_rm_ensure_term_exists( $term_name, $taxonomy ) {
		return MDS_RM_Sync::ensure_term_exists( $term_name, $taxonomy );
	}

	/**
	 * Synchronizes a release or artist post to configured spoke sites.
	 *
	 * @see MDS_RM_Sync::broadcast_to_spokes()
	 * @since 0.9.0
	 * @param int    $post_id   The source post ID.
	 * @param string $post_type The source post type.
	 * @return true|WP_Error|null True on success, an error on failure, or null when synchronization is not applicable.
	 */
	public static function mds_rm_broadcast_to_spokes( $post_id, $post_type ) {
		return MDS_RM_Sync::broadcast_to_spokes( $post_id, $post_type );
	}

	/**
	 * Retrieves configured providers and fills in missing brand colors.
	 *
	 * @see MDS_RM_Providers::defaults()
	 * @since 0.9.0
	 * @return array The configured provider data.
	 */
	public static function mds_rm_get_default_providers() {
		return MDS_RM_Providers::defaults();
	}
}

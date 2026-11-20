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
 * Provides shared rendering and asset helpers and legacy entry points.
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

	// --- 2. Database-Backed Editable Assets ---.
	/**
	 * Retrieves editable asset contents stored in the database.
	 *
	 * @since 0.9.0
	 * @return array The saved asset contents.
	 */
	public static function mds_rm_get_db_templates() {
		$templates = is_multisite()
		? get_site_option( 'mds_rm_db_templates', array() )
		: get_option( 'mds_rm_db_templates', array() );
		return is_array( $templates ) ? $templates : array();
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
		if (
			! is_string( $template_name )
			|| 'templates' !== dirname( $template_name )
			|| 'php' !== strtolower( pathinfo( $template_name, PATHINFO_EXTENSION ) )
		) {
			wp_die( esc_html__( 'The requested Release Manager template is invalid.', 'mds-release-manager' ) );
			return '';
		}

		if ( ! is_array( $args ) ) {
			wp_die( esc_html__( 'The requested Release Manager template arguments are invalid.', 'mds-release-manager' ) );
			return '';
		}

		$theme_template = locate_template( 'mds-release-manager/' . $template_name );
		$template_path  = $theme_template ? $theme_template : MDS_RM_PATH . $template_name;

		if ( ! is_readable( $template_path ) ) {
			wp_die( esc_html__( 'The requested Release Manager template could not be found.', 'mds-release-manager' ) );
			return '';
		}

		ob_start();
		load_template( $template_path, false, $args );

		return (string) ob_get_clean();
	}

	/**
	 * Sanitize shortcode output while preserving supported audio embeds.
	 *
	 * @since 0.9.0
	 * @param string $html Shortcode output.
	 * @return string Sanitized HTML.
	 */
	public static function mds_rm_kses_shortcode_html( $html ) {
		$allowed_html           = wp_kses_allowed_html( 'post' );
		$allowed_html['iframe'] = array(
			'allow'             => true,
			'allowfullscreen'   => true,
			'allowtransparency' => true,
			'class'             => true,
			'frameborder'       => true,
			'height'            => true,
			'loading'           => true,
			'name'              => true,
			'referrerpolicy'    => true,
			'scrolling'         => true,
			'seamless'          => true,
			'src'               => true,
			'style'             => true,
			'title'             => true,
			'width'             => true,
		);

		return wp_kses( $html, $allowed_html );
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
		$db_templates = self::mds_rm_get_db_templates();
		if ( isset( $db_templates[ $rel_path ] ) && trim( $db_templates[ $rel_path ] ) !== '' ) {
			wp_register_style( $handle, false, $deps, MDS_RELEASE_MANAGER_VERSION );
			wp_enqueue_style( $handle );
			wp_add_inline_style( $handle, $db_templates[ $rel_path ] );
		} else {
			$asset_path = MDS_RM_PATH . $rel_path;
			wp_register_style( $handle, MDS_RM_URL . $rel_path, $deps, filemtime( $asset_path ) );
			wp_enqueue_style( $handle );
		}
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
		$db_templates = self::mds_rm_get_db_templates();
		if ( isset( $db_templates[ $rel_path ] ) && trim( $db_templates[ $rel_path ] ) !== '' ) {
			wp_register_script( $handle, false, $deps, MDS_RELEASE_MANAGER_VERSION, $in_footer );
			wp_enqueue_script( $handle );
			wp_add_inline_script( $handle, $db_templates[ $rel_path ] );
		} else {
			$asset_path = MDS_RM_PATH . $rel_path;
			wp_register_script( $handle, MDS_RM_URL . $rel_path, $deps, filemtime( $asset_path ), $in_footer );
			wp_enqueue_script( $handle );
		}
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

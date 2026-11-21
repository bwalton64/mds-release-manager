<?php
/**
 * Template and editable asset helpers for the MDS Release Manager plugin.
 *
 * @package MDS_Release_Manager
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads plugin templates and editable assets.
 */
final class MDS_RM_Template_Loader {

	/**
	 * Retrieves editable asset contents stored in the database.
	 *
	 * @since 0.9.0
	 * @return array The saved asset contents.
	 */
	public static function get_db_templates(): array {
		$templates = is_multisite()
		? get_site_option( 'mds_rm_db_templates', array() )
		: get_option( 'mds_rm_db_templates', array() );
		return is_array( $templates ) ? $templates : array();
	}

	/**
	 * Load a PHP template and return its rendered output.
	 *
	 * @since 0.9.0
	 * @param mixed $template_name Template path relative to the plugin directory.
	 * @param mixed $args          Variables passed to the template; must be an array.
	 * @return string Rendered template.
	 */
	public static function render( mixed $template_name, mixed $args = array() ): string {
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

		return self::render_file( $template_path, $args );
	}

	/**
	 * Render a bundled admin view without consulting the active theme.
	 *
	 * @param string $view View filename.
	 * @param array  $args Variables passed to the view.
	 * @return string Rendered view.
	 */
	public static function render_admin_view( string $view, array $args = array() ): string {
		if ( ! in_array( $view, array( 'artist-meta-box.php', 'release-identity-box.php', 'release-details-box.php', 'release-streaming-box.php', 'release-commerce-box.php', 'csv-page.php', 'settings-page.php', 'network-settings-page.php' ), true ) ) {
			wp_die( esc_html__( 'The requested Release Manager template is invalid.', 'mds-release-manager' ) );
			return '';
		}

		return self::render_file( MDS_RM_PATH . 'includes/admin/views/' . $view, $args );
	}

	/**
	 * Load a resolved template using WordPress's template context.
	 *
	 * @param string $template_path Absolute template path.
	 * @param array  $args          Variables passed to the template.
	 * @return string Rendered template.
	 */
	private static function render_file( string $template_path, array $args ): string {
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
	public static function kses_shortcode_html( $html ): string {
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
	 * @param string $fallback Optional bundled path when it differs from the stored asset key.
	 */
	public static function enqueue_editable_style( $handle, $rel_path, $deps = array(), $fallback = '' ): void {
		$db_templates = self::get_db_templates();
		if ( isset( $db_templates[ $rel_path ] ) && trim( $db_templates[ $rel_path ] ) !== '' ) {
			wp_register_style( $handle, false, $deps, MDS_RELEASE_MANAGER_VERSION );
			wp_enqueue_style( $handle );
			wp_add_inline_style( $handle, $db_templates[ $rel_path ] );
		} else {
			$asset = '' === $fallback ? $rel_path : $fallback;
			wp_register_style( $handle, MDS_RM_URL . $asset, $deps, filemtime( MDS_RM_PATH . $asset ) );
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
	public static function enqueue_editable_script( $handle, $rel_path, $deps = array(), $in_footer = true ): void {
		$db_templates = self::get_db_templates();
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
}

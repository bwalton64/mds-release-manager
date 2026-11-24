<?php
/**
 * Registers and serves the plugin's frontend and admin assets.
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and serves the plugin's frontend and admin assets.
 *
 * @since 0.9.0
 */
final class Assets {

	/**
	 * Public CDN Font Awesome Free version.
	 *
	 * @var string
	 */
	public const FA_CDN_VERSION = '6.7.2';

	/**
	 * Public CDN stylesheet URL (cdnjs / Cloudflare).
	 *
	 * @var string
	 */
	public const FA_CDN_URL = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css';

	/**
	 * Official SHA-512 Subresource Integrity hash for FA_CDN_URL.
	 *
	 * @var string
	 */
	public const FA_CDN_INTEGRITY = 'sha512-Evv84Mr4kqVGRNSgIGL/F/aIDqQb7xQ2vcrdIwxfjThSH8CSR7PBEakCr51Ck+w+/U6swU2Im1vVX0SVk9ABhg==';

	/**
	 * Registers asset and redirect hooks.
	 *
	 * @since 0.9.0
	 */
	public static function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_frontend_assets' ) );
		add_action( 'enqueue_block_assets', array( \MDS\ReleaseManager\Blocks\ReleaseMeta::class, 'enqueue_assets' ) );
		add_action( 'enqueue_block_assets', array( \MDS\ReleaseManager\Blocks\ReleaseLinks::class, 'enqueue_assets' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'admin_assets' ) );
		add_filter( 'style_loader_tag', array( self::class, 'filter_font_awesome_tag' ), 10, 4 );
	}

	/**
	 * Enqueues the frontend styles.
	 *
	 * @since 0.9.0
	 */
	public static function enqueue_frontend_assets(): void {
		self::enqueue_font_awesome();
		\MDS\ReleaseManager\Blocks\ReleaseMeta::enqueue_assets();

		\MDS\ReleaseManager\Core\TemplateLoader::enqueue_editable_style( 'mds-release-links-css', 'css/frontend.css' );
		if ( is_singular( 'release' ) ) {
			wp_add_inline_style(
				'mds-release-links-css',
				'.mds-title { color: ' . esc_attr( get_theme_mod( 'mds_rm_primary_color', '#2271b1' ) ) . '; }'
			);
		}
	}

	/**
	 * Detects whether an active Font Awesome plugin or registered stylesheet is present.
	 *
	 * @since 1.1.0
	 * @return string|null Human-readable provider label when detected, or null.
	 */
	public static function detect_font_awesome_plugin(): ?string {
		$detected = null;

		if (
			class_exists( 'FortAwesome\\FontAwesome' )
			|| defined( 'FORTAWESOME_FONT_AWESOME_VERSION' )
			|| function_exists( 'FortAwesome\\fa' )
		) {
			$detected = __( 'Font Awesome (Official)', 'mds-release-manager' );
		} elseif ( class_exists( 'Better_Font_Awesome_Library' ) || defined( 'BFA_VERSION' ) ) {
			$detected = __( 'Better Font Awesome', 'mds-release-manager' );
		} elseif ( class_exists( 'FontAwesome_Integration' ) || class_exists( 'FontAwesomeFour' ) ) {
			$detected = __( 'Font Awesome Plugin', 'mds-release-manager' );
		} elseif ( function_exists( 'wp_style_is' ) ) {
			$known_handles = array(
				'font-awesome-official',
				'font-awesome',
				'fontawesome',
				'bfa-font-awesome',
				'elementor-icons-fa-solid',
			);
			foreach ( $known_handles as $handle ) {
				if ( wp_style_is( $handle, 'enqueued' ) || wp_style_is( $handle, 'registered' ) ) {
					$detected = sprintf(
						/* translators: %s: Registered WordPress stylesheet handle. */
						__( 'Active stylesheet (%s)', 'mds-release-manager' ),
						$handle
					);
					break;
				}
			}
		}

		/**
		 * Filters the detected Font Awesome provider label.
		 *
		 * @since 1.1.0
		 * @param string|null $detected Human-readable provider label, or null if none detected.
		 */
		$filtered = apply_filters( 'mds_rm_detected_font_awesome_plugin', $detected );
		return is_string( $filtered ) && '' !== trim( $filtered ) ? $filtered : null;
	}

	/**
	 * Normalizes a stored or submitted Font Awesome source setting.
	 *
	 * @since 1.1.0
	 * @param mixed $value Raw option value.
	 * @return string One of 'auto', 'cdn', 'plugin', 'theme', 'none'.
	 */
	public static function sanitize_font_awesome_source( mixed $value ): string {
		$raw = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
		if ( 'internal' === $raw ) {
			return 'cdn';
		}
		if ( in_array( $raw, array( 'auto', 'cdn', 'plugin', 'theme', 'none' ), true ) ) {
			return $raw;
		}
		return 'auto';
	}

	/**
	 * Resolves the effective Font Awesome source ('cdn', 'plugin', 'theme', or 'none').
	 *
	 * @since 1.1.0
	 * @param string|null $configured Optional explicit setting; defaults to stored option.
	 * @return string Effective source.
	 */
	public static function resolve_font_awesome_source( ?string $configured = null ): string {
		$mode = self::sanitize_font_awesome_source(
			null !== $configured ? $configured : get_option( 'mds_rm_fa_support', 'auto' )
		);

		if ( 'auto' === $mode ) {
			return null !== self::detect_font_awesome_plugin() ? 'plugin' : 'cdn';
		}

		return $mode;
	}

	/**
	 * Enqueues Font Awesome from the public CDN when the resolved source is 'cdn'.
	 */
	public static function enqueue_font_awesome(): void {
		if ( 'cdn' !== self::resolve_font_awesome_source() ) {
			return;
		}

		/**
		 * Filters the public CDN URL used when MDS Release Manager loads Font Awesome.
		 *
		 * @since 1.1.0
		 * @param string $url Default cdnjs URL for Font Awesome Free 6.7.2.
		 */
		$cdn_url = (string) apply_filters( 'mds_rm_font_awesome_cdn_url', self::FA_CDN_URL );
		if ( '' === trim( $cdn_url ) ) {
			return;
		}

		wp_register_style(
			'mds-font-awesome',
			$cdn_url,
			array(),
			null // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Version is pinned in the CDN path for cache hits and SRI integrity.
		);
		wp_enqueue_style( 'mds-font-awesome' );
	}

	/**
	 * Adds Subresource Integrity (SRI), crossorigin, and referrerpolicy attributes
	 * to the default cdnjs Font Awesome stylesheet tag.
	 *
	 * @since 1.1.0
	 * @param string $html   The `<link>` tag HTML.
	 * @param string $handle The registered style handle.
	 * @param string $href   The stylesheet URL.
	 * @param string $media  The stylesheet media attribute.
	 * @return string Filtered `<link>` tag HTML.
	 */
	public static function filter_font_awesome_tag( string $html, string $handle, string $href, string $media ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by WordPress style_loader_tag filter signature.
		if ( 'mds-font-awesome' !== $handle || str_contains( $html, 'integrity=' ) ) {
			return $html;
		}
		if ( self::FA_CDN_URL !== $href ) {
			return $html;
		}

		$sri_attrs = sprintf(
			' integrity="%s" crossorigin="anonymous" referrerpolicy="no-referrer"',
			esc_attr( self::FA_CDN_INTEGRITY )
		);

		return str_replace( ' />', $sri_attrs . ' />', $html );
	}

	/**
	 * Redirects release pages to their configured purchase URL.
	 *
	 * @since 0.9.0
	 */
	public static function handle_global_redirect(): void {
		if ( is_admin() || ! is_singular( 'release' ) ) {
			return;
		}
		if ( get_option( 'mds_rm_global_redirect', '0' ) !== '1' ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}

		$primary_buy_now = \MDS\ReleaseManager\Core\ReleaseModel::get_primary_buy_now_url( $post_id );
		if ( '' === $primary_buy_now ) {
			return;
		}

		$target_url  = esc_url_raw( $primary_buy_now );
		$current_url = home_url( add_query_arg( array(), $GLOBALS['wp']->request ?? '' ) );
		if ( empty( $target_url ) || untrailingslashit( $target_url ) === untrailingslashit( $current_url ) ) {
			return;
		}

		$target_host = wp_parse_url( $target_url, PHP_URL_HOST );
		if ( $target_host ) {
			add_filter(
				'allowed_redirect_hosts',
				static function ( $hosts ) use ( $target_host ) {
					$hosts[] = $target_host;
					return $hosts;
				}
			);
		}

		wp_safe_redirect( $target_url, 302 );
		exit;
	}

	/**
	 * Enqueues assets required by plugin admin screens.
	 *
	 * @since 0.9.0
	 */
	public static function admin_assets(): void {
		$screen             = get_current_screen();
		$is_content_screen  = $screen
			&& in_array( $screen->base, array( 'post', 'edit' ), true )
			&& in_array( $screen->post_type, array( 'release', 'artist' ), true );
		$is_settings_screen = $screen && 'release_page_mds-release-settings' === $screen->id;
		$is_csv_screen      = $screen && 'release_page_mds-release-csv' === $screen->id;
		$is_network_screen  = $screen && 'settings_page_mds-release-network-settings-network' === $screen->id;

		if ( $is_content_screen || $is_settings_screen || $is_csv_screen || $is_network_screen ) {
			$stylesheet = 'css/admin-styles.css';
			wp_enqueue_style( 'mds-admin-view-styles', MDS_RM_URL . $stylesheet, array(), filemtime( MDS_RM_PATH . $stylesheet ) );
			if ( $is_content_screen || $is_settings_screen ) {
				\MDS\ReleaseManager\Core\TemplateLoader::enqueue_editable_style( 'mds-admin-styles', $stylesheet, array( 'mds-admin-view-styles' ), 'css/admin-defaults.css' );
			}
		}

		if ( $is_content_screen ) {
			wp_enqueue_media();

			\MDS\ReleaseManager\Core\TemplateLoader::enqueue_editable_script(
				'mds-meta-boxes-js',
				'js/meta-boxes.js',
				array( 'jquery' ),
				true
			);

			if ( 'post' === $screen->base && 'release' === $screen->post_type ) {
				$is_block_editor = $screen->is_block_editor();
				$dependencies    = array( 'jquery', 'tags-box' );
				$dependencies    = array_merge( $dependencies, $is_block_editor ? array( 'wp-data', 'wp-dom-ready', 'wp-edit-post', 'wp-hooks' ) : array( 'post' ) );
				\MDS\ReleaseManager\Core\TemplateLoader::enqueue_editable_script(
					'mds-release-taxonomies',
					'js/release-taxonomies.js',
					$dependencies,
					true
				);
				wp_add_inline_script(
					'mds-release-taxonomies',
					'var mdsReleaseBlockEditor = ' . wp_json_encode( $is_block_editor ) . ';',
					'before'
				);
				\MDS\ReleaseManager\Core\TemplateLoader::enqueue_editable_script(
					'mds-release-artist-picker',
					'js/release-artist-picker.js',
					array(),
					true
				);
				wp_add_inline_script(
					'mds-release-artist-picker',
					'var mdsReleaseArtistPickerData = ' . wp_json_encode(
						array(
							'rest_url'       => esc_url_raw( rest_url( 'mds/v1/artists' ) ),
							'nonce'          => wp_create_nonce( 'wp_rest' ),
							'loading'        => __( 'Searching Artists...', 'mds-release-manager' ),
							'search_failed'  => __( 'Could not load Artists. Please try again.', 'mds-release-manager' ),
							'no_results'     => __( 'No matching Artists found.', 'mds-release-manager' ),
							'previous'       => __( 'Previous', 'mds-release-manager' ),
							'next'           => __( 'Next', 'mds-release-manager' ),
							'selected'       => __( 'Selected: %s', 'mds-release-manager' ),
							'cleared'        => __( 'No Artist selected.', 'mds-release-manager' ),
							'type_to_search' => __( 'Type an Artist name to search.', 'mds-release-manager' ),
							'minimum_length' => __( 'Enter at least two characters to search.', 'mds-release-manager' ),
						)
					) . ';',
					'before'
				);
				wp_set_script_translations( 'mds-release-artist-picker', 'mds-release-manager', MDS_RM_PATH . 'languages' );
			}

			if ( 'post' === $screen->base && 'artist' === $screen->post_type ) {
				$routing_script = 'js/artist-routing.js';
				wp_enqueue_script(
					'mds-artist-routing',
					MDS_RM_URL . $routing_script,
					array(),
					filemtime( MDS_RM_PATH . $routing_script ),
					true
				);
				wp_add_inline_script(
					'mds-artist-routing',
					'var mdsArtistRoutingData = ' . wp_json_encode(
						array(
							'rest_base' => esc_url_raw( rest_url( 'mds/v1/artists/' ) ),
							'nonce'     => wp_create_nonce( 'wp_rest' ),
							'saving'    => __( 'Saving & syncing…', 'mds-release-manager' ),
							'save_btn'  => __( 'Save Routing Changes', 'mds-release-manager' ),
							'saved'     => __( 'Routing saved.', 'mds-release-manager' ),
							'error'     => __( 'Could not save routing changes.', 'mds-release-manager' ),
							'no_select' => __( 'Select at least one release to apply a bulk action.', 'mds-release-manager' ),
						)
					) . ';',
					'before'
				);
			}
		}

		if ( $is_settings_screen || $is_network_screen ) {
			$tabs_script = 'js/settings-tabs.js';
			wp_enqueue_script( 'mds-settings-tabs', MDS_RM_URL . $tabs_script, array( 'jquery' ), filemtime( MDS_RM_PATH . $tabs_script ), true );
		}

		if ( $is_settings_screen ) {
			wp_enqueue_style( 'wp-color-picker' );
			wp_enqueue_style( 'wp-components' );
			wp_enqueue_style( 'wp-block-editor' );
			$defaults_script = 'js/settings-defaults.js';
			wp_enqueue_script(
				'mds-settings-defaults',
				MDS_RM_URL . $defaults_script,
				array( 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n', 'mds-rm-release-meta-controls', 'mds-rm-release-links-controls', 'mds-rm-discography-table-controls' ),
				filemtime( MDS_RM_PATH . $defaults_script ),
				true
			);
			$context = new \WP_Block_Editor_Context( array( 'name' => 'mds-rm/settings' ) );
			wp_add_inline_script(
				'mds-settings-defaults',
				'var mdsRmDefaultsSettings = ' . wp_json_encode(
					array(
						'editorSettings'      => get_block_editor_settings( array(), $context ),
						'defaults'            => get_option( 'mds_rm_block_defaults', array() ),
						'metaItems'           => \MDS\ReleaseManager\Blocks\ReleaseMeta::default_items(),
						'metaFields'          => \MDS\ReleaseManager\Blocks\ReleaseMeta::fields(),
						'providers'           => \MDS\ReleaseManager\Blocks\ReleaseLinks::providers(),
						'audioPlayers'        => \MDS\ReleaseManager\Core\AudioPlayers::get_client_players(),
						'activeAudioPlayer'   => \MDS\ReleaseManager\Core\AudioPlayers::get_active_player(),
						'audioPlayerSettings' => \MDS\ReleaseManager\Core\AudioPlayers::get_all_settings(),
					)
				) . ';',
				'before'
			);
			wp_set_script_translations( 'mds-settings-defaults', 'mds-release-manager', MDS_RM_PATH . 'languages' );

			$template_defaults_script = 'js/template-defaults.js';
			wp_enqueue_script(
				'mds-template-defaults',
				MDS_RM_URL . $template_defaults_script,
				array( 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n', 'wp-a11y', 'mds-rm-release-meta-controls' ),
				filemtime( MDS_RM_PATH . $template_defaults_script ),
				true
			);
			wp_add_inline_script(
				'mds-template-defaults',
				'var mdsRmTemplateDefaultsData = ' . wp_json_encode(
					array(
						'editorSettings' => get_block_editor_settings( array(), $context ),
						'values'         => TemplateDefaults::get_all(),
						'defaults'       => TemplateDefaults::defaults(),
					)
				) . ';',
				'before'
			);
			wp_set_script_translations( 'mds-template-defaults', 'mds-release-manager', MDS_RM_PATH . 'languages' );
			\MDS\ReleaseManager\Core\TemplateLoader::enqueue_editable_script(
				'mds-settings-js',
				'js/settings.js',
				array( 'jquery', 'wp-color-picker', 'jquery-ui-sortable' ),
				true
			);
			wp_add_inline_script(
				'mds-settings-js',
				'var mds_rm_data = ' . wp_json_encode(
					array(
						'rest_url'           => esc_url_raw( rest_url( 'mds/v1/settings' ) ),
						'nonce'              => wp_create_nonce( 'wp_rest' ),
						'provider_name'      => __( 'Provider Name', 'mds-release-manager' ),
						'hex_color'          => __( 'Hex Color', 'mds-release-manager' ),
						'remove'             => __( 'Remove', 'mds-release-manager' ),
						'saving'             => __( 'Saving...', 'mds-release-manager' ),
						'save_settings'      => __( 'Save Settings', 'mds-release-manager' ),
						'save_error'         => __( 'Error saving settings.', 'mds-release-manager' ),
						'unsaved_after_save' => __( 'Settings were saved, but newer changes have not been saved.', 'mds-release-manager' ),
					)
				) . ';',
				'before'
			);
		}
	}
}

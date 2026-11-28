<?php
/**
 * Release Manager top-level admin menu and Dashboard landing page.
 *
 * Registers "Release Manager" as the top-level menu with Dashboard as the
 * default landing page at the top of the submenu, attaches Releases, Artists,
 * and Release taxonomies underneath, preserves active menu highlighting via
 * `parent_file` / `submenu_file`, and redirects legacy submenu URLs cleanly.
 *
 * @package   MDS\ReleaseManager
 * @copyright 2026 Musty Dungeon Studios (Walton International Business Group LLC)
 * @license   GPL-3.0-or-later
 * @since     2.2.0
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Admin;

use MDS\ReleaseManager\Core\BrandIcon;
use MDS\ReleaseManager\Core\Sync;
use MDS\ReleaseManager\Frontend\TemplateDefaults;
use MDS\ReleaseManager\Frontend\ThemeScheme;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Top-level Release Manager admin menu & Dashboard screen controller.
 *
 * @since 2.2.0
 */
final class Dashboard {

	/**
	 * Top-level admin menu & Dashboard page slug.
	 *
	 * @var string
	 */
	public const MENU_SLUG = 'mds-release-manager';

	/**
	 * Registers admin menu, highlighting, and legacy redirect hooks.
	 *
	 * @since 2.2.0
	 */
	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 5 );
		add_action( 'admin_init', array( self::class, 'redirect_legacy_submenu_urls' ) );
		add_filter( 'parent_file', array( self::class, 'filter_parent_file' ) );
		add_filter( 'submenu_file', array( self::class, 'filter_submenu_file' ), 10, 2 );
	}

	/**
	 * Registers the top-level "Release Manager" menu with Dashboard as the default first submenu.
	 *
	 * @since 2.2.0
	 */
	public static function register_menu(): void {
		add_menu_page(
			__( 'Release Manager', 'mds-release-manager' ),
			__( 'Release Manager', 'mds-release-manager' ),
			'edit_posts',
			self::MENU_SLUG,
			array( self::class, 'render_page' ),
			BrandIcon::data_uri(),
			25
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Release Manager Dashboard', 'mds-release-manager' ),
			__( 'Dashboard', 'mds-release-manager' ),
			'edit_posts',
			self::MENU_SLUG,
			array( self::class, 'render_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'All Releases', 'mds-release-manager' ),
			__( 'All Releases', 'mds-release-manager' ),
			'edit_posts',
			'edit.php?post_type=release'
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'All Artists', 'mds-release-manager' ),
			__( 'All Artists', 'mds-release-manager' ),
			'edit_posts',
			'edit.php?post_type=artist'
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Release Types', 'mds-release-manager' ),
			__( 'Release Types', 'mds-release-manager' ),
			'manage_categories',
			'edit-tags.php?taxonomy=release_type&post_type=release'
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Media Formats', 'mds-release-manager' ),
			__( 'Media Formats', 'mds-release-manager' ),
			'manage_categories',
			'edit-tags.php?taxonomy=release_media_format&post_type=release'
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Release Tags', 'mds-release-manager' ),
			__( 'Release Tags', 'mds-release-manager' ),
			'manage_categories',
			'edit-tags.php?taxonomy=release_tag&post_type=release'
		);
	}

	/**
	 * Redirects legacy `edit.php?post_type=release&page=mds-release-*` URLs to `admin.php?page=...`.
	 *
	 * @since 2.2.0
	 */
	public static function redirect_legacy_submenu_urls(): void {
		global $pagenow;
		if ( 'edit.php' !== $pagenow ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation redirect.
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( (string) wp_unslash( $_GET['post_type'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation redirect.
		$page = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : '';

		if ( 'release' === $post_type && in_array( $page, array( 'mds-release-settings', 'mds-release-csv' ), true ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . $page ) );
			exit;
		}
	}

	/**
	 * Keeps the "Release Manager" top-level menu expanded on Release, Artist, and taxonomy screens.
	 *
	 * @since 2.2.0
	 *
	 * @param string $parent_file Current parent menu file.
	 * @return string Filtered parent menu file.
	 */
	public static function filter_parent_file( string $parent_file ): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return $parent_file;
		}

		if ( in_array( $screen->post_type, array( 'release', 'artist' ), true ) ) {
			return self::MENU_SLUG;
		}
		if ( in_array( $screen->taxonomy, array( 'release_type', 'release_media_format', 'release_tag' ), true ) ) {
			return self::MENU_SLUG;
		}

		return $parent_file;
	}

	/**
	 * Highlights the exact active submenu item under "Release Manager".
	 *
	 * @since 2.2.0
	 *
	 * @param string|null $submenu_file Current submenu file.
	 * @param string      $parent_file  Current parent menu file.
	 * @return string|null Filtered submenu file.
	 */
	public static function filter_submenu_file( ?string $submenu_file, string $parent_file ): ?string {
		if ( self::MENU_SLUG !== $parent_file ) {
			return $submenu_file;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return $submenu_file;
		}

		if ( in_array( $screen->taxonomy, array( 'release_type', 'release_media_format', 'release_tag' ), true ) ) {
			return 'edit-tags.php?taxonomy=' . $screen->taxonomy . '&post_type=release';
		}
		if ( 'artist' === $screen->post_type ) {
			return 'edit.php?post_type=artist';
		}
		if ( 'release' === $screen->post_type ) {
			return 'edit.php?post_type=release';
		}

		return $submenu_file;
	}

	/**
	 * Collects fast KPI metrics, catalog completeness counts, recent releases, and site status.
	 *
	 * @since 2.2.0
	 *
	 * @return array<string, mixed> Serializable dashboard payload.
	 */
	public static function get_data(): array {
		global $wpdb;

		$release_counts = wp_count_posts( 'release' );
		$artist_counts  = wp_count_posts( 'artist' );

		$published_releases = (int) ( $release_counts->publish ?? 0 );
		$draft_releases     = (int) ( $release_counts->draft ?? 0 );
		$published_artists  = (int) ( $artist_counts->publish ?? 0 );

		$missing_cover  = 0;
		$missing_upc    = 0;
		$missing_artist = 0;

		if ( $published_releases > 0 && isset( $wpdb ) ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lightweight admin dashboard aggregate counts.
			$missing_cover = (int) $wpdb->get_var(
				"SELECT COUNT(p.ID) FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_thumbnail_id' AND pm.meta_value != '' AND pm.meta_value != '0'
				WHERE p.post_type = 'release' AND p.post_status = 'publish' AND pm.meta_id IS NULL"
			);

			$missing_upc = (int) $wpdb->get_var(
				"SELECT COUNT(p.ID) FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'upc_ean' AND pm.meta_value != ''
				WHERE p.post_type = 'release' AND p.post_status = 'publish' AND pm.meta_id IS NULL"
			);

			$missing_artist = (int) $wpdb->get_var(
				"SELECT COUNT(p.ID) FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'artist_id' AND pm.meta_value != ''
				WHERE p.post_type = 'release' AND p.post_status = 'publish' AND pm.meta_id IS NULL"
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		$total_checks = $published_releases * 3;
		$passed       = max( 0, $total_checks - ( $missing_cover + $missing_upc + $missing_artist ) );
		$health_score = $total_checks > 0 ? (int) round( ( $passed / $total_checks ) * 100 ) : 100;

		// Recent releases (latest 5).
		$recent_posts = get_posts(
			array(
				'post_type'      => 'release',
				'post_status'    => array( 'publish', 'draft', 'future' ),
				'posts_per_page' => 5,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		$recent = array();
		foreach ( $recent_posts as $post ) {
			$thumb_url = get_the_post_thumbnail_url( $post->ID, 'thumbnail' );
			$recent[]  = array(
				'id'       => (int) $post->ID,
				'title'    => (string) $post->post_title,
				'status'   => (string) $post->post_status,
				'year'     => (string) get_the_date( 'Y', $post->ID ),
				'upc'      => (string) get_post_meta( $post->ID, 'upc_ean', true ),
				'thumbUrl' => is_string( $thumb_url ) ? $thumb_url : '',
				'editUrl'  => (string) get_edit_post_link( $post->ID, 'raw' ),
				'viewUrl'  => (string) get_permalink( $post->ID ),
			);
		}

		$archive         = TemplateDefaults::get( 'archive' );
		$archive_page_id = TemplateDefaults::get_archive_page_id();
		$archive_title   = ( $archive_page_id > 0 && 'publish' === get_post_status( $archive_page_id ) )
			? (string) get_the_title( $archive_page_id )
			: __( 'Default (/release/)', 'mds-release-manager' );

		$is_multisite = Sync::is_network_mode();
		$spoke_count  = 0;
		if ( $is_multisite && function_exists( 'get_sites' ) ) {
			$sites       = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			$spoke_count = max( 0, count( $sites ) - 1 );
		}

		return array(
			'version'        => MDS_RM_VERSION,
			'canManage'      => current_user_can( 'manage_options' ),
			'counts'         => array(
				'releasesPublished' => $published_releases,
				'releasesDraft'     => $draft_releases,
				'artistsPublished'  => $published_artists,
			),
			'completeness'   => array(
				'score'         => $health_score,
				'missingCover'  => $missing_cover,
				'missingUpc'    => $missing_upc,
				'missingArtist' => $missing_artist,
			),
			'recentReleases' => $recent,
			'archive'        => array(
				'enabled'     => ! empty( $archive['enabled'] ),
				'pageId'      => $archive_page_id,
				'pageTitle'   => $archive_title,
				'url'         => (string) get_post_type_archive_link( 'release' ),
				'editPageUrl' => $archive_page_id > 0 ? (string) get_edit_post_link( $archive_page_id, 'raw' ) : '',
			),
			'themeScheme'    => ThemeScheme::detect(),
			'multisite'      => array(
				'isMultisite'        => $is_multisite,
				'isHub'              => Sync::is_hub(),
				'hubId'              => Sync::hub_id(),
				'spokeCount'         => $spoke_count,
				'networkSettingsUrl' => $is_multisite ? network_admin_url( 'settings.php?page=mds-release-network-settings' ) : '',
			),
			'urls'           => array(
				'addRelease'   => admin_url( 'post-new.php?post_type=release' ),
				'addArtist'    => admin_url( 'post-new.php?post_type=artist' ),
				'allReleases'  => admin_url( 'edit.php?post_type=release' ),
				'allArtists'   => admin_url( 'edit.php?post_type=artist' ),
				'settings'     => admin_url( 'admin.php?page=mds-release-settings' ),
				'templatesTab' => admin_url( 'admin.php?page=mds-release-settings#tab-templates' ),
				'blocksTab'    => admin_url( 'admin.php?page=mds-release-settings#tab-defaults' ),
				'importExport' => admin_url( 'admin.php?page=mds-release-csv' ),
				'siteEditor'   => ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) ? admin_url( 'site-editor.php?path=%2Fwp_template' ) : '',
			),
		);
	}

	/**
	 * Renders the Dashboard root container.
	 *
	 * @since 2.2.0
	 */
	public static function render_page(): void {
		echo '<div class="wrap mds-dashboard-wrap">';
		echo '<div id="mds-rm-dashboard-root"></div>';

		/**
		 * Neutral extension hook rendered below the Release Manager Dashboard root.
		 *
		 * Companion plugins may mount additional status cards here without Core
		 * referencing or coupling to any paid extension.
		 *
		 * @since 2.2.0
		 */
		do_action( 'mds_rm_dashboard_after_root' );

		echo '</div>';
	}
}

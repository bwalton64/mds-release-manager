<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die;
}

( static function (): void {
	$wipe_all = get_site_option( 'mds_rm_wipe_on_uninstall', '0' ) === '1';

	// We clear out localized plugin settings to clean up the options table.
	$options = array(
		'mds_rm_fa_support',
		'mds_rm_widget_bg_color',
		'mds_rm_shortcode_gap',
		'mds_rm_shortcode_align',
		'mds_rm_links_icon_bg',
		'mds_rm_links_color',
		'mds_rm_links_bg',
		'mds_rm_links_radius',
		'mds_rm_links_gap',
		'mds_rm_links_padding',
		'mds_rm_links_align',
		'mds_rm_buy_now_icon',
		'mds_rm_buy_now_color',
		'mds_rm_buy_now_bg',
		'mds_rm_meta_color',
		'mds_rm_meta_bg',
		'mds_rm_meta_radius',
		'mds_rm_meta_gap',
		'mds_rm_meta_padding',
		'mds_rm_meta_align',
		'mds_rm_woo_meta_bg',
		'mds_rm_woo_meta_color',
		'mds_rm_woo_meta_gap',
		'mds_rm_woo_meta_align',
		'mds_rm_audio_players',
		'mds_rm_audio_player_settings',
		'mds_rm_audio_provider_priority',
		'mds_rm_audio_disabled_providers',
		'mds_rm_bandcamp_template',
		'mds_rm_providers',
		'mds_rm_global_redirect',
		'mds_rm_block_defaults',
		'mds_rm_template_defaults',
		'mds_rm_active_scheme_fingerprint',
		'mds_rm_cache_settings',
		'mds_rm_network_sync_job',
		'mds_rm_network_sync_lock',
		'mds_rm_secret_vault',
		'mds_rm_secret_audit',
		'mds_rm_release_type_migrated',
		'mds_rm_seeded_media_formats_hash',
		'mds_rm_media_formats_seeded',
		'mds_rm_normalized_upc_migration_complete',
		'mds_rm_artist_image_cleanup_complete',
	);

	$delete_local_options = static function () use ( $options ): void {
		foreach ( $options as $opt ) {
			delete_option( $opt );
		}

		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( 'mds_rm_release_audit' );
			delete_transient( 'mds_rm_scheme_reset_notice' );
		}

		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'query' ) ) {
			$like_val     = $wpdb->esc_like( '_transient_mds_rm_audio_' ) . '%';
			$like_timeout = $wpdb->esc_like( '_transient_timeout_mds_rm_audio_' ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Targeted uninstall cleanup of MDS Release Manager audio transients.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
					$like_val,
					$like_timeout
				)
			);
		}
	};

	$delete_local_options();

	if ( ! $wipe_all ) {
		return;
	}

	$delete_plugin_posts = static function (): void {
		$post_ids = get_posts(
			array(
				'post_type'              => array( 'release', 'artist' ),
				'numberposts'            => -1,
				'post_status'            => array_keys( get_post_stati() ), // Unlike 'any', this includes Trash.
				'fields'                 => 'ids',
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		foreach ( $post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
	};

	delete_site_option( 'mds_rm_master_blog_id' );
	delete_site_option( 'mds_rm_db_templates' );
	delete_site_option( 'mds_rm_wipe_on_uninstall' );

	if ( ! is_multisite() ) {
		$delete_plugin_posts();
		return;
	}

	delete_site_option( 'mds_rm_spoke_sites' );
	delete_site_option( 'spoke_sites' );
	$delete_plugin_posts();

	$current_blog_id = get_current_blog_id();
	$site_ids        = get_sites(
		array(
			'network_id' => get_current_network_id(),
			'fields'     => 'ids',
			'number'     => 0,
		)
	);
	foreach ( $site_ids as $site_id ) {
		if ( $current_blog_id === (int) $site_id ) {
			continue;
		}

		switch_to_blog( (int) $site_id );
		try {
			$delete_local_options();
			$delete_plugin_posts();
		} finally {
			restore_current_blog();
		}
	}
} )();

<?php
declare(strict_types=1);

/**
 * Streaming provider defaults for the MDS Release Manager plugin.
 *
 * @package MDS_Release_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supplies provider defaults and restores missing brand colors.
 */
final class MDS_RM_Providers {

	/**
	 * Retrieves configured providers and fills in missing brand colors.
	 *
	 * @return array The configured provider data.
	 */
	public static function defaults() {
		$providers = get_option( 'mds_rm_providers', false );

		if ( empty( $providers ) || ! is_array( $providers ) ) {
			$providers = array(
				array(
					'name'  => 'Spotify',
					'icon'  => 'fab fa-spotify',
					'color' => '#1DB954',
				),
				array(
					'name'  => 'Apple Music',
					'icon'  => 'fab fa-apple',
					'color' => '#FA243C',
				),
				array(
					'name'  => 'Amazon Music',
					'icon'  => 'fab fa-amazon',
					'color' => '#00A8E1',
				),
				array(
					'name'  => 'YouTube Music',
					'icon'  => 'fab fa-youtube',
					'color' => '#FF0000',
				),
				array(
					'name'  => 'Soundcloud',
					'icon'  => 'fab fa-soundcloud',
					'color' => '#FF5500',
				),
			);
			// Save defaults so they populate the settings page.
			update_option( 'mds_rm_providers', $providers );
		} else {
			// Restore brand colors for older configurations with blank or black colors.
			$brand_colors = array(
				'Spotify'       => '#1DB954',
				'Apple Music'   => '#FA243C',
				'Amazon Music'  => '#00A8E1',
				'YouTube Music' => '#FF0000',
				'Soundcloud'    => '#FF5500',
			);
			$updated      = false;
			foreach ( $providers as &$p ) {
				if ( isset( $brand_colors[ $p['name'] ] ) && ( empty( $p['color'] ) || '#000000' === $p['color'] ) ) {
					$p['color'] = $brand_colors[ $p['name'] ];
					$updated    = true;
				}
			}
			if ( $updated ) {
				update_option( 'mds_rm_providers', $providers );
			}
		}

		return $providers;
	}
}

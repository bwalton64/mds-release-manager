<?php
/**
 * WordPress Abilities API Integration
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers WordPress Abilities for the MDS Release Manager plugin.
 *
 * Uses the WordPress 6.9+ Abilities API to expose core plugin features as native
 * MCP tools, discoverable by the official WordPress MCP Adapter.
 *
 * @since 0.9.0
 */
final class Abilities {

	/**
	 * Registers the ability hooks.
	 *
	 * @since 0.9.0
	 */
	public static function register_hooks(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_ability_categories' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
	}

	/**
	 * Registers the custom ability category for MDS Release Manager.
	 *
	 * @since 0.9.0
	 */
	public static function register_ability_categories(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			'mds_release_manager',
			array(
				'label'       => __( 'MDS Release Manager', 'mds-release-manager' ),
				'description' => __( 'Manage artist releases, formats, and distribution settings', 'mds-release-manager' ),
			)
		);
	}

	/**
	 * Registers all MDS Release Manager abilities.
	 *
	 * @since 0.9.0
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		// Get Releases ability.
		wp_register_ability(
			'mds_rm_get_releases',
			array(
				'label'               => __( 'Get Releases', 'mds-release-manager' ),
				'description'         => __( 'Retrieve a list of published artist releases with metadata', 'mds-release-manager' ),
				'category'            => 'mds_release_manager',
				'capability'          => 'read',
				'permission_callback' => array( self::class, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'per_page' => array(
							'type'        => 'integer',
							'description' => __( 'Number of releases per page', 'mds-release-manager' ),
							'default'     => 10,
							'minimum'     => 1,
							'maximum'     => 100,
						),
						'page'     => array(
							'type'        => 'integer',
							'description' => __( 'Page number for pagination', 'mds-release-manager' ),
							'default'     => 1,
							'minimum'     => 1,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'releases' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'     => array(
										'type'        => 'integer',
										'description' => __( 'Release post ID', 'mds-release-manager' ),
									),
									'title'  => array(
										'type'        => 'string',
										'description' => __( 'Release title', 'mds-release-manager' ),
									),
									'upc'    => array(
										'type'        => 'string',
										'description' => __( 'UPC/EAN identifier', 'mds-release-manager' ),
									),
									'format' => array(
										'type'        => 'string',
										'description' => __( 'Release format (Single, EP, Album, Digital)', 'mds-release-manager' ),
									),
								),
							),
						),
						'total'    => array(
							'type'        => 'integer',
							'description' => __( 'Total number of releases', 'mds-release-manager' ),
						),
						'pages'    => array(
							'type'        => 'integer',
							'description' => __( 'Total number of pages', 'mds-release-manager' ),
						),
					),
				),
				'callback'            => array( self::class, 'ability_get_releases' ),
				'meta'                => array(
					'mcp' => array(
						'public' => true,
					),
				),
			)
		);

		// Save Settings ability.
		wp_register_ability(
			'mds_rm_save_settings',
			array(
				'label'               => __( 'Save Plugin Settings', 'mds-release-manager' ),
				'description'         => __( 'Update plugin settings including providers, audio players, and global redirect', 'mds-release-manager' ),
				'category'            => 'mds_release_manager',
				'capability'          => 'manage_options',
				'permission_callback' => array( self::class, 'check_manage_options_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'providers'       => array(
							'type'        => 'object',
							'description' => __( 'Release providers configuration', 'mds-release-manager' ),
							'properties'  => array(
								'name'  => array(
									'type'        => 'array',
									'items'       => array( 'type' => 'string' ),
									'description' => __( 'Provider names', 'mds-release-manager' ),
								),
								'icon'  => array(
									'type'        => 'array',
									'items'       => array( 'type' => 'string' ),
									'description' => __( 'Provider icon classes or URLs', 'mds-release-manager' ),
								),
								'color' => array(
									'type'        => 'array',
									'items'       => array( 'type' => 'string' ),
									'description' => __( 'Provider brand colors', 'mds-release-manager' ),
								),
							),
						),
						'audio_players'   => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Enabled audio player slugs', 'mds-release-manager' ),
						),
						'global_redirect' => array(
							'type'        => 'boolean',
							'description' => __( 'Enable global artist homepage redirect', 'mds-release-manager' ),
						),
						'fa_support'      => array(
							'type'        => 'string',
							'description' => __( 'Font Awesome source setting', 'mds-release-manager' ),
							'enum'        => array( 'auto', 'cdn', 'plugin', 'theme', 'none' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether settings were saved successfully', 'mds-release-manager' ),
						),
						'message' => array(
							'type'        => 'string',
							'description' => __( 'Response message', 'mds-release-manager' ),
						),
					),
				),
				'callback'            => array( self::class, 'ability_save_settings' ),
				'meta'                => array(
					'mcp' => array(
						'public' => true,
					),
				),
			)
		);

		// Save Network Settings ability.
		wp_register_ability(
			'mds_rm_save_network_settings',
			array(
				'label'               => __( 'Save Network Settings', 'mds-release-manager' ),
				'description'         => __( 'Update network-wide settings for the multisite release manager', 'mds-release-manager' ),
				'category'            => 'mds_release_manager',
				'capability'          => 'manage_network_options',
				'permission_callback' => array( self::class, 'check_manage_network_options_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'master_blog_id'    => array(
							'type'        => 'integer',
							'description' => __( 'Master hub site blog ID', 'mds-release-manager' ),
							'minimum'     => 1,
						),
						'wipe_on_uninstall' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether to delete all plugin data on uninstall', 'mds-release-manager' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether settings were saved successfully', 'mds-release-manager' ),
						),
						'message' => array(
							'type'        => 'string',
							'description' => __( 'Response message', 'mds-release-manager' ),
						),
					),
				),
				'callback'            => array( self::class, 'ability_save_network_settings' ),
				'meta'                => array(
					'mcp' => array(
						'public' => true,
					),
				),
			)
		);

		// Sync Network ability.
		wp_register_ability(
			'mds_rm_sync_network',
			array(
				'label'               => __( 'Sync Network Data', 'mds-release-manager' ),
				'description'         => __( 'Synchronize releases and artists across all network sites in resumable batches', 'mds-release-manager' ),
				'category'            => 'mds_release_manager',
				'capability'          => 'manage_network_options',
				'permission_callback' => array( self::class, 'check_manage_network_options_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'job_id' => array(
							'type'        => 'string',
							'description' => __( 'Job ID returned by a previous batch; omit to start or resume the active job.', 'mds-release-manager' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'         => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the sync has encountered synchronization errors so far', 'mds-release-manager' ),
						),
						'done'            => array(
							'type'        => 'boolean',
							'description' => __( 'Whether all records have been synchronized', 'mds-release-manager' ),
						),
						'job_id'          => array(
							'type'        => 'string',
							'description' => __( 'Job ID to pass to the next batch request', 'mds-release-manager' ),
						),
						'phase'           => array(
							'type'        => 'string',
							'description' => __( 'Current phase: artists, releases, or complete', 'mds-release-manager' ),
						),
						'message'         => array(
							'type'        => 'string',
							'description' => __( 'Batch progress message with cumulative counts', 'mds-release-manager' ),
						),
						'artists_synced'  => array(
							'type'        => 'integer',
							'description' => __( 'Number of artists synchronized', 'mds-release-manager' ),
						),
						'releases_synced' => array(
							'type'        => 'integer',
							'description' => __( 'Number of releases synchronized', 'mds-release-manager' ),
						),
						'errors'          => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Any errors that occurred during sync', 'mds-release-manager' ),
						),
						'error_count'     => array(
							'type'        => 'integer',
							'description' => __( 'Total synchronization errors, including errors beyond the retained detail limit', 'mds-release-manager' ),
						),
					),
				),
				'callback'            => array( self::class, 'ability_sync_network' ),
				'meta'                => array(
					'mcp' => array(
						'public' => true,
					),
				),
			)
		);
	}

	/**
	 * Checks if the current user can read plugin data.
	 *
	 * @since 0.9.0
	 * @return bool Whether the user has read capability.
	 */
	public static function check_read_permission(): bool {
		return current_user_can( 'read' );
	}

	/**
	 * Checks if the current user can manage plugin options.
	 *
	 * @since 0.9.0
	 * @return bool Whether the user has manage_options capability.
	 */
	public static function check_manage_options_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Checks if the current user can manage network options.
	 *
	 * @since 0.9.0
	 * @return bool Whether the user has manage_network_options capability.
	 */
	public static function check_manage_network_options_permission(): bool {
		return current_user_can( 'manage_network_options' );
	}

	/**
	 * Ability callback: Get releases.
	 *
	 * @since 0.9.0
	 * @param array $input The input parameters.
	 * @return array The ability response.
	 */
	public static function ability_get_releases( $input ): array {
		$per_page = isset( $input['per_page'] ) ? absint( $input['per_page'] ) : 10;
		$page     = isset( $input['page'] ) ? absint( $input['page'] ) : 1;

		// Clamp per_page to the supported range.
		$per_page = min( max( $per_page, 1 ), 100 );

		$args = array(
			'post_type'      => 'release',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
		);

		$query    = new \WP_Query( $args );
		$releases = $query->posts;
		$data     = array();

		foreach ( $releases as $rel ) {
			$format_terms = wp_get_post_terms( $rel->ID, 'release_format', array( 'fields' => 'names' ) );
			$format       = ! is_wp_error( $format_terms ) && ! empty( $format_terms ) ? $format_terms[0] : '';

			$data[] = array(
				'id'     => (int) $rel->ID,
				'title'  => $rel->post_title,
				'upc'    => (string) get_post_meta( $rel->ID, 'upc_ean', true ),
				'format' => $format,
			);
		}

		return array(
			'releases' => $data,
			'total'    => (int) $query->found_posts,
			'pages'    => (int) $query->max_num_pages,
		);
	}

	/**
	 * Ability callback: Save settings.
	 *
	 * @since 0.9.0
	 * @param array $input The input parameters.
	 * @return array The ability response.
	 */
	public static function ability_save_settings( $input ): array {
		try {
			$providers = array();
			if ( isset( $input['providers'] ) && is_array( $input['providers'] ) ) {
				$names  = isset( $input['providers']['name'] ) ? (array) $input['providers']['name'] : array();
				$icons  = isset( $input['providers']['icon'] ) ? (array) $input['providers']['icon'] : array();
				$colors = isset( $input['providers']['color'] ) ? (array) $input['providers']['color'] : array();

				$count = count( $names );
				for ( $i = 0; $i < $count; $i++ ) {
					$name = sanitize_text_field( wp_unslash( $names[ $i ] ?? '' ) );
					if ( empty( $name ) ) {
						continue;
					}
					$providers[] = array(
						'name'  => $name,
						'icon'  => sanitize_text_field( wp_unslash( $icons[ $i ] ?? '' ) ),
						'color' => sanitize_text_field( wp_unslash( $colors[ $i ] ?? '' ) ),
					);
				}
			}
			update_option( 'mds_rm_providers', $providers );

			if ( isset( $input['audio_players'] ) && is_array( $input['audio_players'] ) ) {
				$players = \MDS\ReleaseManager\Core\AudioPlayers::sanitize_active_players( wp_unslash( $input['audio_players'] ) );
				update_option( 'mds_rm_audio_players', $players );
			} else {
				update_option( 'mds_rm_audio_players', array() );
			}

			if ( isset( $input['audio_player_settings'] ) && is_array( $input['audio_player_settings'] ) ) {
				$validated_audio_settings = \MDS\ReleaseManager\Core\AudioPlayers::validate_settings( $input['audio_player_settings'] );
				if ( is_wp_error( $validated_audio_settings ) ) {
					return array(
						'success' => false,
						'message' => $validated_audio_settings->get_error_message(),
					);
				}
				update_option( 'mds_rm_audio_player_settings', $validated_audio_settings );
			}

			if ( isset( $input['global_redirect'] ) && $input['global_redirect'] ) {
				update_option( 'mds_rm_global_redirect', '1' );
			} else {
				update_option( 'mds_rm_global_redirect', '0' );
			}

			if ( isset( $input['fa_support'] ) ) {
				update_option( 'mds_rm_fa_support', \MDS\ReleaseManager\Frontend\Assets::sanitize_font_awesome_source( wp_unslash( $input['fa_support'] ) ) );
			}

			\MDS\ReleaseManager\Core\Sync::purge_cache( 'settings' );

			return array(
				'success' => true,
				'message' => __( 'Settings saved successfully', 'mds-release-manager' ),
			);
		} catch ( \Exception $e ) {
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * Ability callback: Save network settings.
	 *
	 * @since 0.9.0
	 * @param array $input The input parameters.
	 * @return array The ability response.
	 */
	public static function ability_save_network_settings( $input ): array {
		try {
			if ( isset( $input['master_blog_id'] ) ) {
				update_site_option( 'mds_rm_master_blog_id', absint( $input['master_blog_id'] ) );
			}

			if ( isset( $input['wipe_on_uninstall'] ) && $input['wipe_on_uninstall'] ) {
				update_site_option( 'mds_rm_wipe_on_uninstall', '1' );
			} else {
				update_site_option( 'mds_rm_wipe_on_uninstall', '0' );
			}

			return array(
				'success' => true,
				'message' => __( 'Network settings saved successfully', 'mds-release-manager' ),
			);
		} catch ( \Exception $e ) {
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * Ability callback: Sync one batch of network data.
	 *
	 * @since 0.9.0
	 * @param array $input Input supplied by the Abilities API.
	 * @return array The batch progress or an error response.
	 */
	public static function ability_sync_network( $input ): array {
		$job_id = isset( $input['job_id'] ) && is_string( $input['job_id'] ) ? sanitize_text_field( $input['job_id'] ) : '';
		$result = \MDS\ReleaseManager\Core\Sync::run_network_sync_batch( $job_id );
		if ( is_wp_error( $result ) ) {
			return array(
				'success'         => false,
				'done'            => false,
				'job_id'          => $job_id,
				'phase'           => '',
				'message'         => $result->get_error_message(),
				'artists_synced'  => 0,
				'releases_synced' => 0,
				'error_count'     => 1,
				'errors'          => array( $result->get_error_message() ),
			);
		}

		return $result;
	}
}

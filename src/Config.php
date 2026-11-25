<?php
/**
 * Centralized Tier 2 system configuration constants for MDS Release Manager.
 *
 * @package MDS\ReleaseManager
 * @since   1.1.0
 */

declare(strict_types=1);

namespace MDS\ReleaseManager;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for non-translatable plugin identifiers, option keys,
 * post/term meta keys, transients, REST routes, and lazy i18n label helpers.
 *
 * @since 1.1.0
 */
final class Config {

	/**
	 * Primary site-level option keys.
	 */
	public const OPTION_SETTINGS                 = 'mds_rm_block_defaults';
	public const OPTION_TEMPLATE_DEFAULTS        = 'mds_rm_template_defaults';
	public const OPTION_CACHE_SETTINGS           = 'mds_rm_cache_settings';
	public const OPTION_PROVIDERS                = 'mds_rm_providers';
	public const OPTION_AUDIO_PROVIDER_PRIORITY  = 'mds_rm_audio_provider_priority';
	public const OPTION_AUDIO_DISABLED_PROVIDERS = 'mds_rm_audio_disabled_providers';
	public const OPTION_FA_SUPPORT               = 'mds_rm_fa_support';
	public const OPTION_NETWORK_SYNC_JOB         = 'mds_rm_network_sync_job';

	/**
	 * Multisite network-level option keys.
	 */
	public const SITE_OPTION_MASTER_BLOG_ID    = 'mds_rm_master_blog_id';
	public const SITE_OPTION_SPOKE_SITES       = 'mds_rm_spoke_sites';
	public const SITE_OPTION_DB_TEMPLATES      = 'mds_rm_db_templates';
	public const SITE_OPTION_WIPE_ON_UNINSTALL = 'mds_rm_wipe_on_uninstall';

	/**
	 * Transient prefix for locale-aware caching.
	 */
	public const TRANSIENT_PREFIX = 'mds_rm_';

	/**
	 * REST API namespace.
	 */
	public const REST_NAMESPACE = 'mds/v1';

	/**
	 * Custom post type and taxonomy slugs.
	 */
	public const CPT_RELEASE      = 'release';
	public const CPT_ARTIST       = 'artist';
	public const TAX_RELEASE_TYPE = 'release_type';
	public const TAX_MEDIA_FORMAT = 'release_media_format';
	public const TAX_RELEASE_TAG  = 'release_tag';

	/**
	 * Administrative capability required to manage plugin settings.
	 */
	public const CAP_MANAGE = 'manage_options';

	/**
	 * Builds a locale-aware transient key to prevent multilingual cache collisions.
	 *
	 * @since 1.1.0
	 *
	 * @param string $suffix Descriptive transient suffix.
	 * @return string Locale-scoped transient key (max 172 chars for WordPress).
	 */
	public static function transient_key( string $suffix ): string {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$clean  = sanitize_key( $suffix );
		return substr( self::TRANSIENT_PREFIX . $clean . '_' . sanitize_key( $locale ), 0, 172 );
	}

	/**
	 * Returns default user-facing labels lazily at runtime (call on or after `init`).
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, string> Translatable default labels.
	 */
	public static function get_default_labels(): array {
		return array(
			'plugin_name'     => __( 'MDS Release Manager', 'mds-release-manager' ),
			'contact_heading' => __( 'Contact Information', 'mds-release-manager' ),
			'buy_now'         => __( 'Buy Now', 'mds-release-manager' ),
			'choose_format'   => __( 'Choose a Format', 'mds-release-manager' ),
		);
	}
}

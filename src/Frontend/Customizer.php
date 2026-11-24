<?php
/**
 * Backward-compatibility shim for legacy Customizer helpers.
 *
 * The MDS Release Manager Customizer panel has been retired and replaced by
 * Releases -> Settings -> Default Templates (`MDS_RM_Template_Defaults`).
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Delegates legacy fallback style helpers to MDS_RM_Template_Defaults.
 *
 * @since 0.9.0
 */
final class Customizer {

	/**
	 * No-op retained for backward compatibility; the Customizer panel is retired.
	 *
	 * @since 0.9.0
	 */
	public static function register_hooks(): void {
		// Intentionally empty: fallback template settings now live in Releases -> Settings -> Default Templates.
	}

	/**
	 * Builds safe inline CSS custom properties for the Release fallback partial.
	 *
	 * @return string CSS custom-property declarations.
	 */
	public static function get_release_fallback_style_attribute(): string {
		return TemplateDefaults::get_release_style_attribute();
	}

	/**
	 * Builds inline custom properties for the Artist fallback partial.
	 *
	 * @return string CSS custom-property declarations.
	 */
	public static function get_artist_fallback_style_attribute(): string {
		return TemplateDefaults::get_artist_style_attribute();
	}

	/**
	 * Sanitizes a fallback color, including alpha colors.
	 *
	 * @param mixed $value Submitted color.
	 * @return string|null Safe color, empty string to inherit, or null if invalid.
	 */
	public static function sanitize_fallback_color( mixed $value ): ?string {
		return TemplateDefaults::sanitize_color( $value );
	}

	/**
	 * Validates the image shadow choice.
	 *
	 * @param mixed $value Submitted shadow preset.
	 * @return string|null Valid preset or null.
	 */
	public static function sanitize_shadow( mixed $value ): ?string {
		return in_array( $value, array( 'none', 'subtle', 'default', 'elevated' ), true ) ? $value : null;
	}
}

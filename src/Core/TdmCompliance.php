<?php
/**
 * Free Core W3C TDM Reservation Protocol (`/.well-known/tdmrep.json`) & `TDM-Reservation: 1` header.
 *
 * Zero external API calls; zero web-root file writes; filterable via `mds_rm_tdmrep_rules`.
 *
 * @package   MDS\ReleaseManager
 * @copyright 2026 Musty Dungeon Studios (Walton International Business Group LLC)
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Publishes site-wide W3C TDM opt-out declarations in Free Core.
 *
 * @since 2.2.0
 */
final class TdmCompliance {

	/**
	 * Core option key for TDM settings.
	 *
	 * @var string
	 */
	public const OPTION_TDM = 'mds_rm_tdm_settings';

	/**
	 * Legacy companion option key checked on read.
	 *
	 * @var string
	 */
	public const LEGACY_OPTION_TDM = 'mds_md_tdm_settings';

	/**
	 * Registers dynamic `/.well-known/tdmrep.json` and `TDM-Reservation` header hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'parse_request', array( self::class, 'maybe_serve_tdmrep' ), 1 );
		add_action( 'send_headers', array( self::class, 'maybe_send_headers' ) );
	}

	/**
	 * Returns sanitized site-wide TDM settings.
	 *
	 * @return array{enabled:bool,reservation:int,policy_url:string}
	 */
	public static function get_settings(): array {
		$raw = function_exists( 'get_option' ) ? get_option( self::OPTION_TDM, null ) : null;
		if ( null === $raw || false === $raw ) {
			$raw = function_exists( 'get_option' ) ? get_option( self::LEGACY_OPTION_TDM, array() ) : array();
		}
		$raw = is_array( $raw ) ? $raw : array();
		return array(
			'enabled'     => ! array_key_exists( 'enabled', $raw ) || ! empty( $raw['enabled'] ),
			'reservation' => isset( $raw['reservation'] ) && 0 === (int) $raw['reservation'] ? 0 : 1,
			'policy_url'  => esc_url_raw( (string) ( $raw['policy_url'] ?? '' ) ),
		);
	}

	/**
	 * Builds the W3C `tdmrep.json` payload.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function build_payload(): array {
		$settings = self::get_settings();
		$rule     = array(
			'location'        => '/*',
			'tdm-reservation' => $settings['reservation'],
		);
		if ( '' !== $settings['policy_url'] ) {
			$rule['tdm-policy'] = $settings['policy_url'];
		}
		$rules = apply_filters( 'mds_rm_tdmrep_rules', array( $rule ) );
		return is_array( $rules ) ? $rules : array( $rule );
	}

	/**
	 * Dynamically serves `/.well-known/tdmrep.json`.
	 *
	 * @param \WP $wp WordPress request environment.
	 * @return void
	 */
	public static function maybe_serve_tdmrep( \WP $wp ): void {
		$settings = self::get_settings();
		if ( ! $settings['enabled'] ) {
			return;
		}
		if ( '.well-known/tdmrep.json' !== trim( (string) $wp->request, '/' ) ) {
			return;
		}
		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'TDM-Reservation: ' . $settings['reservation'] );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response payload for /.well-known/tdmrep.json.
		echo (string) wp_json_encode( self::build_payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Emits `TDM-Reservation` HTTP headers on frontend requests.
	 *
	 * @return void
	 */
	public static function maybe_send_headers(): void {
		if ( is_admin() || headers_sent() ) {
			return;
		}
		$settings = self::get_settings();
		if ( ! $settings['enabled'] ) {
			return;
		}
		header( 'TDM-Reservation: ' . $settings['reservation'] );
		if ( '' !== $settings['policy_url'] ) {
			header( 'TDM-Policy: ' . $settings['policy_url'] );
		}
	}
}

<?php
/**
 * Debug logging for the MDS Release Manager plugin.
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes plugin diagnostics and operational failures.
 */
final class Logger {

	/**
	 * Publishes a message when WordPress debugging and debug logging are enabled.
	 *
	 * @param mixed $message The value to log.
	 */
	public static function log( mixed $message ): void {
		if ( ! self::debug_logging_enabled() ) {
			return;
		}

		$prefix  = '[MDS Release Manager] ';
		$message = is_array( $message ) || is_object( $message ) ? wp_json_encode( $message ) : $message;
		if ( false === $message ) {
			$message = '[Unable to encode log message]';
		}
		do_action( 'mds_rm_log_message', $prefix . $message );
	}

	/**
	 * Writes a routine diagnostic only when debug logging is enabled.
	 *
	 * @param string $message Diagnostic without the plugin prefix.
	 */
	public static function diagnostic( string $message ): void {
		if ( self::debug_logging_enabled() ) {
			self::write( $message );
		}
	}

	/**
	 * Writes an operational failure regardless of the debug settings.
	 *
	 * @param string $message Failure without the plugin prefix.
	 */
	public static function report_failure( string $message ): void {
		self::write( $message );
	}

	/**
	 * Checks whether WordPress debug logging is enabled.
	 *
	 * @return bool Whether both debug flags are enabled.
	 */
	private static function debug_logging_enabled(): bool {
		return defined( 'WP_DEBUG' ) && true === WP_DEBUG
			&& defined( 'WP_DEBUG_LOG' ) && true === WP_DEBUG_LOG;
	}

	/**
	 * Writes a prefixed message to the configured PHP error log.
	 *
	 * @param string $message Unprefixed message.
	 */
	private static function write( string $message ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Central sink for operational failures and explicitly enabled debug diagnostics.
		error_log( '[MDS Release Manager] ' . $message );
	}
}

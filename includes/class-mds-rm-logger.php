<?php
declare(strict_types=1);

/**
 * Debug logging for the MDS Release Manager plugin.
 *
 * @package MDS_Release_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Publishes plugin debug messages through the logging action.
 */
final class MDS_RM_Logger {

	/**
	 * Publishes a message when WordPress debugging and debug logging are enabled.
	 *
	 * @param mixed $message The value to log.
	 */
	public static function log( $message ) {
		if (
			! defined( 'WP_DEBUG' )
			|| true !== WP_DEBUG
			|| ! defined( 'WP_DEBUG_LOG' )
			|| true !== WP_DEBUG_LOG
		) {
			return;
		}

		$prefix  = '[MDS Release Manager] ';
		$message = is_array( $message ) || is_object( $message ) ? wp_json_encode( $message ) : $message;
		if ( false === $message ) {
			$message = '[Unable to encode log message]';
		}
		do_action( 'mds_rm_log_message', $prefix . $message );
	}
}

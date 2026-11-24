<?php
/**
 * Background synchronization queue supporting Action Scheduler with WP-Cron fallback.
 *
 * @package MDS\ReleaseManager
 * @since   2.1.0
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Core\Sync;

use MDS\ReleaseManager\Core\Logger;
use MDS\ReleaseManager\Core\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates asynchronous Hub-to-Spoke network sync batches using Action Scheduler
 * when available, falling back seamlessly to WP-Cron.
 *
 * @since 2.1.0
 */
final class SyncQueue {

	/**
	 * Action hook processed by Action Scheduler or WP-Cron.
	 *
	 * @var string
	 */
	public const HOOK = 'mds_rm_process_sync_queue';

	/**
	 * Action Scheduler group identifier.
	 *
	 * @var string
	 */
	public const GROUP = 'mds-release-manager';

	/**
	 * Registers the background worker callback hook.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( self::HOOK, array( self::class, 'process_tick' ), 10, 1 );
	}

	/**
	 * Returns the active background queue driver identifier.
	 *
	 * @since 2.1.0
	 *
	 * @return string `'action_scheduler'` or `'wp_cron'`.
	 */
	public static function driver(): string {
		return function_exists( 'as_enqueue_async_action' ) ? 'action_scheduler' : 'wp_cron';
	}

	/**
	 * Enqueues one background sync tick for a job ID (deduplicated).
	 *
	 * @since 2.1.0
	 *
	 * @param string $job_id Optional sync job ID (empty string starts/resumes current job).
	 * @return array{queued: bool, driver: string, job_id: string} Enqueue summary.
	 */
	public static function enqueue( string $job_id = '' ): array {
		$driver = self::driver();
		$args   = array( 'job_id' => $job_id );

		if ( 'action_scheduler' === $driver ) {
			if ( ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
				as_enqueue_async_action( self::HOOK, $args, self::GROUP );
			}
			return array(
				'queued' => true,
				'driver' => $driver,
				'job_id' => $job_id,
			);
		}

		if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_single_event' ) ) {
			if ( ! wp_next_scheduled( self::HOOK, array( $job_id ) ) ) {
				wp_schedule_single_event( time() + 1, self::HOOK, array( $job_id ) );
			}
		}

		return array(
			'queued' => true,
			'driver' => $driver,
			'job_id' => $job_id,
		);
	}

	/**
	 * Executes one background sync batch and self-schedules the next tick until complete.
	 *
	 * @since 2.1.0
	 *
	 * @param string $job_id Sync job ID.
	 * @return void
	 */
	public static function process_tick( string $job_id = '' ): void {
		$result = Sync::run_network_sync_batch( $job_id );
		if ( is_wp_error( $result ) ) {
			if ( 'mds_sync_busy' === $result->get_error_code() ) {
				// Another worker holds the lock; retry shortly.
				if ( 'action_scheduler' === self::driver() && function_exists( 'as_schedule_single_action' ) ) {
					as_schedule_single_action( time() + 15, self::HOOK, array( 'job_id' => $job_id ), self::GROUP );
				} elseif ( function_exists( 'wp_schedule_single_event' ) ) {
					wp_schedule_single_event( time() + 15, self::HOOK, array( $job_id ) );
				}
				return;
			}
			Logger::report_failure( 'Background network sync tick failed: ' . $result->get_error_message() );
			return;
		}

		if ( empty( $result['done'] ) && ! empty( $result['job_id'] ) ) {
			self::enqueue( (string) $result['job_id'] );
		}
	}
}

<?php
/**
 * Transport contract for Hub-to-Spoke synchronization.
 *
 * Allows MDS Release Manager to broadcast JSON-serializable catalog payloads
 * and lifecycle tombstones to local WordPress Multisite spokes today and to
 * remote WordPress installs over signed REST/webhooks in the future.
 *
 * @package MDS\ReleaseManager
 * @since   2.1.0
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Core\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract implemented by all Hub-to-Spoke synchronization transports.
 *
 * @since 2.1.0
 */
interface SyncTransportInterface {

	/**
	 * Returns the unique transport identifier (e.g. `multisite`, `rest_webhook`).
	 *
	 * @since 2.1.0
	 *
	 * @return string Transport slug.
	 */
	public function id(): string;

	/**
	 * Determines whether this transport can deliver to the given target descriptor.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed> $target Target descriptor (e.g. `['type' => 'multisite', 'blog_id' => 2]`).
	 * @return bool True when this transport handles the target.
	 */
	public function supports( array $target ): bool;

	/**
	 * Pushes a batch of JSON-serializable Hub payloads to one target.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed>             $target  Target descriptor.
	 * @param array<int, array<string, mixed>> $entries List of `['key' => string, 'payload' => array]` entries.
	 * @return array<string, string|null> Map of entry key (`"{post_type}:{id}"`) to error message or null on success.
	 */
	public function push_batch( array $target, array $entries ): array;

	/**
	 * Applies a batch of lifecycle tombstones (`trash`, `untrash`, `delete`) to one target.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed>             $target     Target descriptor.
	 * @param array<int, array<string, mixed>> $tombstones List of JSON-serializable tombstone arrays.
	 * @return array<string, string|null> Map of tombstone key (`"{post_type}:{id}"`) to error message or null on success.
	 */
	public function delete_batch( array $target, array $tombstones ): array;
}

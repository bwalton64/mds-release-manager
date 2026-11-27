<?php
/**
 * Neutral Secret Schema Registry for MDS Release Manager and companion plugins.
 *
 * Companion plugins register their credential schemas via the
 * `mds_rm_register_secret_schemas` filter so administrators manage all tokens,
 * Client IDs, and API keys from a single centralized Hub screen (`Releases → Settings → Credentials`),
 * while shared namespaces (e.g. `spotify`, `musicfetch`, `github`) deduplicate automatically.
 *
 * @package MDS\ReleaseManager
 * @since   2.2.0
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Core\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Discovers, merges, and validates secret schemas registered by Core and companion plugins.
 *
 * @since 2.2.0
 */
final class SecretRegistry {

	/**
	 * Discovers all registered secret schemas, merging duplicate namespaces cleanly.
	 *
	 * @return array<string, array<string, mixed>> Schemas keyed by canonical namespace.
	 */
	public static function get_schemas(): array {
		$defaults = array(
			'github' => array(
				'namespace'   => 'github',
				'label'       => __( 'GitHub Auto-Updater', 'mds-release-manager' ),
				'description' => __( 'Optional GitHub Personal Access Token for private plugin repositories or higher GitHub API rate limits.', 'mds-release-manager' ),
				'plugin'      => __( 'MDS Release Manager', 'mds-release-manager' ),
				'docs_url'    => 'https://github.com/settings/tokens',
				'fields'      => array(
					'token' => array(
						'label'     => __( 'GitHub Personal Access Token', 'mds-release-manager' ),
						'is_secret' => true,
						'const'     => array( 'MDS_RM_GITHUB_TOKEN' ),
						'env'       => array( 'MDS_RM_GITHUB_TOKEN' ),
					),
				),
			),
		);

		/**
		 * Filters the registered secret schemas displayed in Releases → Settings → Credentials.
		 *
		 * Companion plugins append schemas keyed by canonical service namespace (e.g. `spotify`,
		 * `soundcloud`, `discogs`, `musicfetch`). Duplicate namespaces merge their `fields`,
		 * `plugins`, and override lists automatically.
		 *
		 * @since 2.2.0
		 *
		 * @param array<string, array<string, mixed>> $schemas Registered schemas keyed by namespace.
		 */
		$filtered = function_exists( 'apply_filters' ) ? apply_filters( 'mds_rm_register_secret_schemas', $defaults ) : $defaults;
		if ( ! is_array( $filtered ) ) {
			$filtered = $defaults;
		}

		$normalized = array();
		foreach ( $filtered as $raw_ns => $schema ) {
			if ( ! is_array( $schema ) ) {
				continue;
			}
			$ns = sanitize_key( (string) ( $schema['namespace'] ?? $raw_ns ) );
			if ( '' === $ns || empty( $schema['fields'] ) || ! is_array( $schema['fields'] ) ) {
				continue;
			}

			$fields = array();
			foreach ( $schema['fields'] as $raw_field => $field_def ) {
				if ( ! is_array( $field_def ) ) {
					continue;
				}
				$fkey = sanitize_key( (string) $raw_field );
				if ( '' === $fkey ) {
					continue;
				}
				$fields[ $fkey ] = array(
					'key'         => $fkey,
					'label'       => sanitize_text_field( (string) ( $field_def['label'] ?? $fkey ) ),
					'placeholder' => sanitize_text_field( (string) ( $field_def['placeholder'] ?? '' ) ),
					'is_secret'   => ! array_key_exists( 'is_secret', $field_def ) || (bool) $field_def['is_secret'],
					'const'       => self::normalize_string_array( $field_def['const'] ?? array() ),
					'env'         => self::normalize_string_array( $field_def['env'] ?? array() ),
				);
			}

			if ( empty( $fields ) ) {
				continue;
			}

			$plugin_label = sanitize_text_field( (string) ( $schema['plugin'] ?? '' ) );

			if ( isset( $normalized[ $ns ] ) ) {
				foreach ( $fields as $fkey => $fdef ) {
					if ( isset( $normalized[ $ns ]['fields'][ $fkey ] ) ) {
						$normalized[ $ns ]['fields'][ $fkey ]['const'] = array_values( array_unique( array_merge( $normalized[ $ns ]['fields'][ $fkey ]['const'], $fdef['const'] ) ) );
						$normalized[ $ns ]['fields'][ $fkey ]['env']   = array_values( array_unique( array_merge( $normalized[ $ns ]['fields'][ $fkey ]['env'], $fdef['env'] ) ) );
					} else {
						$normalized[ $ns ]['fields'][ $fkey ] = $fdef;
					}
				}
				if ( '' !== $plugin_label && ! in_array( $plugin_label, $normalized[ $ns ]['plugins'], true ) ) {
					$normalized[ $ns ]['plugins'][] = $plugin_label;
				}
				if ( empty( $normalized[ $ns ]['test_callback'] ) && ! empty( $schema['test_callback'] ) && is_callable( $schema['test_callback'] ) ) {
					$normalized[ $ns ]['test_callback'] = $schema['test_callback'];
				}
				continue;
			}

			$normalized[ $ns ] = array(
				'namespace'     => $ns,
				'label'         => sanitize_text_field( (string) ( $schema['label'] ?? ucfirst( $ns ) ) ),
				'description'   => sanitize_text_field( (string) ( $schema['description'] ?? '' ) ),
				'plugins'       => '' !== $plugin_label ? array( $plugin_label ) : array(),
				'docs_url'      => ! empty( $schema['docs_url'] ) && is_string( $schema['docs_url'] ) ? esc_url_raw( $schema['docs_url'] ) : '',
				'fields'        => $fields,
				'test_callback' => ! empty( $schema['test_callback'] ) && is_callable( $schema['test_callback'] ) ? $schema['test_callback'] : null,
			);
		}

		return $normalized;
	}

	/**
	 * Builds a REST-safe payload of all schemas and their masked statuses (never exposes raw secrets).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_admin_descriptors(): array {
		$out = array();
		foreach ( self::get_schemas() as $ns => $schema ) {
			$fields_out = array();
			foreach ( $schema['fields'] as $fkey => $fdef ) {
				$status       = SecretVault::get_status(
					$ns,
					$fkey,
					array(
						'const'     => $fdef['const'],
						'env'       => $fdef['env'],
						'is_secret' => $fdef['is_secret'],
					)
				);
				$fields_out[] = array(
					'key'         => $fkey,
					'label'       => $fdef['label'],
					'placeholder' => $fdef['placeholder'],
					'is_secret'   => $fdef['is_secret'],
					'const_names' => $fdef['const'],
					'env_names'   => $fdef['env'],
					'status'      => $status,
				);
			}

			$out[] = array(
				'namespace'   => $ns,
				'label'       => $schema['label'],
				'description' => $schema['description'],
				'plugins'     => $schema['plugins'],
				'docs_url'    => $schema['docs_url'],
				'can_test'    => null !== $schema['test_callback'],
				'fields'      => $fields_out,
			);
		}
		return $out;
	}

	/**
	 * Normalizes a string or array of strings into a clean list.
	 *
	 * @param mixed $raw Raw value.
	 * @return string[]
	 */
	private static function normalize_string_array( mixed $raw ): array {
		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			return array( trim( $raw ) );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $item ) {
			if ( is_string( $item ) && '' !== trim( $item ) ) {
				$out[] = trim( $item );
			}
		}
		return array_values( array_unique( $out ) );
	}
}

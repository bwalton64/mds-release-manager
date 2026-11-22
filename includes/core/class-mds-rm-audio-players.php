<?php
/**
 * Audio Player Manager registry, settings validation, and rendering.
 *
 * @package MDS_Release_Manager
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages registered audio preview players, their site-wide settings, and fallback rendering.
 *
 * @since 1.1.0
 */
final class MDS_RM_Audio_Players {
	/**
	 * Built-in core HTML5 audio player slug.
	 *
	 * @var string
	 */
	public const DEFAULT_PLAYER = 'html5';

	/**
	 * Option name storing the active player list (single-element array).
	 *
	 * @var string
	 */
	public const ACTIVE_OPTION = 'mds_rm_audio_players';

	/**
	 * Option name storing declarative per-player settings keyed by player slug.
	 *
	 * @var string
	 */
	public const SETTINGS_OPTION = 'mds_rm_audio_player_settings';

	/**
	 * Supported declarative field types for extension player settings.
	 *
	 * @var string[]
	 */
	private const FIELD_TYPES = array( 'select', 'color', 'toggle', 'range', 'number', 'text' );

	/**
	 * Returns all valid registered audio players, always including the core HTML5 player first.
	 *
	 * Extensions register players via the `mds_rm_register_audio_players` filter.
	 *
	 * @since 1.1.0
	 * @return array<string, array{
	 *     label: string,
	 *     description: string,
	 *     icon: string,
	 *     render_callback: callable,
	 *     fields: array<string, array<string, mixed>>
	 * }> Registered player definitions keyed by slug.
	 */
	public static function get_registered_players(): array {
		$builtin = array(
			self::DEFAULT_PLAYER => array(
				'label'           => __( 'HTML5 Player (.mp3)', 'mds-release-manager' ),
				'description'     => __( 'Built-in browser audio player that plays the release Audio Preview URL (.mp3). Also acts as the automatic fallback whenever a selected third-party player has no audio configured for a release.', 'mds-release-manager' ),
				'icon'            => 'dashicons dashicons-format-audio',
				'render_callback' => array( self::class, 'render_html5' ),
				'fields'          => array(),
			),
		);

		/**
		 * Filters the registered audio preview players.
		 *
		 * Each key must be a sanitized slug (`sanitize_key`) mapping to an array with:
		 * - `label` (string, required): Display title in the Audio Player Manager.
		 * - `description` (string, optional): Explanation shown in the player card.
		 * - `icon` (string, optional): Dashicons or Font Awesome class name.
		 * - `render_callback` (callable, required): `fn( int $post_id, array $settings, array $attributes ): string`.
		 * - `fields` (array, optional): Declarative site-wide settings/styling schema.
		 *
		 * @since 1.1.0
		 * @param array $players Registered player definitions keyed by slug.
		 */
		$filtered = apply_filters( 'mds_rm_register_audio_players', $builtin );
		if ( ! is_array( $filtered ) ) {
			MDS_RM_Logger::report_failure( 'Invalid audio player registry returned from mds_rm_register_audio_players.' );
			return $builtin;
		}

		$valid = $builtin;
		foreach ( $filtered as $slug => $definition ) {
			if ( self::DEFAULT_PLAYER === $slug ) {
				continue;
			}
			if ( ! is_string( $slug ) || '' === $slug || sanitize_key( $slug ) !== $slug || 'native' === $slug || ! is_array( $definition ) ) {
				MDS_RM_Logger::report_failure( 'Invalid audio player slug or definition in mds_rm_register_audio_players.' );
				continue;
			}
			$label    = isset( $definition['label'] ) && is_string( $definition['label'] ) ? trim( $definition['label'] ) : '';
			$callback = $definition['render_callback'] ?? null;
			if ( '' === $label || ! is_callable( $callback ) ) {
				MDS_RM_Logger::report_failure( 'Audio player "' . $slug . '" is missing a valid label or render_callback.' );
				continue;
			}
			$description = isset( $definition['description'] ) && is_string( $definition['description'] ) ? trim( $definition['description'] ) : '';
			$icon        = isset( $definition['icon'] ) && is_string( $definition['icon'] ) ? trim( $definition['icon'] ) : 'dashicons dashicons-format-audio';
			if ( ! self::valid_icon( $icon ) ) {
				MDS_RM_Logger::diagnostic( 'Invalid audio player icon for ' . $slug );
				$icon = 'dashicons dashicons-format-audio';
			}
			$fields = self::sanitize_field_schemas( $slug, $definition['fields'] ?? array() );

			$valid[ $slug ] = array(
				'label'           => $label,
				'description'     => $description,
				'icon'            => $icon,
				'render_callback' => $callback,
				'fields'          => $fields,
			);
		}

		return $valid;
	}

	/**
	 * Formats registered players for the admin React Audio Player Manager UI.
	 *
	 * @since 1.1.0
	 * @return array<int, array<string, mixed>> Serializable player descriptors.
	 */
	public static function get_client_players(): array {
		$client = array();
		foreach ( self::get_registered_players() as $slug => $player ) {
			$fields = array();
			foreach ( $player['fields'] as $key => $field ) {
				$fields[] = array_merge( $field, array( 'key' => $key ) );
			}
			$client[] = array(
				'slug'        => $slug,
				'label'       => $player['label'],
				'description' => $player['description'],
				'icon'        => $player['icon'],
				'isBuiltin'   => self::DEFAULT_PLAYER === $slug,
				'fields'      => $fields,
			);
		}
		return $client;
	}

	/**
	 * Returns the single currently active audio player slug.
	 *
	 * Normalizes legacy `'native'` to `'html5'` and falls back to `'html5'` if a
	 * previously selected third-party player plugin is no longer registered.
	 *
	 * @since 1.1.0
	 * @return string Active player slug, or empty string when explicitly disabled.
	 */
	public static function get_active_player(): string {
		$stored = get_option( self::ACTIVE_OPTION, array( self::DEFAULT_PLAYER ) );
		if ( is_string( $stored ) && '' !== $stored ) {
			$stored = array( $stored );
		}
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return '';
		}
		$registered = self::get_registered_players();
		$normalized = array();
		foreach ( $stored as $candidate ) {
			if ( ! is_string( $candidate ) ) {
				continue;
			}
			$slug = self::normalize_player_slug( $candidate );
			if ( isset( $registered[ $slug ] ) && ! in_array( $slug, $normalized, true ) ) {
				$normalized[] = $slug;
			}
		}
		if ( count( $normalized ) > 1 ) {
			foreach ( $normalized as $slug ) {
				if ( self::DEFAULT_PLAYER !== $slug ) {
					return $slug;
				}
			}
		}
		return $normalized[0] ?? self::DEFAULT_PLAYER;
	}

	/**
	 * Sanitizes a submitted `mds_rm_audio_players` parameter into a single-element array.
	 *
	 * @since 1.1.0
	 * @param mixed $raw Raw submitted player list or slug.
	 * @return string[] Single-element array containing the active player slug, or empty array.
	 */
	public static function sanitize_active_players( mixed $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = '' !== trim( $raw ) ? array( $raw ) : array();
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		foreach ( $raw as $candidate ) {
			if ( ! is_scalar( $candidate ) ) {
				continue;
			}
			$slug = self::normalize_player_slug( sanitize_text_field( (string) $candidate ) );
			if ( '' !== $slug ) {
				return array( $slug );
			}
		}
		return array();
	}

	/**
	 * Normalizes legacy audio player identifiers to current registry slugs.
	 *
	 * @param string $slug Raw player slug.
	 * @return string Normalized player slug.
	 */
	private static function normalize_player_slug( string $slug ): string {
		$clean = sanitize_key( $slug );
		if ( 'native' === $clean ) {
			return self::DEFAULT_PLAYER;
		}
		if ( 'blocks_for_bandcamp' === $clean ) {
			return 'bandcamp';
		}
		return $clean;
	}

	/**
	 * Returns all stored per-player settings merged with each registered player's field defaults.
	 *
	 * @since 1.1.0
	 * @return array<string, array<string, mixed>> Settings keyed by player slug.
	 */
	public static function get_all_settings(): array {
		$stored = get_option( self::SETTINGS_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$result = array();

		foreach ( $stored as $slug => $values ) {
			if ( is_string( $slug ) && is_array( $values ) ) {
				$result[ $slug ] = $values;
			}
		}

		foreach ( self::get_registered_players() as $slug => $player ) {
			$defaults = array();
			foreach ( $player['fields'] as $key => $field ) {
				if ( array_key_exists( 'default', $field ) ) {
					$defaults[ $key ] = $field['default'];
				}
			}
			$saved           = isset( $result[ $slug ] ) && is_array( $result[ $slug ] ) ? $result[ $slug ] : array();
			$result[ $slug ] = array_merge( $defaults, array_intersect_key( $saved, $player['fields'] ) );
		}

		return $result;
	}

	/**
	 * Returns the resolved settings for a single registered player.
	 *
	 * @since 1.1.0
	 * @param string $slug Player slug.
	 * @return array<string, mixed> Player settings with defaults applied.
	 */
	public static function get_player_settings( string $slug ): array {
		$all = self::get_all_settings();
		return $all[ $slug ] ?? array();
	}

	/**
	 * Validates and sanitizes submitted per-player settings against registered schemas.
	 *
	 * Preserves stored settings for currently inactive extensions so deactivating
	 * a plugin does not wipe its saved configuration.
	 *
	 * @since 1.1.0
	 * @param mixed $submitted Decoded array of per-player settings.
	 * @return array<string, array<string, mixed>>|WP_Error Validated settings or error.
	 */
	public static function validate_settings( mixed $submitted ): array|WP_Error {
		if ( ! is_array( $submitted ) ) {
			return self::invalid_settings_error();
		}

		$registered = self::get_registered_players();
		$existing   = get_option( self::SETTINGS_OPTION, array() );
		$validated  = is_array( $existing ) ? $existing : array();

		foreach ( $submitted as $slug => $values ) {
			if ( ! is_string( $slug ) || '' === $slug || sanitize_key( $slug ) !== $slug || ! is_array( $values ) ) {
				return self::invalid_settings_error();
			}
			if ( ! isset( $registered[ $slug ] ) ) {
				continue;
			}
			$schema        = $registered[ $slug ]['fields'];
			$clean_player  = array();
			foreach ( $values as $field_key => $value ) {
				if ( ! is_string( $field_key ) || ! isset( $schema[ $field_key ] ) ) {
					return self::invalid_settings_error();
				}
				if ( null === $value || '' === $value ) {
					continue;
				}
				$field = $schema[ $field_key ];
				$clean = self::validate_field_value( $field, $value );
				if ( is_wp_error( $clean ) ) {
					return $clean;
				}
				$clean_player[ $field_key ] = $clean;
			}
			if ( $clean_player ) {
				$validated[ $slug ] = $clean_player;
			} else {
				unset( $validated[ $slug ] );
			}
		}

		return $validated;
	}

	/**
	 * Renders the audio preview markup for a release.
	 *
	 * If a third-party player is active and returns non-empty markup for the release,
	 * that markup is sanitized and returned. If the third-party player is not configured
	 * for the release (returns empty markup) or HTML5 is the active player, core
	 * automatically falls back to the built-in HTML5 player when `audio_preview_url` exists.
	 *
	 * @since 1.1.0
	 * @param int   $post_id    Release post ID.
	 * @param array $attributes Release Links block or shortcode attributes.
	 * @return string Sanitized audio player HTML, or empty string.
	 */
	public static function render( int $post_id, array $attributes = array() ): string {
		$registered = self::get_registered_players();
		$active     = self::get_active_player();

		// 1. If a registered third-party player is selected as the active player, try it first.
		if ( '' !== $active && self::DEFAULT_PLAYER !== $active && isset( $registered[ $active ] ) ) {
			$settings = self::get_player_settings( $active );
			$html     = call_user_func( $registered[ $active ]['render_callback'], $post_id, $settings, $attributes );
			if ( is_string( $html ) && '' !== trim( $html ) ) {
				return MDS_RM_Template_Loader::kses_shortcode_html( $html );
			}
		}

		// 2. Legacy filter bridge: invoked when a third-party player is active or when
		// an older extension hooks `mds_rm_release_links_audio_html` without registering.
		if ( count( $registered ) === 1 || ( '' !== $active && self::DEFAULT_PLAYER !== $active ) ) {
			/**
			 * Filters the audio player markup for the Release Links block.
			 *
			 * The first extension returning non-empty markup supplies the audio
			 * player and skips the built-in HTML5 player. The result is
			 * sanitized with the plugin's iframe-aware allowlist.
			 *
			 * @since 1.1.0
			 *
			 * @param string $html       Audio player markup. Default empty string.
			 * @param int    $post_id    Release post ID.
			 * @param array  $attributes Release Links block attributes.
			 */
			$extension_html = apply_filters( 'mds_rm_release_links_audio_html', '', $post_id, $attributes );
			if ( is_string( $extension_html ) && '' !== trim( $extension_html ) ) {
				return MDS_RM_Template_Loader::kses_shortcode_html( $extension_html );
			}
		}

		// 3. Core HTML5 Player: renders when active or as the automatic fallback when a
		// selected third-party player is not configured for this release.
		if ( '' !== $active ) {
			return self::render_html5( $post_id );
		}

		return '';
	}

	/**
	 * Renders the built-in HTML5 `<audio>` player for a release's `.mp3` preview URL.
	 *
	 * @since 1.1.0
	 * @param int $post_id Release post ID.
	 * @return string HTML5 audio element or empty string.
	 */
	public static function render_html5( int $post_id ): string {
		$url = get_post_meta( $post_id, 'audio_preview_url', true );
		if ( is_string( $url ) && '' !== $url && '' !== esc_url( $url ) ) {
			return '<audio controls src="' . esc_url( $url ) . '"></audio>';
		}
		return '';
	}

	/**
	 * Validates and normalizes a player's declarative `fields` schema.
	 *
	 * @param string $slug   Player slug.
	 * @param mixed  $fields Raw fields definition.
	 * @return array<string, array<string, mixed>> Validated field definitions.
	 */
	private static function sanitize_field_schemas( string $slug, mixed $fields ): array {
		if ( ! is_array( $fields ) ) {
			MDS_RM_Logger::report_failure( 'Invalid fields schema for audio player "' . $slug . '".' );
			return array();
		}
		$valid = array();
		foreach ( $fields as $key => $field ) {
			if ( ! is_string( $key ) || '' === $key || sanitize_key( $key ) !== $key || ! is_array( $field ) ) {
				MDS_RM_Logger::report_failure( 'Invalid field key in audio player "' . $slug . '".' );
				continue;
			}
			$type  = isset( $field['type'] ) && is_string( $field['type'] ) ? $field['type'] : '';
			$label = isset( $field['label'] ) && is_string( $field['label'] ) ? trim( $field['label'] ) : '';
			if ( ! in_array( $type, self::FIELD_TYPES, true ) || '' === $label ) {
				MDS_RM_Logger::report_failure( 'Invalid field type or label for "' . $slug . '.' . $key . '".' );
				continue;
			}
			$entry = array(
				'type'  => $type,
				'label' => $label,
				'help'  => isset( $field['help'] ) && is_string( $field['help'] ) ? trim( $field['help'] ) : '',
			);
			if ( 'select' === $type ) {
				if ( empty( $field['options'] ) || ! is_array( $field['options'] ) ) {
					MDS_RM_Logger::report_failure( 'Select field "' . $slug . '.' . $key . '" requires an options array.' );
					continue;
				}
				$options = array();
				foreach ( $field['options'] as $opt_val => $opt_label ) {
					if ( is_array( $opt_label ) && isset( $opt_label['value'], $opt_label['label'] ) ) {
						$options[ (string) $opt_label['value'] ] = (string) $opt_label['label'];
					} elseif ( is_scalar( $opt_val ) && is_scalar( $opt_label ) ) {
						$options[ (string) $opt_val ] = (string) $opt_label;
					}
				}
				if ( ! $options ) {
					continue;
				}
				$entry['options'] = $options;
			}
			if ( in_array( $type, array( 'range', 'number' ), true ) ) {
				if ( isset( $field['min'] ) && is_numeric( $field['min'] ) ) {
					$entry['min'] = $field['min'] + 0;
				}
				if ( isset( $field['max'] ) && is_numeric( $field['max'] ) ) {
					$entry['max'] = $field['max'] + 0;
				}
				if ( isset( $field['step'] ) && is_numeric( $field['step'] ) && $field['step'] > 0 ) {
					$entry['step'] = $field['step'] + 0;
				}
				if ( isset( $field['unit'] ) && is_string( $field['unit'] ) ) {
					$entry['unit'] = $field['unit'];
				}
			}
			if ( array_key_exists( 'default', $field ) ) {
				$default = self::validate_field_value( $entry, $field['default'] );
				if ( ! is_wp_error( $default ) ) {
					$entry['default'] = $default;
				}
			}
			$valid[ $key ] = $entry;
		}
		return $valid;
	}

	/**
	 * Validates one setting value against its field schema.
	 *
	 * @param array<string, mixed> $field Field schema.
	 * @param mixed                $value Submitted value.
	 * @return mixed|WP_Error Sanitized value or error.
	 */
	private static function validate_field_value( array $field, mixed $value ): mixed {
		$type = $field['type'];
		switch ( $type ) {
			case 'toggle':
				if ( ! is_bool( $value ) ) {
					return self::invalid_settings_error();
				}
				return $value;

			case 'select':
				if ( ! is_scalar( $value ) ) {
					return self::invalid_settings_error();
				}
				$str = (string) $value;
				if ( ! array_key_exists( $str, $field['options'] ) ) {
					return self::invalid_settings_error();
				}
				return $str;

			case 'color':
				if ( ! is_string( $value ) ) {
					return self::invalid_settings_error();
				}
				$clean = MDS_RM_Release_Links_Block::style_value( trim( $value ), 'color' );
				if ( '' === $clean ) {
					return self::invalid_settings_error();
				}
				return $clean;

			case 'range':
			case 'number':
				if ( is_string( $value ) && ! empty( $field['unit'] ) && str_ends_with( $value, (string) $field['unit'] ) ) {
					$value = substr( $value, 0, -strlen( (string) $field['unit'] ) );
				}
				if ( ! is_numeric( $value ) ) {
					return self::invalid_settings_error();
				}
				$num = $value + 0;
				if ( isset( $field['min'] ) && $num < $field['min'] ) {
					return self::invalid_settings_error();
				}
				if ( isset( $field['max'] ) && $num > $field['max'] ) {
					return self::invalid_settings_error();
				}
				return $num;

			case 'text':
				if ( ! is_string( $value ) || strlen( $value ) > 500 || sanitize_text_field( $value ) !== $value ) {
					return self::invalid_settings_error();
				}
				return $value;
		}

		return self::invalid_settings_error();
	}

	/**
	 * Checks whether an icon class string is a valid Dashicon or Font Awesome class.
	 *
	 * @param string $icon Icon class string.
	 * @return bool True when valid.
	 */
	private static function valid_icon( string $icon ): bool {
		return 1 === preg_match( '/^(?:dashicons dashicons-[a-z0-9-]+|(?:fa[srbl]|fa-(?:solid|regular|brands|light)) fa-[a-z0-9-]+)$/D', $icon );
	}

	/**
	 * Builds the validation error for invalid audio player settings.
	 *
	 * @return WP_Error Validation error.
	 */
	private static function invalid_settings_error(): WP_Error {
		return new WP_Error(
			'mds_rm_invalid_audio_player_settings',
			__( 'The audio player settings are invalid.', 'mds-release-manager' ),
			array( 'status' => 400 )
		);
	}
}

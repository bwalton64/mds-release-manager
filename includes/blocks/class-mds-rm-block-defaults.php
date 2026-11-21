<?php
/**
 * Site-wide defaults for the Release Manager blocks.
 *
 * @package MDS_Release_Manager
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves site defaults without changing the saved block markup.
 */
final class MDS_RM_Block_Defaults {
	/** Registers the block-render filter. */
	public static function register_hooks(): void {
		add_filter( 'render_block_data', array( self::class, 'apply_to_block' ) );
	}

	/**
	 * Gets the site defaults for one block.
	 *
	 * @param string $slug Block slug.
	 * @return array Defaults.
	 */
	public static function get( string $slug ): array {
		$all = get_option( 'mds_rm_block_defaults', array() );
		return is_array( $all ) && isset( $all[ $slug ] ) && is_array( $all[ $slug ] ) ? $all[ $slug ] : array();
	}

	/**
	 * Validates defaults submitted by the settings screen.
	 *
	 * @param mixed $all Submitted defaults.
	 * @return true|WP_Error True when valid, otherwise an error.
	 */
	public static function validate( $all ) {
		if ( ! is_array( $all ) ) {
			return self::invalid();
		}
		$keys = array(
			'release-meta'       => array( 'metaStyles', 'items' ),
			'release-links'      => array( 'linkStyles', 'items', 'newTab', 'showAudio' ),
			'artist-discography' => array( 'tableStyles', 'style' ),
		);
		foreach ( $all as $slug => $settings ) {
			if ( ! isset( $keys[ $slug ] ) || ! is_array( $settings ) ) {
				return self::invalid();
			}
			foreach ( $settings as $key => $value ) {
				if ( ! in_array( $key, $keys[ $slug ], true ) ) {
					return self::invalid();
				}
				if ( in_array( $key, array( 'newTab', 'showAudio' ), true ) ) {
					if ( ! is_bool( $value ) ) {
						return self::invalid();
					}
					continue;
				}
				if ( ! is_array( $value ) ) {
					return self::invalid();
				}
				if ( 'style' === $key ) {
					$schema = array(
						'spacing'    => array( 'margin' => 'spacing', 'padding' => 'spacing' ),
						'color'      => array( 'background' => 'color', 'text' => 'color' ),
						'elements'   => array( 'link' => array( 'color' => array( 'text' => 'color' ) ) ),
						'typography' => array( 'fontSize' => 'font_size', 'fontFamily' => 'font', 'fontWeight' => 'weight', 'fontStyle' => 'font_style', 'lineHeight' => 'line_height', 'letterSpacing' => 'length' ),
						'border'     => array( 'color' => 'color', 'width' => 'length', 'radius' => 'length', 'style' => 'border_style' ),
					);
					if ( ! self::valid_wrapper( $value, $schema ) ) {
						return self::invalid();
					}
					continue;
				}
				if ( 'items' === $key ) {
					$id_key = 'release-meta' === $slug ? 'field' : 'name';
					$seen   = array();
					foreach ( $value as $item ) {
						if ( ! is_array( $item ) || ! isset( $item[ $id_key ] ) || ! is_string( $item[ $id_key ] )
							|| '' === trim( $item[ $id_key ] ) || strlen( $item[ $id_key ] ) > 200
							|| sanitize_text_field( $item[ $id_key ] ) !== $item[ $id_key ]
							|| isset( $seen[ strtolower( $item[ $id_key ] ) ] )
							|| ( 'field' === $id_key && ! isset( MDS_RM_Release_Meta::fields()[ $item[ $id_key ] ] ) ) ) {
							return self::invalid();
						}
						$seen[ strtolower( $item[ $id_key ] ) ] = true;
						foreach ( $item as $property => $setting ) {
							if ( $id_key === $property ) {
								continue;
							}
							if ( 'enabled' === $property && is_bool( $setting ) ) {
								continue;
							}
							if ( in_array( $property, array( 'icon', 'title' ), true ) && is_string( $setting )
								&& strlen( $setting ) <= 200 && sanitize_text_field( $setting ) === $setting ) {
								continue;
							}
							return self::invalid();
						}
					}
					continue;
				}
				$fields = 'metaStyles' === $key ? MDS_RM_Release_Meta::style_fields() : MDS_RM_Release_Links::style_fields();
				if ( 'metaStyles' === $key ) {
					$fields['font_size']['type']      = 'font_size';
					$fields['font_style']['type']     = 'font_style';
					$fields['text_align']['type']     = 'text_align';
					$fields['letter_spacing']['type'] = 'length';
				}
				foreach ( $value as $field => $setting ) {
					if ( 'tableStyles' === $key ) {
						if ( 'striped' === $field && is_bool( $setting ) ) {
							continue;
						}
						if ( is_string( $field ) && is_string( $setting ) && strlen( $setting ) <= 200
							&& false !== strpos( MDS_RM_Discography::table_style_variables( array( $field => $setting, 'striped' => true ) ), '--mds-discography-' . $field . ':' ) ) {
							continue;
						}
						return self::invalid();
					}
					if ( ! isset( $fields[ $field ] ) ) {
						return self::invalid();
					}
					$type = $fields[ $field ]['type'];
					if ( 'spacing' === $type && is_array( $setting ) ) {
						foreach ( $setting as $side => $length ) {
							if ( ! in_array( $side, array( 'top', 'right', 'bottom', 'left' ), true )
								|| ! is_string( $length ) || '' === MDS_RM_Release_Links_Block::style_value( $length, 'length' ) ) {
								return self::invalid();
							}
						}
						continue;
					}
					if ( ! is_string( $setting ) || strlen( $setting ) > 200 || '' === MDS_RM_Release_Links_Block::style_value( $setting, $type ) ) {
						return self::invalid();
					}
				}
			}
		}
		return true;
	}

	/**
	 * Returns a validation error suitable for the settings REST endpoint.
	 *
	 * @return WP_Error Validation error.
	 */
	private static function invalid(): WP_Error {
		return new WP_Error( 'mds_rm_invalid_block_defaults', __( 'The block defaults contain an unsupported value. Review the settings and try again.', 'mds-release-manager' ), array( 'status' => 400 ) );
	}

	/**
	 * Validates the subset of native block-support styles used by the UI.
	 *
	 * @param array $values Submitted style values.
	 * @param array $schema Allowed keys and value types.
	 * @return bool Whether all values are valid.
	 */
	private static function valid_wrapper( array $values, array $schema ): bool {
		foreach ( $values as $key => $value ) {
			if ( ! isset( $schema[ $key ] ) ) {
				return false;
			}
			$type = $schema[ $key ];
			if ( is_array( $type ) ) {
				if ( ! is_array( $value ) || ! self::valid_wrapper( $value, $type ) ) {
					return false;
				}
			} elseif ( 'spacing' === $type && is_array( $value ) ) {
				foreach ( $value as $side => $length ) {
					if ( ! in_array( $side, array( 'top', 'right', 'bottom', 'left' ), true )
						|| ! is_string( $length ) || '' === MDS_RM_Release_Links_Block::style_value( $length, 'length' ) ) {
						return false;
					}
				}
			} elseif ( ! is_string( $value ) || strlen( $value ) > 200 || '' === MDS_RM_Release_Links_Block::style_value( $value, $type ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Resolves defaults only for the three dynamic blocks.
	 *
	 * @param array $block Parsed block.
	 * @return array Block with effective attributes.
	 */
	public static function apply_to_block( array $block ): array {
		$names = array(
			'mds-rm/release-meta'       => 'release-meta',
			'mds-rm/release-links'      => 'release-links',
			'mds-rm/artist-discography' => 'artist-discography',
		);
		$name = $block['blockName'] ?? '';
		if ( ! isset( $names[ $name ] ) ) {
			return $block;
		}
		$block['attrs'] = self::resolve( $names[ $name ], is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array() );
		return $block;
	}

	/**
	 * Merges site defaults with explicitly saved block attributes.
	 *
	 * @param string $slug       Block slug.
	 * @param array  $attributes Saved attributes.
	 * @return array Effective attributes.
	 */
	public static function resolve( string $slug, array $attributes ): array {
		$defaults = self::get( $slug );
		$style_key = array(
			'release-meta'       => 'metaStyles',
			'release-links'      => 'linkStyles',
			'artist-discography' => 'tableStyles',
		)[ $slug ] ?? '';
		if ( '' === $style_key ) {
			return $attributes;
		}

		$site_styles = is_array( $defaults[ $style_key ] ?? null ) ? $defaults[ $style_key ] : array();
		$own_styles  = is_array( $attributes[ $style_key ] ?? null ) ? $attributes[ $style_key ] : array();
		if ( is_array( $attributes['styles']['desktop'] ?? null ) ) {
			foreach ( $attributes['styles']['desktop'] as $key => $_value ) {
				if ( ! array_key_exists( $key, $own_styles ) ) {
					unset( $site_styles[ $key ] );
				}
			}
		}
		foreach ( $own_styles as $key => $value ) {
			if ( is_array( $value ) && isset( $site_styles[ $key ] ) && is_string( $site_styles[ $key ] ) ) {
				$site_styles[ $key ] = array_fill_keys( array( 'top', 'right', 'bottom', 'left' ), $site_styles[ $key ] );
			}
		}
		$styles = array_replace_recursive( $site_styles, $own_styles );
		if ( $styles ) {
			$attributes[ $style_key ] = $styles;
		}

		if ( 'artist-discography' === $slug ) {
			$site_wrapper = is_array( $defaults['style'] ?? null ) ? $defaults['style'] : array();
			$own_wrapper  = is_array( $attributes['style'] ?? null ) ? $attributes['style'] : array();
			if ( ! empty( $attributes['textColor'] ) ) {
				unset( $site_wrapper['color']['text'] );
			}
			if ( ! empty( $attributes['backgroundColor'] ) ) {
				unset( $site_wrapper['color']['background'] );
			}
			if ( ! empty( $attributes['fontSize'] ) ) {
				unset( $site_wrapper['typography']['fontSize'] );
			}
			if ( ! empty( $attributes['fontFamily'] ) ) {
				unset( $site_wrapper['typography']['fontFamily'] );
			}
			foreach ( array( 'margin', 'padding' ) as $spacing ) {
				if ( is_array( $own_wrapper['spacing'][ $spacing ] ?? null ) && is_string( $site_wrapper['spacing'][ $spacing ] ?? null ) ) {
					$site_wrapper['spacing'][ $spacing ] = array_fill_keys( array( 'top', 'right', 'bottom', 'left' ), $site_wrapper['spacing'][ $spacing ] );
				}
			}
			$wrapper      = array_replace_recursive( $site_wrapper, $own_wrapper );
			if ( $wrapper ) {
				$attributes['style'] = $wrapper;
			}
			return $attributes;
		}

		if ( 'release-links' === $slug ) {
			foreach ( array( 'newTab', 'showAudio' ) as $key ) {
				if ( ! array_key_exists( $key, $attributes ) && isset( $defaults[ $key ] ) && is_bool( $defaults[ $key ] ) ) {
					$attributes[ $key ] = $defaults[ $key ];
				}
			}
		}

		// Old saved lists are complete overrides. New edits may override fields individually.
		if ( ! array_key_exists( 'items', $attributes ) ) {
			$items = is_array( $defaults['items'] ?? null ) ? $defaults['items'] : ( 'release-meta' === $slug ? MDS_RM_Release_Meta::default_items() : array() );
			$key   = 'release-meta' === $slug ? 'field' : 'name';
			$patch = is_array( $attributes['itemOverrides'] ?? null ) ? $attributes['itemOverrides'] : array();
			foreach ( $items as &$item ) {
				$id = is_array( $item ) && isset( $item[ $key ] ) && is_string( $item[ $key ] ) ? $item[ $key ] : '';
				if ( '' !== $id && isset( $patch[ $id ] ) && is_array( $patch[ $id ] ) ) {
					$item = array_replace( $item, $patch[ $id ] );
				}
			}
			unset( $item );
			foreach ( $patch as $id => $changes ) {
				if ( ! is_string( $id ) || ! is_array( $changes ) || in_array( $id, array_column( $items, $key ), true ) ) {
					continue;
				}
				if ( 'release-links' === $slug ) {
					$items[] = array_merge( array( $key => $id ), $changes );
				}
			}
			if ( is_array( $attributes['itemOrder'] ?? null ) ) {
				$ordered = array();
				foreach ( $attributes['itemOrder'] as $id ) {
					$found = false;
					foreach ( $items as $index => $item ) {
						if ( is_string( $id ) && ( $item[ $key ] ?? null ) === $id ) {
							$ordered[] = $item;
							unset( $items[ $index ] );
							$found = true;
							break;
						}
					}
					if ( ! $found && 'release-links' === $slug && is_string( $id ) && '' !== $id ) {
						$ordered[] = array( 'name' => $id, 'enabled' => true );
					}
				}
				$items = array_merge( $ordered, array_values( $items ) );
			}
			$attributes['items'] = $items;
		}
		return $attributes;
	}
}

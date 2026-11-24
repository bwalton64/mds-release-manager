<?php
/**
 * Shared rendering and configuration for the Release Meta Block.
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Blocks;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers and renders release metadata for blocks and integrations. */
final class ReleaseMeta {
	/**
	 * Lists the supported metadata fields.
	 *
	 * @return array Field definitions keyed by field name.
	 */
	public static function fields(): array {
		return array(
			'release_year'         => array(
				'label'   => 'Release Year',
				'icon'    => 'fas fa-calendar',
				'enabled' => true,
			),
			'record_label'         => array(
				'label'   => 'Record Label',
				'icon'    => 'fas fa-compact-disc',
				'enabled' => true,
			),
			'distributor'          => array(
				'label'   => 'Distributor',
				'icon'    => 'fas fa-tower-broadcast',
				'enabled' => true,
			),
			'upc_ean'              => array(
				'label'   => 'UPC/EAN',
				'icon'    => 'fas fa-barcode',
				'enabled' => true,
			),
			'release_type'         => array(
				'label'   => 'Release Type',
				'icon'    => 'dashicons dashicons-album',
				'enabled' => false,
			),
			'release_media_format' => array(
				'label'   => 'Media Formats',
				'icon'    => 'fas fa-record-vinyl',
				'enabled' => false,
			),
			'release_date'         => array(
				'label'   => 'Full Release Date',
				'icon'    => 'fas fa-calendar',
				'enabled' => false,
			),
			'release_title'        => array(
				'label'   => 'Release Title',
				'icon'    => 'dashicons dashicons-album',
				'enabled' => false,
			),
			'artist'               => array(
				'label'   => 'Artist',
				'icon'    => 'dashicons dashicons-groups',
				'enabled' => false,
			),
			'release_tags'         => array(
				'label'   => 'Release Tags',
				'icon'    => 'dashicons dashicons-tag',
				'enabled' => false,
			),
		);
	}

	/**
	 * Builds the default ordered list of metadata items.
	 *
	 * @return array Default renderer options for each field.
	 */
	public static function default_items(): array {
		$items = array();
		foreach ( self::fields() as $key => $field ) {
			$items[] = array(
				'field'   => $key,
				'enabled' => $field['enabled'],
				'icon'    => $field['icon'],
				'title'   => $field['label'],
			);
		}
		return $items;
	}

	/**
	 * Shared style names and input types for all three interfaces.
	 */
	public static function style_fields(): array {
		return array(
			'background'     => array(
				'label' => 'Background Color',
				'type'  => 'color',
			),
			'color'          => array(
				'label' => 'Text Color',
				'type'  => 'color',
			),
			'icon_color'     => array(
				'label' => 'Icon Color',
				'type'  => 'color',
			),
			'font_family'    => array(
				'label' => 'Font Family',
				'type'  => 'font',
			),
			'font_size'      => array(
				'label' => 'Font Size',
				'type'  => 'length',
			),
			'font_weight'    => array(
				'label' => 'Font Weight',
				'type'  => 'weight',
			),
			'line_height'    => array(
				'label' => 'Line Height',
				'type'  => 'line_height',
			),
			'icon_size'      => array(
				'label' => 'Icon Size',
				'type'  => 'length',
			),
			'icon_opacity'   => array(
				'label' => 'Icon Opacity',
				'type'  => 'opacity',
			),
			'icon_gap'       => array(
				'label' => 'Icon-to-text Gap',
				'type'  => 'length',
			),
			'gap'            => array(
				'label' => 'Item Gap',
				'type'  => 'length',
			),
			'align'          => array(
				'label' => 'Main-axis Alignment',
				'type'  => 'align',
			),
			'vertical_align' => array(
				'label' => 'Cross-axis Alignment',
				'type'  => 'vertical_align',
			),
			'direction'      => array(
				'label' => 'Direction',
				'type'  => 'direction',
			),
			'wrap'           => array(
				'label' => 'Wrapping',
				'type'  => 'wrap',
			),
			'width'          => array(
				'label' => 'Width',
				'type'  => 'width',
			),
			'padding'        => array(
				'label' => 'Padding',
				'type'  => 'spacing',
			),
			'margin'         => array(
				'label' => 'Margin',
				'type'  => 'spacing',
			),
			'border_width'   => array(
				'label' => 'Border Width',
				'type'  => 'spacing',
			),
			'border_style'   => array(
				'label' => 'Border Style',
				'type'  => 'border_style',
			),
			'border_color'   => array(
				'label' => 'Border Color',
				'type'  => 'color',
			),
			'radius'         => array(
				'label' => 'Border Radius',
				'type'  => 'spacing',
			),
		);
	}

	/**
	 * Adds native Release Meta typography controls supported by the shortcode.
	 *
	 * @return array<string, array{label: string, type: string}> Shortcode style fields.
	 */
	private static function shortcode_style_fields(): array {
		$fields                      = self::style_fields();
		$fields['font_size']['type'] = 'font_size';
		$fields['font_style']        = array(
			'label' => 'Font Style',
			'type'  => 'font_style',
		);
		$fields['text_align']        = array(
			'label' => 'Text Alignment',
			'type'  => 'text_align',
		);
		$fields['letter_spacing']    = array(
			'label' => 'Letter Spacing',
			'type'  => 'length',
		);
		return $fields;
	}

	/** Registers the frontend Release Meta stylesheet. */
	public static function register_assets(): void {
		wp_register_style( 'mds-release-meta', MDS_RM_URL . 'css/release-meta.css', array( 'dashicons' ), filemtime( MDS_RM_PATH . 'css/release-meta.css' ) );
	}

	/** Enqueues the frontend Release Meta stylesheet. */
	public static function enqueue_assets(): void {
		if ( ! wp_style_is( 'mds-release-meta', 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( 'mds-release-meta' );
		\MDS\ReleaseManager\Frontend\Assets::enqueue_font_awesome();
	}

	/**
	 * Resolve explicit UPCs first; extensions may provide a UPC for other contexts.
	 *
	 * @param mixed      $upc             Explicit UPC/EAN, if supplied.
	 * @param int|string $context_post_id Post ID to use when no UPC is supplied.
	 * @return WP_Post|null The readable release, if found.
	 */
	public static function resolve_post( $upc = '', $context_post_id = 0 ): ?WP_Post {
		if ( ! is_scalar( $upc ) ) {
			\MDS\ReleaseManager\Core\Logger::diagnostic( 'Invalid Release Meta UPC/EAN.' );
			return null;
		}
		$upc             = (string) $upc;
		$context_post_id = absint( $context_post_id );
		if ( '' === $upc && $context_post_id && 'release' === get_post_type( $context_post_id ) ) {
			$post = get_post( $context_post_id );
			return self::can_display( $post ) ? $post : null;
		}
		if ( '' === $upc ) {
			$upc = apply_filters( 'mds_rm_release_meta_context_upc', '', $context_post_id );
		}
		if ( ! is_scalar( $upc ) ) {
			\MDS\ReleaseManager\Core\Logger::diagnostic( 'Invalid Release Meta context UPC/EAN.' );
			return null;
		}
		$upc = (string) $upc;
		if ( '' === $upc ) {
			return null;
		}
		if ( ! preg_match( '/^\d+$/D', $upc ) ) {
			\MDS\ReleaseManager\Core\Logger::diagnostic( 'Invalid Release Meta UPC/EAN.' );
			return null;
		}
		$posts = get_posts(
			array(
				'post_type'      => 'release',
				'post_status'    => 'any',
				'posts_per_page' => 2,
				'meta_key'       => 'upc_ean', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Resolve an explicit UPC and detect ambiguous matches.
				'meta_value'     => (string) $upc, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		if ( count( $posts ) > 1 ) {
			\MDS\ReleaseManager\Core\Logger::report_failure( 'Cannot render metadata: duplicate UPC/EAN ' . $upc );
			return null;
		}
		return isset( $posts[0] ) && self::can_display( $posts[0] ) ? $posts[0] : null;
	}

	/**
	 * Resolves a shortcode's explicit Release ID, UPC, or current context.
	 *
	 * @param mixed      $release_id Explicit Release post ID.
	 * @param mixed      $upc        Explicit UPC/EAN.
	 * @param int|string $context_id Current post context.
	 * @return WP_Post|null The readable Release, if found.
	 */
	public static function resolve_shortcode_source( $release_id, $upc, $context_id = 0 ): ?WP_Post {
		if ( is_scalar( $release_id ) && '' !== (string) $release_id && '0' !== (string) $release_id ) {
			$id   = (string) $release_id;
			$post = preg_match( '/\A[1-9][0-9]*\z/D', $id ) ? get_post( (int) $id ) : null;
			if ( self::can_display( $post ) ) {
				return $post;
			}
			\MDS\ReleaseManager\Core\Logger::diagnostic( 'Invalid Release shortcode release_id.' );
		} elseif ( ! is_scalar( $release_id ) ) {
			\MDS\ReleaseManager\Core\Logger::diagnostic( 'Invalid Release shortcode release_id.' );
		}

		if ( is_scalar( $upc ) && '' !== (string) $upc ) {
			return self::resolve_post( $upc );
		}
		if ( ! is_scalar( $upc ) ) {
			return self::resolve_post( $upc );
		}
		return self::resolve_post( '', $context_id );
	}

	/**
	 * Canonical public Release resolver for core and extension blocks, shortcodes, and players.
	 *
	 * Resolves a Release post by explicit `releaseId`/`release_id`, explicit `upcOverride`/`upc`,
	 * or contextual post ID (including Artist latest-release context and WooCommerce SKU context).
	 *
	 * @since 1.1.0
	 * @param array          $attributes      Block or shortcode attributes.
	 * @param \WP_Block|null $block           Optional Gutenberg block context.
	 * @param int|string     $context_post_id Fallback context post ID.
	 * @return WP_Post|null The readable Release, if found.
	 */
	public static function resolve_release( array $attributes = array(), ?\WP_Block $block = null, int|string $context_post_id = 0 ): ?WP_Post {
		if ( $block instanceof \WP_Block ) {
			return ReleaseLinksBlock::resolve_block_source( $attributes, $block );
		}
		$release_id = $attributes['release_id'] ?? ( $attributes['releaseId'] ?? 0 );
		$upc        = $attributes['upc'] ?? ( $attributes['upcOverride'] ?? '' );
		$context_id = $context_post_id ? $context_post_id : get_the_ID();
		return self::resolve_shortcode_source( $release_id, $upc, $context_id );
	}

	/**
	 * Registers an extension metadata key on the `release` or `artist` custom post type.
	 *
	 * Ensures consistent REST API / Block Bindings exposure, single-value storage,
	 * and edit-capability checks across extension plugins.
	 *
	 * @since 1.1.0
	 * @param string               $meta_key  Post meta key to register.
	 * @param array<string, mixed> $args      Optional `register_post_meta` overrides.
	 * @param string               $post_type Target CPT (`release` or `artist`). Default `release`.
	 * @return bool True if the meta key was successfully registered.
	 */
	public static function register_extension_meta( string $meta_key, array $args = array(), string $post_type = 'release' ): bool {
		if ( '' === $meta_key || sanitize_key( $meta_key ) !== $meta_key || ! in_array( $post_type, array( 'release', 'artist' ), true ) || ! function_exists( 'register_post_meta' ) ) {
			return false;
		}
		$defaults = array(
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'sanitize_text_field',
			'auth_callback'     => static function ( bool $_allowed, string $_key, int $post_id ): bool {
				return current_user_can( 'edit_post', $post_id );
			},
		);
		return register_post_meta( $post_type, $meta_key, array_merge( $defaults, $args ) );
	}

	/**
	 * Checks whether a candidate is a readable release.
	 *
	 * @param mixed $post Candidate from a WordPress post lookup.
	 * @return bool Whether the release can be displayed.
	 */
	private static function can_display( mixed $post ): bool {
		return $post instanceof WP_Post && 'release' === $post->post_type
			&& ( 'publish' === $post->post_status || current_user_can( 'read_post', $post->ID ) );
	}

	/**
	 * Reads one supported metadata field.
	 *
	 * @param string  $field Metadata field name.
	 * @param WP_Post $post  The release.
	 * @return mixed The field value before display validation.
	 */
	private static function value( $field, $post ) {
		switch ( $field ) {
			case 'release_year':
				return '0000-00-00 00:00:00' !== $post->post_date ? substr( $post->post_date, 0, 4 ) : '';
			case 'release_date':
				return '0000-00-00 00:00:00' !== $post->post_date ? mysql2date( get_option( 'date_format' ), $post->post_date ) : '';
			case 'release_title':
				return get_the_title( $post );
			case 'release_type':
			case 'release_format':
				$value = \MDS\ReleaseManager\Core\ReleaseModel::format_name( $post->ID );
				if ( is_wp_error( $value ) ) {
					\MDS\ReleaseManager\Core\Logger::report_failure( 'Release ' . $post->ID . ': ' . $value->get_error_message() );
					return '';
				}
				return $value;
			case 'release_media_format':
				$terms = wp_get_post_terms( $post->ID, 'release_media_format', array( 'fields' => 'names' ) );
				if ( is_wp_error( $terms ) ) {
					\MDS\ReleaseManager\Core\Logger::report_failure( 'Release ' . $post->ID . ': ' . $terms->get_error_message() );
					return '';
				}
				return $terms ? implode( ', ', $terms ) : '';
			case 'release_tags':
				$terms = wp_get_post_terms( $post->ID, 'release_tag', array( 'fields' => 'names' ) );
				if ( is_wp_error( $terms ) ) {
					\MDS\ReleaseManager\Core\Logger::report_failure( 'Release ' . $post->ID . ': ' . $terms->get_error_message() );
					return '';
				}
				return $terms ? implode( ', ', $terms ) : get_post_meta( $post->ID, 'release_tags', true );
			case 'artist':
				$uuid = get_post_meta( $post->ID, 'artist_id', true );
				if ( ! $uuid ) {
					return '';
				}
				$artists = get_posts(
					array(
						'post_type'      => 'artist',
						'post_status'    => 'publish',
						'posts_per_page' => 1,
						'meta_key'       => '_mds_artist_uuid', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Releases store the Artist UUID, not a local Artist post ID.
						'meta_value'     => $uuid, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					)
				);
				return $artists ? get_the_title( $artists[0] ) : '';
			default:
				return get_post_meta( $post->ID, $field, true );
		}
	}

	/**
	 * Validates a CSS value against the configured control type.
	 *
	 * @param mixed  $value Submitted style value.
	 * @param string $type  Style control type.
	 * @return string The validated value, or an empty string.
	 */
	public static function valid_style( mixed $value, $type ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$length  = '(?:0|-?(?:\d+(?:\.\d+)?|\.\d+)(?:px|em|rem|%|vw|vh))';
		$presets = array(
			'color'   => 'color',
			'font'    => 'font-family',
			'length'  => 'spacing',
			'width'   => 'spacing',
			'spacing' => 'spacing',
		);
		if ( isset( $presets[ $type ] ) && preg_match( '/\Avar\(--wp--preset--' . preg_quote( $presets[ $type ], '/' ) . '--[a-z0-9_-]+\)\z/i', $value ) ) {
			return $value;
		}
		if ( 'font_size' === $type && preg_match( '/\Avar\(--wp--preset--font-size--[a-z0-9_-]+\)\z/i', $value ) ) {
			return $value;
		}
		switch ( $type ) {
			case 'color':
				$color = sanitize_hex_color( $value );
				if ( preg_match( '/\A#[a-f0-9]{4}(?:[a-f0-9]{4})?\z/i', $value ) ) {
					return $value;
				}
				return $color ? $color : ( 'transparent' === $value || 'inherit' === $value || preg_match( '/\A(?:rgba?\([0-9.,% \/]+\))\z/i', $value ) ? $value : '' );
			case 'font':
				$family = '(?:[a-zA-Z0-9_-]+(?:\s+[a-zA-Z0-9_-]+)*|"[a-zA-Z0-9 _-]+"|\'[a-zA-Z0-9 _-]+\')';
				return preg_match( '/^' . $family . '(?:\s*,\s*' . $family . ')*$/D', $value ) ? $value : '';
			case 'font_style':
				return in_array( $value, array( 'normal', 'italic' ), true ) ? $value : '';
			case 'text_align':
				return in_array( $value, array( 'start', 'center', 'end' ), true ) ? $value : '';
			case 'length':
				return preg_match( '/^' . $length . '$/D', $value ) ? $value : '';
			case 'font_size':
				return preg_match( '/^' . $length . '$/D', $value ) ? $value : '';
			case 'width':
				return 'auto' === $value || preg_match( '/^' . $length . '$/D', $value ) ? $value : '';
			case 'spacing':
				return preg_match( '/^' . $length . '(?:\s+' . $length . '){0,3}$/D', $value ) ? $value : '';
			case 'weight':
				return preg_match( '/^(normal|bold|[1-9]00)$/D', $value ) ? $value : '';
			case 'line_height':
				return preg_match( '/^(?:normal|\d+(?:\.\d+)?|' . $length . ')$/D', $value ) ? $value : '';
			case 'opacity':
				return is_numeric( $value ) && (float) $value >= 0 && (float) $value <= 1 ? $value : '';
			case 'align':
				return in_array( $value, array( 'flex-start', 'center', 'flex-end', 'space-between' ), true ) ? $value : '';
			case 'vertical_align':
				return in_array( $value, array( 'flex-start', 'center', 'flex-end', 'stretch' ), true ) ? $value : '';
			case 'direction':
				return in_array( $value, array( 'row', 'row-reverse', 'column', 'column-reverse' ), true ) ? $value : '';
			case 'wrap':
				return in_array( $value, array( 'wrap', 'nowrap', 'wrap-reverse' ), true ) ? $value : '';
			case 'border_style':
				return in_array( $value, array( 'none', 'solid', 'dashed', 'dotted', 'double' ), true ) ? $value : '';
		}
		return '';
	}

	/**
	 * Builds responsive CSS custom-property declarations.
	 *
	 * @param mixed      $styles Responsive style values.
	 * @param array|null $fields Optional supported style field definitions.
	 * @param string     $prefix CSS custom-property namespace.
	 * @return string CSS declarations.
	 */
	public static function style_attribute( $styles, $fields = null, $prefix = 'meta' ): string {
		if ( ! is_array( $styles ) ) {
			return '';
		}
		$fields       = null === $fields ? self::style_fields() : $fields;
		$declarations = array();
		foreach ( array( 'desktop', 'tablet', 'mobile' ) as $device ) {
			if ( empty( $styles[ $device ] ) || ! is_array( $styles[ $device ] ) ) {
				continue;
			}
			foreach ( $fields as $name => $definition ) {
				if ( ! array_key_exists( $name, $styles[ $device ] ) ) {
					continue;
				}
				$value = self::valid_style( $styles[ $device ][ $name ], $definition['type'] );
				if ( '' !== $value ) {
					$declarations[] = '--mds-' . $prefix . '-' . str_replace( '_', '-', $name ) . '-' . $device . ':' . $value;
				} elseif ( ! is_scalar( $styles[ $device ][ $name ] ) || '' !== (string) $styles[ $device ][ $name ] ) {
					\MDS\ReleaseManager\Core\Logger::diagnostic( 'Invalid Release ' . ( 'links' === $prefix ? 'Links' : 'Meta' ) . ' style: ' . $name . ' (' . $device . ')' );
				}
			}
		}
		return implode( ';', $declarations );
	}

	/**
	 * Render one release. Empty values are omitted.
	 *
	 * @param array      $attributes      Field and style overrides.
	 * @param int|string $context_post_id Post ID to use when no UPC is supplied.
	 * @return string The release metadata markup, or an empty string.
	 */
	public static function render( $attributes = array(), $context_post_id = 0 ) {
		$upc        = isset( $attributes['upc'] ) ? $attributes['upc'] : '';
		$release_id = isset( $attributes['release_id'] ) ? $attributes['release_id'] : ( isset( $attributes['releaseId'] ) ? $attributes['releaseId'] : 0 );
		$post       = self::resolve_shortcode_source( $release_id, $upc, $context_post_id );
		return $post ? self::render_post( $post, $attributes ) : '';
	}

	/**
	 * Renders fields for an already resolved, readable Release.
	 *
	 * @param WP_Post $post       Resolved Release.
	 * @param array   $attributes Field and style overrides.
	 * @param array   $style_fields Optional style definitions.
	 * @return string Rendered metadata.
	 */
	public static function render_post( WP_Post $post, array $attributes, $style_fields = null ): string {
		self::enqueue_assets();
		$fields = self::fields();
		$items  = isset( $attributes['items'] ) && is_array( $attributes['items'] ) ? $attributes['items'] : self::default_items();
		$output = '';
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['field'] ) || ! is_string( $item['field'] ) || ! isset( $fields[ $item['field'] ] ) ) {
				\MDS\ReleaseManager\Core\Logger::diagnostic( 'Invalid Release Meta field.' );
				continue;
			}
			if ( empty( $item['enabled'] ) ) {
				continue;
			}
			$key   = $item['field'];
			$value = self::value( $key, $post );
			if ( ! is_scalar( $value ) || '' === (string) $value ) {
				continue;
			}
			$title = isset( $item['title'] ) && is_string( $item['title'] ) ? $item['title'] : $fields[ $key ]['label'];
			$icon  = isset( $item['icon'] ) && is_string( $item['icon'] ) ? trim( $item['icon'] ) : $fields[ $key ]['icon'];
			if ( in_array( $key, array( 'release_type', 'release_format' ), true ) && $icon === $fields[ $key ]['icon'] ) {
				$term_icon = \MDS\ReleaseManager\Core\ReleaseModel::get_release_type_icon_for_post( $post->ID );
				if ( '' !== $term_icon ) {
					$icon = $term_icon;
				}
			}
			if ( '' !== $icon && ! preg_match( '/^(?:dashicons dashicons-[a-z0-9-]+|(?:fa[srbl]|fa-(?:solid|regular|brands|light)) fa-[a-z0-9-]+)$/D', $icon ) ) {
				\MDS\ReleaseManager\Core\Logger::diagnostic( 'Invalid Release Meta icon for ' . $key );
				$icon = '';
			}
			$output .= '<span class="mds-release-meta__item mds-release-meta__item--' . esc_attr( $key ) . '"' . ( '' !== $title ? ' title="' . esc_attr( $title ) . '"' : '' ) . '>';
			if ( '' !== $icon ) {
				$output .= '<i class="mds-release-meta__icon ' . esc_attr( $icon ) . '" aria-hidden="true"></i>';
			}
			$output .= '<span class="screen-reader-text">' . esc_html( $fields[ $key ]['label'] ) . ': </span><span>' . esc_html( (string) $value ) . '</span></span>';
		}
		if ( '' === $output ) {
			return '';
		}
		$style = self::style_attribute( isset( $attributes['styles'] ) ? $attributes['styles'] : array(), $style_fields );
		return '<div class="mds-release-meta"' . ( $style ? ' style="' . esc_attr( $style ) . '"' : '' ) . '>' . $output . '</div>';
	}

	/**
	 * Converts Release Meta shortcode attributes to renderer options.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string The rendered release metadata.
	 */
	public static function shortcode( $atts ): string {
		$defaults = array(
			'upc'        => '',
			'release_id' => '',
			'fields'     => '',
		);
		foreach ( self::fields() as $key => $field ) {
			$defaults[ 'icon_' . $key ]  = $field['icon'];
			$defaults[ 'title_' . $key ] = $field['label'];
		}
		$style_fields = self::shortcode_style_fields();
		foreach ( $style_fields as $key => $_definition ) {
			foreach ( array( '', '_tablet', '_mobile' ) as $suffix ) {
				$defaults[ $key . $suffix ] = '';
			}
		}
		$atts     = shortcode_atts( $defaults, $atts, 'release-meta' );
		$selected = '' === trim( $atts['fields'] ) ? null : array_map( 'trim', explode( ',', $atts['fields'] ) );
		$items    = self::default_items();
		if ( null !== $selected ) {
			$by_key = array();
			foreach ( $items as $item ) {
				$by_key[ $item['field'] ] = $item;
			}
			$items = array();
			foreach ( $selected as $key ) {
				if ( isset( $by_key[ $key ] ) ) {
					$by_key[ $key ]['enabled'] = true;
					$items[]                   = $by_key[ $key ];
				} else {
					\MDS\ReleaseManager\Core\Logger::diagnostic( 'Unknown Release Meta field: ' . sanitize_text_field( $key ) );
				}
			}
		}
		foreach ( $items as &$item ) {
			$item['icon']  = $atts[ 'icon_' . $item['field'] ];
			$item['title'] = $atts[ 'title_' . $item['field'] ];
		}
		unset( $item );
		$styles = array();
		foreach ( $style_fields as $key => $_definition ) {
			foreach ( array(
				'desktop' => '',
				'tablet'  => '_tablet',
				'mobile'  => '_mobile',
			) as $device => $suffix ) {
				$styles[ $device ][ $key ] = $atts[ $key . $suffix ];
			}
		}
		$post   = self::resolve_shortcode_source( $atts['release_id'], $atts['upc'], get_the_ID() );
		$output = $post ? self::render_post(
			$post,
			array(
				'items'  => $items,
				'styles' => $styles,
			),
			$style_fields
		) : '';
		foreach ( array( 'font_style', 'text_align', 'letter_spacing' ) as $wrapper_style ) {
			if ( ! empty( $atts[ $wrapper_style ] ) || ! empty( $atts[ $wrapper_style . '_tablet' ] ) || ! empty( $atts[ $wrapper_style . '_mobile' ] ) ) {
				return $output ? '<div class="wp-block-mds-rm-release-meta">' . $output . '</div>' : '';
			}
		}
		return $output;
	}
}

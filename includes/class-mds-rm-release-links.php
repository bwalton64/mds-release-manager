<?php
/**
 * Shared rendering and configuration for the Release Links Block.
 *
 * @package MDS_Release_Manager
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared rendering and configuration for the Release Links Block.
 */
final class MDS_RM_Release_Links {
	/**
	 * Return the configurable style fields for the block.
	 *
	 * @return array<string, array{label: string, type: string}>
	 */
	public static function style_fields() {
		return array(
			'background'          => array(
				'label' => 'Background Color',
				'type'  => 'color',
			),
			'gap'                 => array(
				'label' => 'Gap',
				'type'  => 'length',
			),
			'align'               => array(
				'label' => 'Main-axis Alignment',
				'type'  => 'align',
			),
			'vertical_align'      => array(
				'label' => 'Cross-axis Alignment',
				'type'  => 'vertical_align',
			),
			'direction'           => array(
				'label' => 'Direction',
				'type'  => 'direction',
			),
			'wrap'                => array(
				'label' => 'Wrapping',
				'type'  => 'wrap',
			),
			'width'               => array(
				'label' => 'Width',
				'type'  => 'width',
			),
			'padding'             => array(
				'label' => 'Padding',
				'type'  => 'spacing',
			),
			'margin'              => array(
				'label' => 'Margin',
				'type'  => 'spacing',
			),
			'border_width'        => array(
				'label' => 'Border Width',
				'type'  => 'spacing',
			),
			'border_style'        => array(
				'label' => 'Border Style',
				'type'  => 'border_style',
			),
			'border_color'        => array(
				'label' => 'Border Color',
				'type'  => 'color',
			),
			'radius'              => array(
				'label' => 'Border Radius',
				'type'  => 'spacing',
			),
			'link_gap'            => array(
				'label' => 'Link Gap',
				'type'  => 'length',
			),
			'button_size'         => array(
				'label' => 'Link Button Size',
				'type'  => 'length',
			),
			'button_radius'       => array(
				'label' => 'Link Button Radius',
				'type'  => 'spacing',
			),
			'button_color'        => array(
				'label' => 'Link Icon Color',
				'type'  => 'color',
			),
			'button_background'   => array(
				'label' => 'Link Background Override',
				'type'  => 'color',
			),
			'button_border_width' => array(
				'label' => 'Link Border Width',
				'type'  => 'spacing',
			),
			'button_border_style' => array(
				'label' => 'Link Border Style',
				'type'  => 'border_style',
			),
			'button_border_color' => array(
				'label' => 'Link Border Color',
				'type'  => 'color',
			),
			'icon_size'           => array(
				'label' => 'Icon Size',
				'type'  => 'length',
			),
			'hover_opacity'       => array(
				'label' => 'Hover Opacity',
				'type'  => 'opacity',
			),
			'audio_width'         => array(
				'label' => 'Audio Player Width',
				'type'  => 'width',
			),
		);
	}

	/**
	 * Get the default streaming providers.
	 *
	 * @return array Default streaming providers.
	 */
	public static function providers() {
		return MDS_RM_Core_Functions::mds_rm_get_default_providers();
	}

	public static function enqueue_assets() {
		wp_enqueue_style( 'mds-release-links-block', MDS_RM_URL . 'css/release-links.css', array( 'dashicons' ), filemtime( MDS_RM_PATH . 'css/release-links.css' ) );
		MDS_RM_Assets::enqueue_font_awesome();
	}

	private static function audio( $post_id ) {
		$players  = (array) get_option( 'mds_rm_audio_players', array( 'html5' ) );
		$bandcamp = get_post_meta( $post_id, 'bandcamp_shortcode', true );
		if ( in_array( 'blocks_for_bandcamp', $players, true ) && is_string( $bandcamp ) && '' !== trim( $bandcamp ) ) {
			$input = trim( $bandcamp );
			if ( 0 === stripos( $input, '[bandcamp' ) ) {
				$shortcode = $input;
			} else {
				$template  = get_option( 'mds_rm_bandcamp_template', '[bandcamp width=100% height=42 album={release_number} size=small bgcol=ffffff linkcol=0687f5 artwork=none]' );
				$album_id  = preg_replace( '/[^0-9]/', '', $input );
				$shortcode = str_replace( array( '{release_number}', '{album_number}' ), $album_id, $template );
			}
			if ( shortcode_exists( 'bandcamp' ) ) {
				return MDS_RM_Core_Functions::mds_rm_kses_shortcode_html( do_shortcode( sanitize_text_field( $shortcode ) ) );
			}
			error_log( '[MDS Release Manager] Bandcamp audio is configured but its shortcode is unavailable.' );
		}
		$url = get_post_meta( $post_id, 'audio_preview_url', true );
		if ( ( in_array( 'html5', $players, true ) || in_array( 'native', $players, true ) ) && is_string( $url ) && esc_url( $url ) ) {
			return '<audio controls src="' . esc_url( $url ) . '"></audio>';
		}
		return '';
	}

	public static function render( $attributes = array(), $context_post_id = 0 ) {
		$post = MDS_RM_Release_Meta::resolve_post( isset( $attributes['upc'] ) ? $attributes['upc'] : '', $context_post_id );
		if ( ! $post ) {
			return '';
		}
		$stored = get_post_meta( $post->ID, 'streaming_links', true );
		$links  = json_decode( is_string( $stored ) ? $stored : '', true );
		if ( ! is_array( $links ) ) {
			if ( ! empty( $stored ) ) {
				error_log( '[MDS Release Manager] Invalid streaming links for release ' . $post->ID );
			}
			$links = array();
		}
		$available = array();
		foreach ( $links as $name => $url ) {
			if ( is_string( $name ) && is_string( $url ) && '' !== esc_url( $url ) ) {
				$available[ $name ] = array(
					'url'   => $url,
					'icon'  => 'fas fa-link',
					'color' => '',
				);
			} elseif ( ! empty( $url ) ) {
				error_log( '[MDS Release Manager] Invalid streaming link for release ' . $post->ID );
			}
		}
		foreach ( self::providers() as $provider ) {
			if ( ! isset( $provider['name'] ) || ! is_string( $provider['name'] ) ) {
				continue;
			}
			foreach ( $available as $name => &$link ) {
				if ( 0 === strcasecmp( $name, $provider['name'] ) ) {
					$link['icon']  = ! empty( $provider['icon'] ) ? $provider['icon'] : 'fas fa-link';
					$link['color'] = isset( $provider['color'] ) ? $provider['color'] : '';
				}
			}
			unset( $link );
		}
		$buy_url = get_post_meta( $post->ID, 'buy_now_link', true );
		if ( is_string( $buy_url ) && '' !== esc_url( $buy_url ) ) {
			$available['__buy_now'] = array(
				'url'   => $buy_url,
				'icon'  => 'fas fa-cart-shopping',
				'color' => '#000000',
			);
		}
		$ordered   = array();
		$overrides = isset( $attributes['items'] ) && is_array( $attributes['items'] ) ? $attributes['items'] : array();
		foreach ( $overrides as $item ) {
			if ( ! is_array( $item ) || empty( $item['name'] ) || ! is_string( $item['name'] ) ) {
				error_log( '[MDS Release Manager] Invalid Release Links provider override.' );
				continue;
			}
			foreach ( $available as $name => $link ) {
				if ( 0 !== strcasecmp( $item['name'], $name ) || array_key_exists( $name, $ordered ) ) {
					continue;
				}
				if ( ! isset( $item['enabled'] ) || $item['enabled'] ) {
					foreach ( array( 'icon', 'title' ) as $key ) {
						if ( isset( $item[ $key ] ) && is_string( $item[ $key ] ) && '' !== trim( $item[ $key ] ) ) {
							$link[ $key ] = $item[ $key ];
						}
					}
					$ordered[ $name ] = $link;
				} else {
					$ordered[ $name ] = null;
				}
				break;
			}
		}
		foreach ( $available as $name => $link ) {
			if ( ! array_key_exists( $name, $ordered ) ) {
				$ordered[ $name ] = $link;
			}
		}
		$new_tab = ! isset( $attributes['newTab'] ) || $attributes['newTab'];
		$output  = '';
		foreach ( $ordered as $name => $link ) {
			if ( ! $link ) {
				continue;
			}
			$label = '__buy_now' === $name ? __( 'Buy Now', 'mds-release-manager' ) : $name;
			$title = isset( $link['title'] ) && is_string( $link['title'] ) && '' !== $link['title'] ? $link['title'] : $label;
			$icon  = isset( $link['icon'] ) && is_string( $link['icon'] ) ? trim( $link['icon'] ) : '';
			if ( ! preg_match( '/^(?:dashicons dashicons-[a-z0-9-]+|(?:fa[srbl]|fa-(?:solid|regular|brands|light)) fa-[a-z0-9-]+)$/D', $icon ) ) {
				error_log( '[MDS Release Manager] Invalid Release Links icon for ' . $label );
				$icon = 'fas fa-link';
			}
			$color = isset( $link['color'] ) ? MDS_RM_Release_Meta::valid_style( $link['color'], 'color' ) : '';
			if ( '' === $color && ! empty( $link['color'] ) ) {
				error_log( '[MDS Release Manager] Invalid Release Links provider color for ' . $label );
			}
			$output .= '<a class="mds-release-links__link" href="' . esc_url( $link['url'] ) . '" aria-label="' . esc_attr( $title ) . '" title="' . esc_attr( $title ) . '"' .
				( $new_tab ? ' target="_blank" rel="noopener noreferrer"' : '' ) .
				( $color ? ' style="--mds-links-provider-background:' . esc_attr( $color ) . '"' : '' ) .
				'><i class="' . esc_attr( $icon ) . '" aria-hidden="true"></i></a>';
		}
		$audio = ! isset( $attributes['showAudio'] ) || $attributes['showAudio'] ? self::audio( $post->ID ) : '';
		if ( '' === $output && '' === $audio ) {
			return '';
		}
		self::enqueue_assets();
		$style = MDS_RM_Release_Meta::style_attribute( isset( $attributes['styles'] ) ? $attributes['styles'] : array(), self::style_fields(), 'links' );
		return '<div class="mds-release-links"' . ( $style ? ' style="' . esc_attr( $style ) . '"' : '' ) . '>' .
			( $output ? '<div class="mds-release-links__buttons">' . $output . '</div>' : '' ) .
			( $audio ? '<div class="mds-release-links__audio">' . $audio . '</div>' : '' ) . '</div>';
	}

	public static function shortcode( $atts ) {
		$atts   = (array) $atts;
		$items  = array();
		$order  = isset( $atts['order'] ) ? array_map( 'trim', explode( ',', $atts['order'] ) ) : array();
		$hidden = isset( $atts['hide'] ) ? array_map( 'trim', explode( ',', $atts['hide'] ) ) : array();
		$names  = array_merge( $order, $hidden );
		foreach ( self::providers() as $provider ) {
			$key = isset( $provider['name'] ) ? sanitize_key( str_replace( ' ', '_', $provider['name'] ) ) : '';
			if ( $key && ( isset( $atts[ 'icon_' . $key ] ) || isset( $atts[ 'title_' . $key ] ) ) ) {
				$names[] = $provider['name'];
			}
		}
		if ( isset( $atts['icon_buy_now'] ) || isset( $atts['title_buy_now'] ) ) {
			$names[] = '__buy_now';
		}
		foreach ( array_unique( $names ) as $name ) {
			if ( '' === $name ) {
				continue;
			}
			$key       = '__buy_now' === $name ? 'buy_now' : sanitize_key( str_replace( ' ', '_', $name ) );
			$is_hidden = false;
			foreach ( $hidden as $hidden_name ) {
				if ( 0 === strcasecmp( $name, $hidden_name ) ) {
					$is_hidden = true;
					break;
				}
			}
			$item = array(
				'name'    => $name,
				'enabled' => ! $is_hidden,
			);
			if ( isset( $atts[ 'icon_' . $key ] ) ) {
				$item['icon'] = $atts[ 'icon_' . $key ];
			}
			if ( isset( $atts[ 'title_' . $key ] ) ) {
				$item['title'] = $atts[ 'title_' . $key ];
			}
			$items[] = $item;
		}
		$styles = array();
		foreach ( self::style_fields() as $key => $_field ) {
			foreach ( array(
				'desktop' => '',
				'tablet'  => '_tablet',
				'mobile'  => '_mobile',
			) as $device => $suffix ) {
				if ( isset( $atts[ $key . $suffix ] ) ) {
					$styles[ $device ][ $key ] = $atts[ $key . $suffix ];
				}
			}
		}
		return self::render(
			array(
				'upc'       => isset( $atts['upc'] ) ? $atts['upc'] : '',
				'items'     => $items,
				'newTab'    => self::shortcode_toggle( $atts, 'new_tab' ),
				'showAudio' => self::shortcode_toggle( $atts, 'show_audio' ),
				'styles'    => $styles,
			),
			get_the_ID()
		);
	}

	private static function shortcode_toggle( $atts, $name ) {
		if ( ! isset( $atts[ $name ] ) || '1' === $atts[ $name ] ) {
			return true;
		}
		if ( '0' === $atts[ $name ] ) {
			return false;
		}
		error_log( '[MDS Release Manager] Invalid Release Links shortcode option: ' . $name );
		return true;
	}
}

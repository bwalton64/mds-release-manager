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
	public static function style_fields(): array {
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
	public static function providers(): array {
		return MDS_RM_Providers::defaults();
	}

	/** Enqueues the frontend Release Links stylesheet. */
	public static function enqueue_assets(): void {
		wp_enqueue_style( 'mds-release-links-block', MDS_RM_URL . 'css/release-links.css', array( 'dashicons' ), filemtime( MDS_RM_PATH . 'css/release-links.css' ) );
		MDS_RM_Assets::enqueue_font_awesome();
	}

	/**
	 * Renders the enabled audio preview for a release.
	 *
	 * @param int|string $post_id    Release post ID.
	 * @param array      $attributes Release Links block attributes.
	 * @return string Audio markup, or an empty string when unavailable.
	 */
	private static function audio( int|string $post_id, array $attributes = array() ): string {
		return MDS_RM_Audio_Players::render( (int) $post_id, $attributes );
	}

	/**
	 * Renders streaming links and the optional audio preview for a release.
	 *
	 * @param array      $attributes      Link, audio, and style overrides.
	 * @param int|string $context_post_id Post ID to use when no UPC is supplied.
	 * @return string The release links and audio markup, or an empty string.
	 */
	public static function render( $attributes = array(), $context_post_id = 0 ): string {
		$release_id = isset( $attributes['release_id'] ) ? $attributes['release_id'] : ( isset( $attributes['releaseId'] ) ? $attributes['releaseId'] : 0 );
		$upc        = isset( $attributes['upc'] ) ? $attributes['upc'] : '';
		$post       = MDS_RM_Release_Meta::resolve_shortcode_source( $release_id, $upc, $context_post_id );
		return $post ? self::render_post( $post, $attributes ) : '';
	}

	/**
	 * Renders the links for an already resolved, readable Release.
	 *
	 * @param WP_Post $post       Resolved Release.
	 * @param array   $attributes Link, audio, and style overrides.
	 * @return string Rendered links and audio.
	 */
	public static function render_post( WP_Post $post, array $attributes ): string {
		$stored = get_post_meta( $post->ID, 'streaming_links', true );
		$links  = json_decode( is_string( $stored ) ? $stored : '', true );
		if ( ! is_array( $links ) ) {
			if ( ! empty( $stored ) ) {
				MDS_RM_Logger::report_failure( 'Invalid streaming links for release ' . $post->ID );
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
				MDS_RM_Logger::report_failure( 'Invalid streaming link for release ' . $post->ID );
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
		$buy_now_links  = class_exists( 'MDS_RM_Release_Model' ) ? MDS_RM_Release_Model::get_buy_now_links( $post->ID ) : array();
		$buy_url        = class_exists( 'MDS_RM_Release_Model' ) ? MDS_RM_Release_Model::get_primary_buy_now_url( $post->ID ) : (string) get_post_meta( $post->ID, 'buy_now_link', true );
		$release_mode   = get_post_meta( $post->ID, 'buy_now_mode', true );
		if ( is_string( $release_mode ) && in_array( $release_mode, array( 'picker', 'direct' ), true ) ) {
			$buy_mode = $release_mode;
		} else {
			$buy_mode = isset( $attributes['buyNowMode'] ) && in_array( $attributes['buyNowMode'], array( 'picker', 'direct' ), true )
				? $attributes['buyNowMode']
				: 'picker';
		}
		/**
		 * Filters how the Buy Now button behaves when multiple media formats exist.
		 *
		 * Extensions (such as MDS WooCommerce Integration) can return `'direct'`
		 * when a unified White-Label product URL handles format selection.
		 *
		 * @since 1.1.0
		 * @param string $buy_mode      Display mode (`picker` or `direct`).
		 * @param int    $post_id       Release post ID.
		 * @param array  $buy_now_links Normalized Buy Now offers for the release.
		 */
		$buy_mode       = (string) apply_filters( 'mds_rm_buy_now_display_mode', $buy_mode, $post->ID, $buy_now_links );
		$use_buy_modal  = 'picker' === $buy_mode && count( $buy_now_links ) >= 2;
		$buy_dialog_id  = $use_buy_modal ? 'mds-buy-now-dialog-' . $post->ID : '';

		if ( '' !== $buy_url && '' !== esc_url( $buy_url ) ) {
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
				MDS_RM_Logger::diagnostic( 'Invalid Release Links provider override.' );
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
				MDS_RM_Logger::diagnostic( 'Invalid Release Links icon for ' . $label );
				$icon = 'fas fa-link';
			}
			$color = isset( $link['color'] ) ? MDS_RM_Release_Meta::valid_style( $link['color'], 'color' ) : '';
			if ( '' === $color && ! empty( $link['color'] ) ) {
				MDS_RM_Logger::diagnostic( 'Invalid Release Links provider color for ' . $label );
			}
			$modal_attrs = ( '__buy_now' === $name && $use_buy_modal )
				? ' data-mds-buy-now-modal="' . esc_attr( $buy_dialog_id ) . '" aria-haspopup="dialog" aria-controls="' . esc_attr( $buy_dialog_id ) . '"'
				: '';
			$output     .= '<a class="mds-release-links__link" href="' . esc_url( $link['url'] ) . '" aria-label="' . esc_attr( $title ) . '" title="' . esc_attr( $title ) . '"' .
				( $new_tab ? ' target="_blank" rel="noopener noreferrer"' : '' ) .
				( $color ? ' style="--mds-links-provider-background:' . esc_attr( $color ) . '"' : '' ) .
				$modal_attrs .
				'><i class="' . esc_attr( $icon ) . '" aria-hidden="true"></i></a>';
		}
		$modal_html = '';
		if ( $use_buy_modal && ! empty( $ordered['__buy_now'] ) ) {
			if ( function_exists( 'wp_enqueue_script' ) && defined( 'MDS_RM_URL' ) && defined( 'MDS_RM_PATH' ) && is_readable( MDS_RM_PATH . 'js/buy-now-modal.js' ) ) {
				wp_enqueue_script( 'mds-buy-now-modal', MDS_RM_URL . 'js/buy-now-modal.js', array(), filemtime( MDS_RM_PATH . 'js/buy-now-modal.js' ), true );
			}
			$items_html = '';
			foreach ( $buy_now_links as $offer ) {
				$offer_icon = isset( $offer['icon'] ) && is_string( $offer['icon'] ) ? trim( $offer['icon'] ) : '';
				if ( ! preg_match( '/^(?:dashicons dashicons-[a-z0-9-]+|(?:fa[srbl]|fa-(?:solid|regular|brands|light)) fa-[a-z0-9-]+)$/D', $offer_icon ) ) {
					$offer_icon = 'fas fa-cart-shopping';
				}
				$store_html = ! empty( $offer['store'] )
					? '<span class="mds-buy-now-dialog__store">' . esc_html( (string) $offer['store'] ) . '</span>'
					: '';
				$items_html .= '<li><a class="mds-buy-now-dialog__option" href="' . esc_url( (string) $offer['url'] ) . '"' .
					( $new_tab ? ' target="_blank" rel="noopener noreferrer"' : '' ) . '>' .
					'<span class="mds-buy-now-dialog__icon"><i class="' . esc_attr( $offer_icon ) . '" aria-hidden="true"></i></span>' .
					'<span class="mds-buy-now-dialog__text"><span class="mds-buy-now-dialog__format">' . esc_html( (string) $offer['label'] ) . '</span>' . $store_html . '</span>' .
					'<span class="mds-buy-now-dialog__cta">' . esc_html__( 'Buy', 'mds-release-manager' ) . ' →</span>' .
					'</a></li>';
			}
			$modal_html = '<dialog id="' . esc_attr( $buy_dialog_id ) . '" class="mds-buy-now-dialog" aria-labelledby="' . esc_attr( $buy_dialog_id . '-title' ) . '">' .
				'<div class="mds-buy-now-dialog__header">' .
				'<h3 id="' . esc_attr( $buy_dialog_id . '-title' ) . '" class="mds-buy-now-dialog__title">' . esc_html__( 'Choose a Format', 'mds-release-manager' ) . '</h3>' .
				'<button type="button" class="mds-buy-now-dialog__close" aria-label="' . esc_attr__( 'Close', 'mds-release-manager' ) . '">&times;</button>' .
				'</div>' .
				'<ul class="mds-buy-now-dialog__list">' . $items_html . '</ul>' .
				'</dialog>';
		}
		$audio = ! isset( $attributes['showAudio'] ) || $attributes['showAudio'] ? self::audio( $post->ID, $attributes ) : '';
		if ( '' === $output && '' === $audio ) {
			return '';
		}
		self::enqueue_assets();
		$style = MDS_RM_Release_Meta::style_attribute( isset( $attributes['styles'] ) ? $attributes['styles'] : array(), self::style_fields(), 'links' );
		return '<div class="mds-release-links"' . ( $style ? ' style="' . esc_attr( $style ) . '"' : '' ) . '>' .
			( $output ? '<div class="mds-release-links__buttons">' . $output . '</div>' : '' ) .
			( $audio ? '<div class="mds-release-links__audio">' . $audio . '</div>' : '' ) .
			$modal_html . '</div>';
	}

	/**
	 * Converts Release Links shortcode attributes to renderer options.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string The rendered release links and audio markup.
	 */
	public static function shortcode( $atts ): string {
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
		$buy_now_mode = isset( $atts['buy_now_mode'] ) && in_array( $atts['buy_now_mode'], array( 'picker', 'direct' ), true )
			? $atts['buy_now_mode']
			: 'picker';
		return self::render(
			array(
				'upc'        => isset( $atts['upc'] ) ? $atts['upc'] : '',
				'release_id' => isset( $atts['release_id'] ) ? $atts['release_id'] : '',
				'items'      => $items,
				'newTab'     => self::shortcode_toggle( $atts, 'new_tab' ),
				'showAudio'  => self::shortcode_toggle( $atts, 'show_audio' ),
				'buyNowMode' => $buy_now_mode,
				'styles'     => $styles,
			),
			get_the_ID()
		);
	}

	/**
	 * Reads an optional shortcode flag, which defaults to enabled.
	 *
	 * @param array  $atts Shortcode attributes.
	 * @param string $name Flag name.
	 * @return bool Whether the flag is enabled.
	 */
	private static function shortcode_toggle( array $atts, string $name ): bool {
		if ( ! isset( $atts[ $name ] ) || '1' === $atts[ $name ] ) {
			return true;
		}
		if ( '0' === $atts[ $name ] ) {
			return false;
		}
		MDS_RM_Logger::diagnostic( 'Invalid Release Links shortcode option: ' . $name );
		return true;
	}
}

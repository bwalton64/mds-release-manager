<?php
/**
 * Renders artist discographies.
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Renders the paginated list of releases associated with an Artist. */
final class Discography {
	/**
	 * Render discography from shortcode.
	 *
	 * @param array<string, mixed> $attributes Shortcode attributes.
	 * @return string The rendered HTML.
	 */
	public static function shortcode( array $attributes ): string {
		$style_fields   = array(
			'link-color',
			'link-decoration',
			'table-radius',
			'table-background',
			'table-color',
			'table-font-family',
			'table-font-size',
			'table-font-style',
			'table-font-weight',
			'table-line-height',
			'header-background',
			'header-color',
			'header-font-family',
			'header-font-size',
			'header-font-style',
			'header-font-weight',
			'header-line-height',
			'header-text-align',
			'body-background',
			'body-color',
			'body-font-family',
			'body-font-size',
			'body-font-style',
			'body-font-weight',
			'body-line-height',
			'body-text-align',
			'table-margin-top',
			'table-margin-right',
			'table-margin-bottom',
			'table-margin-left',
			'table-padding-top',
			'table-padding-right',
			'table-padding-bottom',
			'table-padding-left',
			'cell-padding-top',
			'cell-padding-right',
			'cell-padding-bottom',
			'cell-padding-left',
			'table-border-width',
			'table-border-color',
			'table-border-style',
			'cell-border-width',
			'cell-border-color',
			'cell-border-style',
			'stripe-background',
		);
		$wrapper_fields = array(
			'wrapper_background',
			'wrapper_text_color',
			'wrapper_link_color',
			'wrapper_font_size',
			'wrapper_font_family',
			'wrapper_font_weight',
			'wrapper_font_style',
			'wrapper_line_height',
			'wrapper_letter_spacing',
			'wrapper_margin',
			'wrapper_padding',
			'wrapper_border_color',
			'wrapper_border_radius',
			'wrapper_border_style',
			'wrapper_border_width',
		);
		$defaults       = array(
			'artist_id' => 0,
			'striped'   => '',
		);
		foreach ( $style_fields as $field ) {
			$defaults[ str_replace( '-', '_', $field ) ] = '';
		}
		foreach ( $wrapper_fields as $field ) {
			$defaults[ $field ] = '';
		}
		$attributes  = shortcode_atts( $defaults, $attributes, 'artist-discography' );
		$artist_id   = absint( $attributes['artist_id'] );
		$table_style = array();
		foreach ( $style_fields as $field ) {
			$attribute = str_replace( '-', '_', $field );
			if ( isset( $attributes[ $attribute ] ) ) {
				$table_style[ $field ] = $attributes[ $attribute ];
			}
		}
		if ( '1' === $attributes['striped'] ) {
			$table_style['striped'] = true;
		} elseif ( '0' === $attributes['striped'] || '' === $attributes['striped'] ) {
			$table_style['striped'] = false;
		} else {
			\MDS\ReleaseManager\Core\Logger::diagnostic( 'Invalid Artist Discography shortcode option: striped' );
		}

		$content = self::render( $artist_id ? $artist_id : get_the_ID() );
		if ( '' === $content ) {
			return '';
		}
		$style = self::shortcode_wrapper_style( $attributes ) . self::table_style_variables( $table_style );
		if ( '' === $style ) {
			return $content;
		}

		if ( ! wp_style_is( 'mds-rm-discography-block', 'registered' ) ) {
			$style_file = 'blocks/artist-discography/style.css';
			wp_register_style( 'mds-rm-discography-block', MDS_RM_URL . $style_file, array(), filemtime( MDS_RM_PATH . $style_file ) );
		}
		wp_enqueue_style( 'mds-rm-discography-block' );

		$classes = array( 'wp-block-mds-rm-artist-discography' );
		if ( '' !== \MDS\ReleaseManager\Blocks\ReleaseMeta::valid_style( $attributes['wrapper_text_color'] ?? '', 'color' ) ) {
			$classes[] = 'has-text-color';
		}
		if ( '' !== \MDS\ReleaseManager\Blocks\ReleaseMeta::valid_style( $attributes['wrapper_font_size'] ?? '', 'font_size' ) ) {
			$classes[] = 'has-custom-font-size';
		}
		return '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" style="' . esc_attr( rtrim( $style, ';' ) ) . '">' . $content . '</div>';
	}

	/**
	 * Render discography from block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $_content   Block content.
	 * @param object               $block      Block instance with optional post context.
	 * @return string The rendered HTML.
	 */
	public static function render_block( array $attributes, string $_content, object $block ): string {
		$artist_id = ! empty( $attributes['artistId'] ) ? absint( $attributes['artistId'] ) : ( isset( $block->context['postId'] ) ? absint( $block->context['postId'] ) : get_the_ID() );
		$html      = self::render( $artist_id );
		$styles    = self::table_style_variables( $attributes['tableStyles'] ?? array() );
		return $html ? '<div ' . get_block_wrapper_attributes( $styles ? array( 'style' => $styles ) : array() ) . '>' . $html . '</div>' : '';
	}

	/**
	 * Converts known table settings to scoped CSS variables.
	 *
	 * @param mixed $settings Saved table settings.
	 * @return string Validated CSS declarations.
	 */
	public static function table_style_variables( $settings ): string {
		if ( ! is_array( $settings ) ) {
			return '';
		}

		$presets = array(
			'color'       => '/\A(?:#[a-f0-9]{3,8}|rgba?\([0-9.,% \/]+\)|transparent|var\(--wp--preset--color--[a-z0-9_-]+\))\z/i',
			'length'      => '/\A(?:0|[0-9]+(?:\.[0-9]+)?(?:px|em|rem|%|vw|vh|ch|ex|vmin|vmax)|var\(--wp--preset--spacing--[a-z0-9_-]+\))\z/i',
			'font-family' => '/\Avar\(--wp--preset--font-family--[a-z0-9_-]+\)\z/i',
			'font-size'   => '/\A(?:[0-9]{1,2}px|var\(--wp--preset--font-size--[a-z0-9_-]+\))\z/i',
			'font-weight' => '/\A[1-9]00\z/',
			'font-style'  => '/\A(?:normal|italic)\z/',
			'line-height' => '/\A[0-9](?:\.[0-9]+)?\z/',
			'text-align'  => '/\A(?:start|center|end)\z/',
			'decoration'  => '/\A(?:underline|none)\z/',
			'border'      => '/\A(?:none|solid|dashed|dotted|double|groove|ridge|inset|outset|hidden)\z/',
		);
		$fields  = array(
			'link-color'      => 'color',
			'link-decoration' => 'decoration',
			'table-radius'    => 'length',
		);
		foreach ( array( 'table', 'header', 'body' ) as $region ) {
			$fields[ "$region-background" ] = 'color';
			$fields[ "$region-color" ]      = 'color';
			foreach ( array( 'font-family', 'font-size', 'font-style', 'font-weight', 'line-height', 'text-align' ) as $property ) {
				$fields[ "$region-$property" ] = $property;
			}
		}
		foreach ( array( 'table-margin', 'table-padding', 'cell-padding' ) as $spacing ) {
			foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
				$fields[ "$spacing-$side" ] = 'length';
			}
		}
		foreach ( array( 'table-border', 'cell-border' ) as $border ) {
			$fields[ "$border-width" ] = 'length';
			$fields[ "$border-color" ] = 'color';
			$fields[ "$border-style" ] = 'border';
		}
		if ( true === ( $settings['striped'] ?? false ) ) {
			$fields['stripe-background'] = 'color';
			if ( ! isset( $settings['stripe-background'] ) || '' === $settings['stripe-background'] ) {
				$settings['stripe-background'] = 'rgba(0,0,0,0.06)';
			}
		}

		$css = '';
		foreach ( $fields as $field => $type ) {
			$value = $settings[ $field ] ?? null;
			if ( is_string( $value ) && preg_match( $presets[ $type ], $value ) ) {
				$css .= "--mds-discography-$field:$value;";
			} elseif ( null !== $value && '' !== $value && false !== $value ) {
				\MDS\ReleaseManager\Core\Logger::diagnostic( 'Invalid Artist Discography style: ' . $field );
			}
		}
		return $css;
	}

	/**
	 * Builds validated inline styles for the shortcode wrapper.
	 *
	 * @param array $attributes Shortcode attributes.
	 * @return string CSS declarations.
	 */
	private static function shortcode_wrapper_style( array $attributes ): string {
		$fields       = array(
			'wrapper_background'     => array( 'background-color', 'color' ),
			'wrapper_text_color'     => array( 'color', 'color' ),
			'wrapper_link_color'     => array( '--wp--style--color--link', 'color' ),
			'wrapper_font_size'      => array( 'font-size', 'font_size' ),
			'wrapper_font_family'    => array( 'font-family', 'font' ),
			'wrapper_font_weight'    => array( 'font-weight', 'weight' ),
			'wrapper_font_style'     => array( 'font-style', 'font_style' ),
			'wrapper_line_height'    => array( 'line-height', 'line_height' ),
			'wrapper_letter_spacing' => array( 'letter-spacing', 'length' ),
			'wrapper_margin'         => array( 'margin', 'spacing' ),
			'wrapper_padding'        => array( 'padding', 'spacing' ),
			'wrapper_border_color'   => array( 'border-color', 'color' ),
			'wrapper_border_radius'  => array( 'border-radius', 'spacing' ),
			'wrapper_border_style'   => array( 'border-style', 'border_style' ),
			'wrapper_border_width'   => array( 'border-width', 'spacing' ),
		);
		$declarations = array();
		foreach ( $fields as $attribute => $field ) {
			if ( ! isset( $attributes[ $attribute ] ) ) {
				continue;
			}
			$value = \MDS\ReleaseManager\Blocks\ReleaseMeta::valid_style( $attributes[ $attribute ], $field[1] );
			if ( '' !== $value ) {
				$declarations[] = $field[0] . ':' . $value;
			} elseif ( ! is_scalar( $attributes[ $attribute ] ) || '' !== (string) $attributes[ $attribute ] ) {
				\MDS\ReleaseManager\Core\Logger::diagnostic( 'Invalid Artist Discography shortcode style: ' . $attribute );
			}
		}
		return $declarations ? implode( ';', $declarations ) . ';' : '';
	}

	/**
	 * Render the discography HTML for an artist.
	 *
	 * @param int $artist_id The artist post ID.
	 * @return string The rendered HTML.
	 */
	public static function render( int $artist_id ): string {
		$artist = get_post( absint( $artist_id ) );
		if ( ! $artist instanceof \WP_Post || 'artist' !== $artist->post_type || ( 'publish' !== $artist->post_status && ! current_user_can( 'read_post', $artist->ID ) ) ) {
			return '';
		}
		$uuid = get_post_meta( $artist->ID, '_mds_artist_uuid', true );
		if ( ! is_string( $uuid ) || '' === $uuid ) {
			return '<p class="mds-discography__empty">' . esc_html__( 'No releases found.', 'mds-release-manager' ) . '</p>';
		}

		$page_key = 'mds_discography_page_' . $artist->ID;
		$page     = 1;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Public pagination only selects which published releases to display.
		if ( isset( $_GET[ $page_key ] ) && is_string( $_GET[ $page_key ] ) ) {
			$page_value = filter_var(
				wp_unslash( $_GET[ $page_key ] ),
				FILTER_VALIDATE_REGEXP,
				array( 'options' => array( 'regexp' => '/\A[0-9]+\z/' ) )
			);
			$page       = max( 1, (int) $page_value );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$query   = new \WP_Query(
			array(
				'post_type'           => 'release',
				'post_status'         => 'publish',
				'posts_per_page'      => 25,
				'paged'               => $page,
				'meta_key'            => 'artist_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Paginate releases related by the stored Artist UUID.
				'meta_value'          => $uuid, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'             => array(
					'date' => 'DESC',
					'ID'   => 'DESC',
				),
				'ignore_sticky_posts' => true,
			)
		);
		$columns = apply_filters(
			'mds_rm_discography_columns',
			array(
				'title'        => __( 'Title', 'mds-release-manager' ),
				'upc_ean'      => __( 'UPC/EAN', 'mds-release-manager' ),
				'record_label' => __( 'Label', 'mds-release-manager' ),
				'distributor'  => __( 'Distributor', 'mds-release-manager' ),
				'release_year' => __( 'Release Year', 'mds-release-manager' ),
			),
			$artist
		);
		if ( ! is_array( $columns ) || ! $columns ) {
			\MDS\ReleaseManager\Core\Logger::diagnostic( 'Invalid discography columns.' );
			return '';
		}
		$columns = array_filter(
			$columns,
			static function ( $label, $key ) {
				return is_string( $key ) && '' !== $key && is_string( $label ) && '' !== $label;
			},
			ARRAY_FILTER_USE_BOTH
		);
		if ( ! $columns ) {
			\MDS\ReleaseManager\Core\Logger::diagnostic( 'Discography has no valid columns.' );
			return '';
		}

		ob_start();
		?>
		<section class="mds-discography" aria-label="<?php esc_attr_e( 'Discography', 'mds-release-manager' ); ?>">
			<h2><?php esc_html_e( 'Discography', 'mds-release-manager' ); ?></h2>
			<?php if ( $query->posts ) : ?>
				<div class="mds-discography__scroll" tabindex="0">
					<table>
						<?php
						echo '<thead><tr>';
						foreach ( $columns as $label ) {
							echo '<th scope="col">' . esc_html( $label ) . '</th>';
						}
						echo '</tr></thead>' . "\n";
						?>
						<tbody>
						<?php foreach ( $query->posts as $release ) : ?>
							<tr>
								<?php foreach ( $columns as $key => $label ) : ?>
									<?php
									if ( 'title' === $key ) {
										$value = '<a href="' . esc_url( get_permalink( $release ) ) . '">' . esc_html( get_the_title( $release ) ) . '</a>';
									} elseif ( 'release_year' === $key ) {
										$value = '0000-00-00 00:00:00' === $release->post_date ? '' : esc_html( substr( $release->post_date, 0, 4 ) );
									} elseif ( in_array( $key, array( 'upc_ean', 'record_label', 'distributor' ), true ) ) {
										$meta_value = get_post_meta( $release->ID, $key, true );
										$value      = is_scalar( $meta_value ) ? esc_html( (string) $meta_value ) : '';
									} else {
										$value = '';
									}
									$value = apply_filters( 'mds_rm_discography_cell', $value, $key, $release, $artist );
									if ( ! is_string( $value ) ) {
										\MDS\ReleaseManager\Core\Logger::diagnostic( 'Invalid discography cell value for ' . $key );
										$value = '';
									}
									?>
									<td><?php echo wp_kses_post( $value ); ?></td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php else : ?>
				<p class="mds-discography__empty"><?php esc_html_e( 'No releases found on this page.', 'mds-release-manager' ); ?></p>
			<?php endif; ?>
			<?php if ( $query->max_num_pages > 1 ) : ?>
				<nav class="mds-discography__pagination" aria-label="<?php esc_attr_e( 'Discography pages', 'mds-release-manager' ); ?>">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( $page_key, '%#%' ),
								'format'    => '',
								'current'   => $page,
								'total'     => $query->max_num_pages,
								'prev_text' => __( 'Previous', 'mds-release-manager' ),
								'next_text' => __( 'Next', 'mds-release-manager' ),
							)
						)
					);
					?>
				</nav>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}
}

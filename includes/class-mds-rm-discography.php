<?php
/**
 * Renders artist discographies.
 *
 * @package MDS_Release_Manager
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MDS_RM_Discography {
	/**
	 * Render discography from shortcode.
	 *
	 * @param array<string, mixed> $attributes Shortcode attributes.
	 * @return string The rendered HTML.
	 */
	public static function shortcode( array $attributes ): string {
		$attributes = shortcode_atts( array( 'artist_id' => 0 ), $attributes, 'artist-discography' );
		return self::render( absint( $attributes['artist_id'] ) ?: get_the_ID() );
	}

	/**
	 * Render discography from block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $_content Block content.
	 * @param WP_Block             $block Block instance.
	 * @return string The rendered HTML.
	 */
	public static function render_block( array $attributes, string $_content, object $block ): string {
		$artist_id = ! empty( $attributes['artistId'] ) ? absint( $attributes['artistId'] ) : ( isset( $block->context['postId'] ) ? absint( $block->context['postId'] ) : get_the_ID() );
		$html = self::render( $artist_id );
		return $html ? '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>' : '';
	}

	/**
	 * Render the discography HTML for an artist.
	 *
	 * @param int $artist_id The artist post ID.
	 * @return string The rendered HTML.
	 */
	public static function render( int $artist_id ): string {
		$artist = get_post( absint( $artist_id ) );
		if ( ! $artist instanceof WP_Post || 'artist' !== $artist->post_type || ( 'publish' !== $artist->post_status && ! current_user_can( 'read_post', $artist->ID ) ) ) {
			return '';
		}
		$uuid = get_post_meta( $artist->ID, '_mds_artist_uuid', true );
		if ( ! is_string( $uuid ) || '' === $uuid ) {
			return '<p class="mds-discography__empty">' . esc_html__( 'No releases found.', 'mds-release-manager' ) . '</p>';
		}

		$page_key = 'mds_discography_page_' . $artist->ID;
		$page = 1;
		if ( isset( $_GET[ $page_key ] ) && is_string( $_GET[ $page_key ] ) && ctype_digit( $_GET[ $page_key ] ) ) {
			$page = max( 1, (int) $_GET[ $page_key ] );
		}
		$query = new WP_Query(
			array(
				'post_type' => 'release',
				'post_status' => 'publish',
				'posts_per_page' => 25,
				'paged' => $page,
				'meta_key' => 'artist_id',
				'meta_value' => $uuid,
				'orderby' => array( 'date' => 'DESC', 'ID' => 'DESC' ),
				'ignore_sticky_posts' => true,
			)
		);
		$columns = apply_filters(
			'mds_rm_discography_columns',
			array(
				'title' => __( 'Title', 'mds-release-manager' ),
				'upc_ean' => __( 'UPC/EAN', 'mds-release-manager' ),
				'record_label' => __( 'Label', 'mds-release-manager' ),
				'distributor' => __( 'Distributor', 'mds-release-manager' ),
				'release_year' => __( 'Release Year', 'mds-release-manager' ),
			),
			$artist
		);
		if ( ! is_array( $columns ) || ! $columns ) {
			error_log( '[MDS Release Manager] Invalid discography columns.' );
			return '';
		}
		$columns = array_filter( $columns, static function ( $label, $key ) {
			return is_string( $key ) && '' !== $key && is_string( $label ) && '' !== $label;
		}, ARRAY_FILTER_USE_BOTH );
		if ( ! $columns ) {
			error_log( '[MDS Release Manager] Discography has no valid columns.' );
			return '';
		}

		ob_start();
		?>
		<section class="mds-discography" aria-label="<?php esc_attr_e( 'Discography', 'mds-release-manager' ); ?>">
			<h2><?php esc_html_e( 'Discography', 'mds-release-manager' ); ?></h2>
			<?php if ( $query->posts ) : ?>
				<div class="mds-discography__scroll" tabindex="0">
					<table>
						<thead><tr><?php foreach ( $columns as $label ) : ?><th scope="col"><?php echo esc_html( $label ); ?></th><?php endforeach; ?></tr></thead>
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
										$value = is_scalar( $meta_value ) ? esc_html( (string) $meta_value ) : '';
									} else {
										$value = '';
									}
									$value = apply_filters( 'mds_rm_discography_cell', $value, $key, $release, $artist );
									if ( ! is_string( $value ) ) {
										error_log( '[MDS Release Manager] Invalid discography cell value for ' . $key );
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
								'base' => add_query_arg( $page_key, '%#%' ),
								'format' => '',
								'current' => $page,
								'total' => $query->max_num_pages,
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

<?php
/**
 * Release commerce meta box: multi-format Buy Now purchase links.
 *
 * @package MDS_Release_Manager
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

( static function ( array $args ): void {
	$buy_now_links = is_array( $args['buy_now_links'] ?? null ) ? $args['buy_now_links'] : array();
	$media_formats = is_array( $args['all_media_formats'] ?? null ) ? $args['all_media_formats'] : array();
	if ( empty( $buy_now_links ) ) {
		$buy_now_links = array(
			array(
				'format'  => '',
				'store'   => '',
				'url'     => '',
				'primary' => true,
			),
		);
	}
	?>
<div class="mds-meta-section">
	<p class="description">
		<?php esc_html_e( 'Add one Buy Now URL per available Media Format (Digital Download, CD, Vinyl LP, etc.). Select a Primary format for direct links and Site-Wide Routing. Manage available formats and icons under Releases → Media Formats.', 'mds-release-manager' ); ?>
	</p>
	<div id="mds_buy_now_links_wrapper" class="mds-buy-now-repeater">
		<?php foreach ( $buy_now_links as $index => $row ) : ?>
			<div class="mds-buy-now-row">
				<label class="mds-buy-now-primary" title="<?php esc_attr_e( 'Primary Buy Now URL', 'mds-release-manager' ); ?>">
					<input type="radio" name="mds_buy_now_primary" value="<?php echo esc_attr( (string) $index ); ?>" <?php checked( ! empty( $row['primary'] ) ); ?> />
					<span><?php esc_html_e( 'Primary', 'mds-release-manager' ); ?></span>
				</label>
				<select name="mds_buy_now_format[]" aria-label="<?php esc_attr_e( 'Media Format', 'mds-release-manager' ); ?>">
					<option value=""><?php esc_html_e( '— Media Format —', 'mds-release-manager' ); ?></option>
					<?php foreach ( $media_formats as $term ) : ?>
						<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( (string) ( $row['format'] ?? '' ), $term->slug ); ?>>
							<?php echo esc_html( $term->name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<input
					type="text"
					name="mds_buy_now_store[]"
					value="<?php echo esc_attr( (string) ( $row['store'] ?? '' ) ); ?>"
					placeholder="<?php esc_attr_e( 'Store / Edition (optional, e.g. Bandcamp)', 'mds-release-manager' ); ?>"
					aria-label="<?php esc_attr_e( 'Store or Edition Note', 'mds-release-manager' ); ?>"
				/>
				<input
					type="url"
					name="mds_buy_now_url[]"
					value="<?php echo esc_url( (string) ( $row['url'] ?? '' ) ); ?>"
					placeholder="https://..."
					aria-label="<?php esc_attr_e( 'Buy Now URL', 'mds-release-manager' ); ?>"
				/>
				<button type="button" class="button mds-remove-buy-now-row"><?php esc_html_e( 'Remove', 'mds-release-manager' ); ?></button>
			</div>
		<?php endforeach; ?>
	</div>
	<p class="mds-buy-now-actions">
		<button type="button" class="button button-secondary" id="mds_add_buy_now_row"><?php esc_html_e( '+ Add Media Format', 'mds-release-manager' ); ?></button>
		<?php do_action( 'mds_rm_after_buy_now_field', $args['post'] ); ?>
	</p>
	<table class="mds-meta-table">
		<tr>
			<th><label for="mds_buy_now_mode"><?php esc_html_e( 'Buy Now Button Behavior', 'mds-release-manager' ); ?></label></th>
			<td>
				<?php $buy_now_mode = isset( $args['buy_now_mode'] ) && is_string( $args['buy_now_mode'] ) ? $args['buy_now_mode'] : ''; ?>
				<select id="mds_buy_now_mode" name="mds_buy_now_mode">
					<option value="" <?php selected( $buy_now_mode, '' ); ?>><?php esc_html_e( 'Use site / block default', 'mds-release-manager' ); ?></option>
					<option value="direct" <?php selected( $buy_now_mode, 'direct' ); ?>><?php esc_html_e( 'Use Primary URL Only (direct link)', 'mds-release-manager' ); ?></option>
					<option value="picker" <?php selected( $buy_now_mode, 'picker' ); ?>><?php esc_html_e( 'Show Modal Popup (choose format)', 'mds-release-manager' ); ?></option>
				</select>
				<p class="description">
					<?php esc_html_e( 'Controls what happens when a visitor clicks the Buy Now button in the Release Links block or shortcode for this release. Modal Popup applies when 2 or more formats are configured.', 'mds-release-manager' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th><label for="mds_release_routing_mode"><?php esc_html_e( 'Release Page Routing', 'mds-release-manager' ); ?></label></th>
			<td>
				<?php
				$routing_mode    = (string) ( $args['release_routing_mode'] ?? 'inherit' );
				$routing_url     = (string) ( $args['release_routing_url'] ?? '' );
				$routing_modes   = is_array( $args['release_routing_modes'] ?? null ) ? $args['release_routing_modes'] : array();
				$routing_preview = is_array( $args['release_routing_preview'] ?? null ) ? $args['release_routing_preview'] : array();
				?>
				<select id="mds_release_routing_mode" name="mds_release_routing_mode">
					<?php foreach ( $routing_modes as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $routing_mode, $slug ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<div id="mds_release_routing_url_wrap" style="margin-top: 8px;" <?php echo 'custom_url' === $routing_mode ? '' : 'hidden'; ?>>
					<input
						type="url"
						id="mds_release_routing_url"
						name="mds_release_routing_url"
						value="<?php echo esc_url( $routing_url ); ?>"
						placeholder="https://..."
						class="regular-text"
					/>
				</div>
				<?php if ( ! empty( $routing_preview['label'] ) ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: Current resolved routing destination. */
							esc_html__( 'Resolved destination: %s', 'mds-release-manager' ),
							'<strong>' . esc_html( (string) $routing_preview['label'] ) . '</strong>'
						);
						?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
	</table>
	<template id="mds_buy_now_row_template">
		<div class="mds-buy-now-row">
			<label class="mds-buy-now-primary" title="<?php esc_attr_e( 'Primary Buy Now URL', 'mds-release-manager' ); ?>">
				<input type="radio" name="mds_buy_now_primary" value="__INDEX__" />
				<span><?php esc_html_e( 'Primary', 'mds-release-manager' ); ?></span>
			</label>
			<select name="mds_buy_now_format[]" aria-label="<?php esc_attr_e( 'Media Format', 'mds-release-manager' ); ?>">
				<option value=""><?php esc_html_e( '— Media Format —', 'mds-release-manager' ); ?></option>
				<?php foreach ( $media_formats as $term ) : ?>
					<option value="<?php echo esc_attr( $term->slug ); ?>"><?php echo esc_html( $term->name ); ?></option>
				<?php endforeach; ?>
			</select>
			<input
				type="text"
				name="mds_buy_now_store[]"
				value=""
				placeholder="<?php esc_attr_e( 'Store / Edition (optional, e.g. Bandcamp)', 'mds-release-manager' ); ?>"
				aria-label="<?php esc_attr_e( 'Store or Edition Note', 'mds-release-manager' ); ?>"
			/>
			<input
				type="url"
				name="mds_buy_now_url[]"
				value=""
				placeholder="https://..."
				aria-label="<?php esc_attr_e( 'Buy Now URL', 'mds-release-manager' ); ?>"
			/>
			<button type="button" class="button mds-remove-buy-now-row"><?php esc_html_e( 'Remove', 'mds-release-manager' ); ?></button>
		</div>
	</template>
</div>
	<?php
} )( is_array( $args ?? null ) ? $args : array() );

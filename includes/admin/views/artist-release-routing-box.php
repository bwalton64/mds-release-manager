<?php
/**
 * Artist Release Routing meta box: default Artist policy and editable per-release routing table.
 *
 * @package MDS_Release_Manager
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$artist_id     = (int) ( $args['artist_id'] ?? 0 );
$artist_mode   = (string) ( $args['artist_releases_routing_mode'] ?? 'fallback' );
$artist_modes  = is_array( $args['artist_modes'] ?? null ) ? $args['artist_modes'] : array();
$release_modes = is_array( $args['release_modes'] ?? null ) ? $args['release_modes'] : array();
$releases      = is_array( $args['releases'] ?? null ) ? $args['releases'] : array();
?>
<div id="mds-artist-routing-manager" class="mds-routing-manager" data-artist-id="<?php echo esc_attr( (string) $artist_id ); ?>">
	<div class="mds-routing-policy-bar">
		<div class="mds-routing-policy-field">
			<label for="mds_artist_releases_routing_mode">
				<strong><?php esc_html_e( 'Default Routing Policy for All Releases', 'mds-release-manager' ); ?></strong>
			</label>
			<select id="mds_artist_releases_routing_mode" name="artist_releases_routing_mode">
				<?php foreach ( $artist_modes as $slug => $label ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $artist_mode, $slug ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<p class="description">
				<?php esc_html_e( 'Applies to every release below set to "Inherit Artist Default". Individual releases can override this policy in the table below. All redirects use 302 Found and fall back gracefully to the release page if a target URL is missing.', 'mds-release-manager' ); ?>
			</p>
		</div>
	</div>

	<?php if ( empty( $releases ) ) : ?>
		<p class="description">
			<?php esc_html_e( 'No releases are currently linked to this Artist. Once releases are assigned to this Artist, you can manage their individual and bulk routing rules here.', 'mds-release-manager' ); ?>
		</p>
	<?php else : ?>
		<div class="mds-routing-toolbar">
			<div class="mds-routing-bulk-controls">
				<select id="mds-routing-bulk-mode" aria-label="<?php esc_attr_e( 'Bulk routing action', 'mds-release-manager' ); ?>">
					<option value=""><?php esc_html_e( 'Bulk Actions…', 'mds-release-manager' ); ?></option>
					<?php foreach ( $release_modes as $slug => $label ) : ?>
						<?php
						if ( 'custom_url' === $slug ) {
							continue;
						}
						?>
						<option value="<?php echo esc_attr( $slug ); ?>">
							<?php
							/* translators: %s: Routing mode label. */
							echo esc_html( sprintf( __( 'Set selected to: %s', 'mds-release-manager' ), $label ) );
							?>
						</option>
					<?php endforeach; ?>
				</select>
				<button type="button" class="button button-secondary" id="mds-routing-bulk-apply"><?php esc_html_e( 'Apply', 'mds-release-manager' ); ?></button>
				<button type="button" class="button button-link" id="mds-routing-reset-all"><?php esc_html_e( 'Reset All to Inherit', 'mds-release-manager' ); ?></button>
			</div>
			<div class="mds-routing-search-wrap">
				<input
					type="search"
					id="mds-routing-search"
					class="regular-text"
					placeholder="<?php esc_attr_e( 'Filter releases by title or UPC…', 'mds-release-manager' ); ?>"
					aria-label="<?php esc_attr_e( 'Filter releases by title or UPC', 'mds-release-manager' ); ?>"
				/>
			</div>
		</div>

		<div class="mds-routing-table-wrap">
			<table class="wp-list-table widefat fixed striped mds-routing-table">
				<thead>
					<tr>
						<td class="manage-column column-cb check-column">
							<input type="checkbox" id="mds-routing-select-all" aria-label="<?php esc_attr_e( 'Select all releases', 'mds-release-manager' ); ?>" />
						</td>
						<th style="width: 56px;"><?php esc_html_e( 'Cover', 'mds-release-manager' ); ?></th>
						<th><?php esc_html_e( 'Release', 'mds-release-manager' ); ?></th>
						<th style="width: 140px;"><?php esc_html_e( 'UPC / EAN', 'mds-release-manager' ); ?></th>
						<th style="width: 220px;"><?php esc_html_e( 'Routing Mode', 'mds-release-manager' ); ?></th>
						<th><?php esc_html_e( 'Custom URL / Resolved Destination', 'mds-release-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $releases as $row ) : ?>
						<?php
						$rid        = (int) $row['id'];
						$own_mode   = (string) $row['own_mode'];
						$custom_url = (string) $row['custom_url'];
						$preview    = is_array( $row['preview'] ) ? $row['preview'] : array();
						$is_custom  = 'custom_url' === $own_mode;
						?>
						<tr
							class="mds-routing-row"
							data-release-id="<?php echo esc_attr( (string) $rid ); ?>"
							data-search="<?php echo esc_attr( strtolower( $row['title'] . ' ' . $row['upc'] ) ); ?>"
						>
							<th scope="row" class="check-column">
								<input type="checkbox" class="mds-routing-row-cb" value="<?php echo esc_attr( (string) $rid ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Select %s', 'mds-release-manager' ), $row['title'] ) ); ?>" />
							</th>
							<td>
								<?php if ( ! empty( $row['thumb'] ) ) : ?>
									<?php echo wp_kses_post( $row['thumb'] ); ?>
								<?php else : ?>
									<span class="dashicons dashicons-album mds-routing-thumb-placeholder" aria-hidden="true"></span>
								<?php endif; ?>
							</td>
							<td>
								<strong><a href="<?php echo esc_url( $row['edit_url'] ); ?>"><?php echo esc_html( $row['title'] ); ?></a></strong>
							</td>
							<td><code><?php echo esc_html( $row['upc'] ? $row['upc'] : '—' ); ?></code></td>
							<td>
								<select class="mds-routing-mode-select" aria-label="<?php echo esc_attr( sprintf( __( 'Routing mode for %s', 'mds-release-manager' ), $row['title'] ) ); ?>">
									<?php foreach ( $release_modes as $slug => $label ) : ?>
										<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $own_mode, $slug ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
							<td>
								<input
									type="url"
									class="mds-routing-custom-url regular-text"
									value="<?php echo esc_url( $custom_url ); ?>"
									placeholder="https://..."
									aria-label="<?php echo esc_attr( sprintf( __( 'Custom redirect URL for %s', 'mds-release-manager' ), $row['title'] ) ); ?>"
									<?php echo $is_custom ? '' : 'hidden'; ?>
								/>
								<span class="mds-routing-preview <?php echo ! empty( $preview['is_warning'] ) ? 'is-warning' : ''; ?>" <?php echo $is_custom ? 'hidden' : ''; ?>>
									<?php echo esc_html( (string) ( $preview['label'] ?? '' ) ); ?>
								</span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<div class="mds-routing-footer">
			<button type="button" class="button button-primary" id="mds-routing-save-btn"><?php esc_html_e( 'Save Routing Changes', 'mds-release-manager' ); ?></button>
			<span id="mds-routing-status" class="mds-routing-status" role="status" aria-live="polite"></span>
		</div>
	<?php endif; ?>
</div>

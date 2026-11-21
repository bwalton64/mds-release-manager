<?php
/**
 * Release Manager settings page.
 *
 * @package MDS_Release_Manager
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap mds-settings-wrap">
	<h1><?php esc_html_e( 'Release Manager Settings', 'mds-release-manager' ); ?></h1>

	<h2 class="nav-tab-wrapper" id="mds-settings-tabs">
		<a href="#tab-release-meta" class="nav-tab nav-tab-active" aria-current="page"><?php esc_html_e( 'Release Meta', 'mds-release-manager' ); ?></a>
		<a href="#tab-release-links" class="nav-tab"><?php esc_html_e( 'Release Links', 'mds-release-manager' ); ?></a>
		<a href="#tab-artist-discography" class="nav-tab"><?php esc_html_e( 'Artist Discography', 'mds-release-manager' ); ?></a>
		<a href="#tab-integrations" class="nav-tab"><?php esc_html_e( 'Integrations', 'mds-release-manager' ); ?></a>
		<?php foreach ( $args['extension_tabs'] as $slug => $tab ) : ?>
			<a href="#tab-<?php echo esc_attr( $slug ); ?>" class="nav-tab"><?php echo esc_html( $tab['label'] ); ?></a>
		<?php endforeach; ?>
	</h2>

	<form id="mds-settings-form">
		<input type="hidden" name="mds_rm_block_defaults" id="mds-rm-block-defaults" value="<?php echo esc_attr( wp_json_encode( $args['block_defaults'] ) ); ?>" />
		<div id="tab-release-meta" class="mds-tab-pane active">
			<div class="mds-panel">
				<h2><?php esc_html_e( 'Release Meta defaults', 'mds-release-manager' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Blocks inherit these defaults unless an individual block overrides them.', 'mds-release-manager' ); ?></p>
				<div id="mds-defaults-release-meta"></div>
			</div>
			<div class="mds-admin-save-actions">
				<button type="submit" class="button button-primary mds-save-settings-btn"><?php esc_html_e( 'Save Settings', 'mds-release-manager' ); ?></button>
				<span class="mds-save-status" role="status"><?php esc_html_e( 'Settings saved! Cache purged.', 'mds-release-manager' ); ?></span>
			</div>
		</div>
		<div id="tab-release-links" class="mds-tab-pane">
			<div class="mds-panel">
				<h2><?php esc_html_e( 'Release Links defaults', 'mds-release-manager' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Blocks inherit these defaults unless an individual block overrides them.', 'mds-release-manager' ); ?></p>
				<div id="mds-defaults-release-links"></div>
			</div>
			<div class="mds-panel">
				<h3><?php esc_html_e( 'Streaming Provider Defaults', 'mds-release-manager' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Configure streaming providers used in the release editor.', 'mds-release-manager' ); ?></p>
				<div id="mds_providers_repeater">
					<?php foreach ( $args['providers'] as $provider ) : ?>
						<?php if ( isset( $provider['name'], $provider['icon'], $provider['color'] ) ) : ?>
							<div class="mds-provider-row mds-provider-row-saved">
								<input type="text" name="mds_rm_providers[name][]" value="<?php echo esc_attr( $provider['name'] ); ?>" placeholder="<?php esc_attr_e( 'Provider Name', 'mds-release-manager' ); ?>" />
								<div class="mds-icon-input-wrapper">
									<input type="text" name="mds_rm_providers[icon][]" value="<?php echo esc_attr( $provider['icon'] ); ?>" placeholder="fab fa-spotify" class="mds-icon-input" />
								</div>
								<input type="text" name="mds_rm_providers[color][]" value="<?php echo esc_attr( $provider['color'] ); ?>" class="mds-color-picker" />
								<button type="button" class="button mds-remove-provider"><?php esc_html_e( 'Remove', 'mds-release-manager' ); ?></button>
							</div>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>
				<button type="button" class="button button-secondary" id="mds_add_provider_btn"><?php esc_html_e( '+ Add Provider', 'mds-release-manager' ); ?></button>
				<hr />
				<h3><?php esc_html_e( 'Audio Preview Player', 'mds-release-manager' ); ?></h3>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Select Active Player(s)', 'mds-release-manager' ); ?></th>
						<td>
							<?php foreach ( $args['audio_players'] as $slug => $label ) : ?>
								<label class="mds-admin-option">
									<input type="checkbox" name="mds_rm_audio_players[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $args['active_players'], true ) ); ?> />
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mds_bandcamp_template"><?php esc_html_e( 'Bandcamp Shortcode Template', 'mds-release-manager' ); ?></label></th>
						<td>
							<input type="text" name="mds_rm_bandcamp_template" id="mds_bandcamp_template" value="<?php echo esc_attr( $args['settings']['bandcamp_template'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Only enabled if Blocks for Bandcamp is selected.', 'mds-release-manager' ); ?></p>
						</td>
					</tr>
					<?php if ( $args['audio_player_custom_fields'] ) : ?>
						<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The extension filter provides trusted settings markup. ?>
						<?php echo $args['audio_player_custom_fields']; ?>
					<?php endif; ?>
				</table>
			</div>

			<div class="mds-admin-save-actions">
				<button type="submit" class="button button-primary mds-save-settings-btn"><?php esc_html_e( 'Save Settings', 'mds-release-manager' ); ?></button>
				<span class="mds-save-status"><?php esc_html_e( 'Settings saved! Cache purged.', 'mds-release-manager' ); ?></span>
			</div>
		</div>

		<div id="tab-artist-discography" class="mds-tab-pane">
			<div class="mds-panel">
				<h2><?php esc_html_e( 'Artist Discography defaults', 'mds-release-manager' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Blocks inherit these defaults unless an individual block overrides them.', 'mds-release-manager' ); ?></p>
				<div id="mds-defaults-artist-discography"></div>
			</div>
			<div class="mds-admin-save-actions">
				<button type="submit" class="button button-primary mds-save-settings-btn"><?php esc_html_e( 'Save Settings', 'mds-release-manager' ); ?></button>
				<span class="mds-save-status" role="status"><?php esc_html_e( 'Settings saved! Cache purged.', 'mds-release-manager' ); ?></span>
			</div>
		</div>

		<div id="tab-integrations" class="mds-tab-pane">
			<div class="mds-panel">
				<h2><?php esc_html_e( 'Routing', 'mds-release-manager' ); ?></h2>
				<table class="form-table mds-admin-spaced-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Site-Wide Routing', 'mds-release-manager' ); ?></th>
						<td>
							<label><input type="checkbox" name="mds_rm_global_redirect" value="1" <?php checked( $args['settings']['global_redirect'] ); ?>> <?php esc_html_e( 'Enable Site-Wide Routing', 'mds-release-manager' ); ?></label>
							<p class="description"><?php esc_html_e( 'Redirect releases with a Buy Now URL. When enabled, the automatic Release fallback composition is disabled for all releases.', 'mds-release-manager' ); ?></p>
							<p class="description">
								<?php
								printf(
									/* translators: %s: the emphasized Buy Now Link field name. */
									esc_html__( "Redirect visitors directly to the release's configured %s.", 'mds-release-manager' ),
									'<em>' . esc_html__( 'Buy Now Link', 'mds-release-manager' ) . '</em>'
								);
								?>
							</p>
						</td>
					</tr>
				</table>
				<hr />
				<h2><?php esc_html_e( 'Network Synchronization', 'mds-release-manager' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Manually force a broadcast of all Artists and Releases from this Hub site to all configured Spoke sites. This is useful if a Spoke site was added recently or if sync was interrupted.', 'mds-release-manager' ); ?></p>
				<button type="button" class="button button-secondary" id="mds-run-manual-sync-btn"><?php esc_html_e( 'Run Manual Sync', 'mds-release-manager' ); ?></button>
				<span id="mds-manual-sync-status"></span>
				<hr />
				<h2><?php esc_html_e( 'Font-Awesome Integration', 'mds-release-manager' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Select which Font-Awesome source to use for the shortcode widgets.', 'mds-release-manager' ); ?></p>
				<select name="mds_rm_fa_support">
					<?php foreach ( $args['fa_sources'] as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $args['active_fa_source'], $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="mds-admin-save-actions">
				<button type="submit" class="button button-primary mds-save-settings-btn"><?php esc_html_e( 'Save Settings', 'mds-release-manager' ); ?></button>
				<span class="mds-save-status"><?php esc_html_e( 'Settings saved! Cache purged.', 'mds-release-manager' ); ?></span>
			</div>
		</div>
	</form>
	<?php foreach ( $args['extension_tabs'] as $slug => $tab ) : ?>
		<div id="tab-<?php echo esc_attr( $slug ); ?>" class="mds-tab-pane">
			<?php call_user_func( $tab['render'] ); ?>
		</div>
	<?php endforeach; ?>
</div>

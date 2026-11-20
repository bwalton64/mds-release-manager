<?php
declare(strict_types=1);

/**
 * Release Manager settings page.
 *
 * @package MDS_Release_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap mds-settings-wrap">
	<h1>Release Manager Settings</h1>

	<h2 class="nav-tab-wrapper" id="mds-settings-tabs">
		<a href="#tab-shortcode" class="nav-tab nav-tab-active">Release Data</a>
		<a href="#tab-integrations" class="nav-tab">Integrations</a>
	</h2>

	<form id="mds-settings-form" style="margin-top:20px;">
		<div id="tab-shortcode" class="mds-tab-pane active">
			<div class="mds-panel">
				<h3>Streaming Provider Defaults</h3>
				<p class="description">Configure streaming providers used in the release editor.</p>
				<div id="mds_providers_repeater">
					<?php foreach ( $args['providers'] as $provider ) : ?>
						<?php if ( isset( $provider['name'], $provider['icon'], $provider['color'] ) ) : ?>
							<div class="mds-provider-row" style="margin-bottom: 10px;">
								<input type="text" name="mds_rm_providers[name][]" value="<?php echo esc_attr( $provider['name'] ); ?>" placeholder="Provider Name" style="margin-right:10px;" />
								<div class="mds-icon-input-wrapper" style="display:inline-block; position:relative; margin-right:10px;">
									<input type="text" name="mds_rm_providers[icon][]" value="<?php echo esc_attr( $provider['icon'] ); ?>" placeholder="fab fa-spotify" class="mds-icon-input" />
								</div>
								<input type="text" name="mds_rm_providers[color][]" value="<?php echo esc_attr( $provider['color'] ); ?>" class="mds-color-picker" />
								<button type="button" class="button mds-remove-provider" style="margin-left:10px;">Remove</button>
							</div>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>
				<button type="button" class="button button-secondary" id="mds_add_provider_btn">+ Add Provider</button>
				<hr />
				<h3>Audio Preview Player</h3>
				<table class="form-table">
					<tr>
						<th scope="row">Select Active Player(s)</th>
						<td>
							<?php foreach ( $args['audio_players'] as $slug => $label ) : ?>
								<label style="display:block; margin-bottom:5px;">
									<input type="checkbox" name="mds_rm_audio_players[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $args['active_players'], true ) ); ?> />
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mds_bandcamp_template">Bandcamp Shortcode Template</label></th>
						<td>
							<input type="text" name="mds_rm_bandcamp_template" id="mds_bandcamp_template" value="<?php echo esc_attr( $args['settings']['bandcamp_template'] ); ?>" style="width:100%; max-width:600px;" />
							<p class="description">Only enabled if Blocks for Bandcamp is selected.</p>
						</td>
					</tr>
					<?php if ( $args['audio_player_custom_fields'] ) : ?>
						<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The extension filter provides trusted settings markup. ?>
						<?php echo $args['audio_player_custom_fields']; ?>
					<?php endif; ?>
				</table>
			</div>

			<div style="display:flex; align-items: center; margin-top:20px;">
				<button type="submit" class="button button-primary mds-save-settings-btn">Save Settings</button>
				<span class="mds-save-status">Settings saved! Cache purged.</span>
			</div>
		</div>

		<div id="tab-integrations" class="mds-tab-pane">
			<div class="mds-panel">
				<h2>Routing</h2>
				<table class="form-table" style="margin-bottom:20px;">
					<tr>
						<th scope="row">Site-Wide Routing</th>
						<td>
							<label><input type="checkbox" name="mds_rm_global_redirect" value="1" <?php checked( $args['settings']['global_redirect'] ); ?>> Enable Site-Wide Routing</label>
							<p class="description">Redirect releases with a Buy Now URL. When enabled, the automatic Release fallback composition is disabled for all releases.</p>
							<p class="description">Redirect visitors directly to the release's configured <em>Buy Now Link</em>.</p>
						</td>
					</tr>
				</table>
				<hr />
				<h2>Network Synchronization</h2>
				<p class="description">Manually force a broadcast of all Artists and Releases from this Hub site to all configured Spoke sites. This is useful if a Spoke site was added recently or if sync was interrupted.</p>
				<button type="button" class="button button-secondary" id="mds-run-manual-sync-btn">Run Manual Sync</button>
				<span id="mds-manual-sync-status" style="margin-left: 10px; font-weight: bold; color: #46b450; display: none;"></span>
				<hr />
				<h2>Font-Awesome Integration</h2>
				<p class="description">Select which Font-Awesome source to use for the shortcode widgets.</p>
				<select name="mds_rm_fa_support" style="min-width: 250px;">
					<?php foreach ( $args['fa_sources'] as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $args['active_fa_source'], $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div style="display:flex; align-items: center; margin-top:20px;">
				<button type="submit" class="button button-primary mds-save-settings-btn">Save Settings</button>
				<span class="mds-save-status">Settings saved! Cache purged.</span>
			</div>
		</div>
	</form>
</div>

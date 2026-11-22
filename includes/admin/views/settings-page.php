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
		<a href="#tab-general-settings" class="nav-tab nav-tab-active" aria-current="page"><?php esc_html_e( 'General Settings', 'mds-release-manager' ); ?></a>
		<a href="#tab-audio-player-manager" class="nav-tab"><?php esc_html_e( 'Audio Player Manager', 'mds-release-manager' ); ?></a>
		<a href="#tab-release-meta-block" class="nav-tab"><?php esc_html_e( 'Release Meta Block', 'mds-release-manager' ); ?></a>
		<a href="#tab-release-links-block" class="nav-tab"><?php esc_html_e( 'Release Links Block', 'mds-release-manager' ); ?></a>
		<a href="#tab-artist-discography-block" class="nav-tab"><?php esc_html_e( 'Artist Discography Block', 'mds-release-manager' ); ?></a>
		<?php foreach ( $args['extension_tabs'] as $slug => $tab ) : ?>
			<a href="#tab-<?php echo esc_attr( $slug ); ?>" class="nav-tab"><?php echo esc_html( $tab['label'] ); ?></a>
		<?php endforeach; ?>
	</h2>

	<form id="mds-settings-form">
		<input type="hidden" name="mds_rm_block_defaults" id="mds-rm-block-defaults" value="<?php echo esc_attr( wp_json_encode( $args['block_defaults'] ) ); ?>" />
		<input type="hidden" name="mds_rm_audio_player_settings" id="mds-rm-audio-player-settings" value="<?php echo esc_attr( wp_json_encode( $args['audio_player_settings'] ?? array() ) ); ?>" />
		<div id="mds-audio-player-fields">
			<?php foreach ( $args['active_players'] as $active_slug ) : ?>
				<input type="hidden" name="mds_rm_audio_players[]" value="<?php echo esc_attr( $active_slug ); ?>" />
			<?php endforeach; ?>
		</div>
		<div id="mds-provider-fields">
			<?php foreach ( $args['providers'] as $provider ) : ?>
				<?php if ( isset( $provider['name'] ) && is_string( $provider['name'] ) && '' !== $provider['name'] ) : ?>
					<input type="hidden" name="mds_rm_providers[name][]" value="<?php echo esc_attr( $provider['name'] ); ?>" />
					<input type="hidden" name="mds_rm_providers[icon][]" value="<?php echo esc_attr( $provider['icon'] ?? '' ); ?>" />
					<input type="hidden" name="mds_rm_providers[color][]" value="<?php echo esc_attr( $provider['color'] ?? '' ); ?>" />
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
		<div id="tab-release-meta-block" class="mds-tab-pane">
			<div class="mds-panel">
				<h2><?php esc_html_e( 'Release Meta defaults', 'mds-release-manager' ); ?></h2>
				<p class="description"><?php esc_html_e( 'These defaults apply to automatic fallback blocks and to inserted blocks that do not override them.', 'mds-release-manager' ); ?></p>
				<div id="mds-defaults-release-meta"></div>
			</div>
			<div class="mds-admin-save-actions">
				<button type="submit" class="button button-primary mds-save-settings-btn"><?php esc_html_e( 'Save Settings', 'mds-release-manager' ); ?></button>
				<span class="mds-save-status" role="status"><?php esc_html_e( 'Settings saved! Cache purged.', 'mds-release-manager' ); ?></span>
			</div>
		</div>
		<div id="tab-release-links-block" class="mds-tab-pane">
			<div class="mds-panel">
				<h2><?php esc_html_e( 'Release Links defaults', 'mds-release-manager' ); ?></h2>
				<p class="description"><?php esc_html_e( 'These defaults apply to automatic fallback blocks and to inserted blocks that do not override them.', 'mds-release-manager' ); ?></p>
				<div id="mds-defaults-release-links"></div>
			</div>

			<div class="mds-admin-save-actions">
				<button type="submit" class="button button-primary mds-save-settings-btn"><?php esc_html_e( 'Save Settings', 'mds-release-manager' ); ?></button>
				<span class="mds-save-status"><?php esc_html_e( 'Settings saved! Cache purged.', 'mds-release-manager' ); ?></span>
			</div>
		</div>

		<div id="tab-artist-discography-block" class="mds-tab-pane">
			<div class="mds-panel">
				<h2><?php esc_html_e( 'Artist Discography defaults', 'mds-release-manager' ); ?></h2>
				<p class="description"><?php esc_html_e( 'These defaults apply to automatic fallback blocks and to inserted blocks that do not override them.', 'mds-release-manager' ); ?></p>
				<div id="mds-defaults-artist-discography"></div>
			</div>
			<div class="mds-admin-save-actions">
				<button type="submit" class="button button-primary mds-save-settings-btn"><?php esc_html_e( 'Save Settings', 'mds-release-manager' ); ?></button>
				<span class="mds-save-status" role="status"><?php esc_html_e( 'Settings saved! Cache purged.', 'mds-release-manager' ); ?></span>
			</div>
		</div>

		<div id="tab-general-settings" class="mds-tab-pane active">
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

		<div id="tab-audio-player-manager" class="mds-tab-pane">
			<div class="mds-panel">
				<h2><?php esc_html_e( 'Audio Player Manager', 'mds-release-manager' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Select the active audio preview player displayed in the Release Links Block and [release-links] shortcode, and configure each registered player’s site-wide styling and options. When a third-party player is active, releases without third-party audio configured automatically fall back to the built-in HTML5 Player (.mp3).', 'mds-release-manager' ); ?></p>
				<div id="mds-audio-player-manager"></div>
				<?php if ( $args['audio_player_custom_fields'] ) : ?>
					<table class="form-table">
						<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The extension filter provides trusted settings markup. ?>
						<?php echo $args['audio_player_custom_fields']; ?>
					</table>
				<?php endif; ?>
			</div>

			<div class="mds-admin-save-actions">
				<button type="submit" class="button button-primary mds-save-settings-btn"><?php esc_html_e( 'Save Settings', 'mds-release-manager' ); ?></button>
				<span class="mds-save-status" role="status"><?php esc_html_e( 'Settings saved! Cache purged.', 'mds-release-manager' ); ?></span>
			</div>
		</div>
	</form>
	<?php foreach ( $args['extension_tabs'] as $slug => $tab ) : ?>
		<div id="tab-<?php echo esc_attr( $slug ); ?>" class="mds-tab-pane">
			<?php call_user_func( $tab['render'] ); ?>
		</div>
	<?php endforeach; ?>
</div>

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

( static function ( array $args ): void {
	?>
<div class="wrap mds-settings-wrap">
	<h1><?php esc_html_e( 'Release Manager Settings', 'mds-release-manager' ); ?></h1>

	<h2 class="nav-tab-wrapper" id="mds-settings-tabs">
		<a href="#tab-general-settings" class="nav-tab nav-tab-active" aria-current="page"><?php esc_html_e( 'General Settings', 'mds-release-manager' ); ?></a>
		<a href="#tab-default-templates" class="nav-tab"><?php esc_html_e( 'Default Templates', 'mds-release-manager' ); ?></a>
		<a href="#tab-audio-player-manager" class="nav-tab"><?php esc_html_e( 'Audio Player Manager', 'mds-release-manager' ); ?></a>
		<a href="#tab-block-defaults" class="nav-tab"><?php esc_html_e( 'Block Defaults', 'mds-release-manager' ); ?></a>
		<?php foreach ( $args['extension_tabs'] as $slug => $tab ) : ?>
			<a href="#tab-<?php echo esc_attr( $slug ); ?>" class="nav-tab"><?php echo esc_html( $tab['label'] ); ?></a>
		<?php endforeach; ?>
	</h2>

	<form id="mds-settings-form">
		<input type="hidden" name="mds_rm_block_defaults" id="mds-rm-block-defaults" value="<?php echo esc_attr( wp_json_encode( $args['block_defaults'] ) ); ?>" />
		<input type="hidden" name="mds_rm_template_defaults" id="mds-rm-template-defaults" value="<?php echo esc_attr( wp_json_encode( $args['template_defaults'] ) ); ?>" />
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
		<div id="tab-block-defaults" class="mds-tab-pane">
			<div class="mds-panel">
				<h2><?php esc_html_e( 'Block Defaults', 'mds-release-manager' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Configure site-wide presentation and behavior defaults for Release Meta, Release Links, and Artist Discography. These defaults apply to automatic fallback blocks and to inserted blocks that do not override them.', 'mds-release-manager' ); ?></p>
				<div id="mds-block-defaults-root"></div>
			</div>
			<div class="mds-admin-save-actions">
				<button type="submit" class="button button-primary mds-save-settings-btn"><?php esc_html_e( 'Save Settings', 'mds-release-manager' ); ?></button>
				<span class="mds-save-status" role="status" aria-live="polite"><?php esc_html_e( 'Settings saved! Cache purged.', 'mds-release-manager' ); ?></span>
			</div>
		</div>

		<div id="tab-general-settings" class="mds-tab-pane active">
			<div class="mds-panel">
				<h2><?php esc_html_e( 'Cache Management', 'mds-release-manager' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Automatically purge page and CSS/JS caches after styling updates, syncs, and companion plugin changes (Bandcamp, WooCommerce). Purges are coalesced to run once per site at the end of a request.', 'mds-release-manager' ); ?></p>
				<input type="hidden" name="mds_rm_cache_present" value="1" />
				<?php if ( ! empty( $args['detected_cache_adapters'] ) ) : ?>
					<table class="widefat striped" style="max-width:760px;margin:12px 0;">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Detected Cache Plugin', 'mds-release-manager' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Tier', 'mds-release-manager' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Purge Behavior', 'mds-release-manager' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $args['detected_cache_adapters'] as $slug => $adapter ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $adapter['label'] ); ?></strong></td>
									<td><?php echo esc_html( $adapter['tier'] ); ?></td>
									<td>
										<label for="mds-cache-mode-<?php echo esc_attr( $slug ); ?>" class="screen-reader-text">
											<?php
											/* translators: %s: Cache plugin name. */
											echo esc_html( sprintf( __( 'Purge behavior for %s', 'mds-release-manager' ), $adapter['label'] ) );
											?>
										</label>
										<select id="mds-cache-mode-<?php echo esc_attr( $slug ); ?>" name="mds_rm_cache_plugins[<?php echo esc_attr( $slug ); ?>]">
											<?php foreach ( $adapter['modes'] as $mode_key => $mode_label ) : ?>
												<option value="<?php echo esc_attr( $mode_key ); ?>" <?php selected( $adapter['active_mode'], $mode_key ); ?>><?php echo esc_html( $mode_label ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p class="description"><em><?php esc_html_e( 'No supported cache plugin is currently active on this site (supports LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Fastest Cache, SiteGround Optimizer, WP Super Cache, Autoptimize, FlyingPress, and Breeze).', 'mds-release-manager' ); ?></em></p>
				<?php endif; ?>
				<p>
					<label for="mds-rm-cache-purge-on-post-save">
						<input type="checkbox" id="mds-rm-cache-purge-on-post-save" name="mds_rm_cache_purge_on_post_save" value="1" <?php checked( ! empty( $args['cache_settings']['purge_on_post_save'] ) ); ?> />
						<?php esc_html_e( 'Purge cache when individual Releases or Artists are saved', 'mds-release-manager' ); ?>
					</label>
				</p>
				<p class="description"><?php esc_html_e( 'Uncheck if your cache plugin already auto-purges on post save. Site-wide styling changes, Default Templates, and Hub-to-Spoke syncs will still trigger a purge.', 'mds-release-manager' ); ?></p>
				<p style="margin-top:12px;">
					<button type="button" class="button button-secondary" id="mds-purge-cache-now-btn"><?php esc_html_e( 'Purge Cache Now', 'mds-release-manager' ); ?></button>
					<span id="mds-purge-cache-status" role="status" style="margin-left:10px;font-weight:600;"></span>
				</p>
				<hr />
				<h2><?php esc_html_e( 'Network Synchronization', 'mds-release-manager' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Manually force a broadcast of all Artists and Releases from this Hub site to all configured Spoke sites. This is useful if a Spoke site was added recently or if sync was interrupted.', 'mds-release-manager' ); ?></p>
				<button type="button" class="button button-secondary" id="mds-run-manual-sync-btn"><?php esc_html_e( 'Run Manual Sync', 'mds-release-manager' ); ?></button>
				<span id="mds-manual-sync-status"></span>
				<hr />
				<h2><?php esc_html_e( 'Font Awesome Integration', 'mds-release-manager' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Choose how Font Awesome icons are loaded for Release Meta and Release Links. Auto mode defers to an installed Font Awesome plugin when detected, and otherwise loads Font Awesome Free 6.7.2 from cdnjs with Subresource Integrity (SRI).', 'mds-release-manager' ); ?></p>
				<label for="mds-rm-fa-support" class="screen-reader-text"><?php esc_html_e( 'Font Awesome source', 'mds-release-manager' ); ?></label>
				<select id="mds-rm-fa-support" name="mds_rm_fa_support">
					<?php foreach ( $args['fa_sources'] as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $args['active_fa_source'], $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description">
					<?php if ( ! empty( $args['detected_fa_plugin'] ) ) : ?>
						<?php
						printf(
							/* translators: %s: Detected Font Awesome plugin or stylesheet name. */
							esc_html__( 'Status: Detected %s. Auto mode will use this provider and skip the public CDN.', 'mds-release-manager' ),
							'<strong>' . esc_html( $args['detected_fa_plugin'] ) . '</strong>'
						);
						?>
					<?php else : ?>
						<?php esc_html_e( 'Status: No Font Awesome plugin detected. Auto mode will load Font Awesome Free 6.7.2 from cdnjs.', 'mds-release-manager' ); ?>
					<?php endif; ?>
				</p>
			</div>

			<div class="mds-admin-save-actions">
				<button type="submit" class="button button-primary mds-save-settings-btn"><?php esc_html_e( 'Save Settings', 'mds-release-manager' ); ?></button>
				<span class="mds-save-status" role="status" aria-live="polite"><?php esc_html_e( 'Settings saved! Cache purged.', 'mds-release-manager' ); ?></span>
			</div>
		</div>

		<div id="tab-default-templates" class="mds-tab-pane">
			<div class="mds-panel mds-panel--wide">
				<h2><?php esc_html_e( 'Default Fallback Templates', 'mds-release-manager' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Customize the automatic page layouts rendered for Release and Artist pages when no page builder or custom redirect is active. Settings here are theme-independent and survive theme switches.', 'mds-release-manager' ); ?></p>
				<div id="mds-default-templates-root"></div>
			</div>
			<div class="mds-admin-save-actions">
				<button type="submit" class="button button-primary mds-save-settings-btn"><?php esc_html_e( 'Save Settings', 'mds-release-manager' ); ?></button>
				<span class="mds-save-status" role="status"><?php esc_html_e( 'Settings saved! Cache purged.', 'mds-release-manager' ); ?></span>
			</div>
		</div>

		<div id="tab-audio-player-manager" class="mds-tab-pane">
			<div class="mds-panel">
				<h2><?php esc_html_e( 'Audio Player Manager', 'mds-release-manager' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Select the active audio preview player displayed in the Release Links Block and [release-links] shortcode, and configure each registered player’s site-wide styling and options. When a third-party player is active, releases without third-party audio configured automatically fall back to the built-in HTML5 Player (.mp3).', 'mds-release-manager' ); ?></p>
				<div id="mds-audio-player-manager"></div>
			</div>

			<div class="mds-admin-save-actions">
				<button type="submit" class="button button-primary mds-save-settings-btn"><?php esc_html_e( 'Save Settings', 'mds-release-manager' ); ?></button>
				<span class="mds-save-status" role="status"><?php esc_html_e( 'Settings saved! Cache purged.', 'mds-release-manager' ); ?></span>
			</div>
		</div>
	</form>
	<?php foreach ( $args['extension_tabs'] as $slug => $tab ) : ?>
		<div id="tab-<?php echo esc_attr( $slug ); ?>" class="mds-tab-pane">
			<?php
			try {
				call_user_func( $tab['render'] );
			} catch ( \Throwable $e ) {
				\MDS\ReleaseManager\Core\Logger::report_failure(
					sprintf( 'Settings tab "%s" failed to render: %s', $slug, $e->getMessage() )
				);
				echo '<div class="notice notice-error inline"><p>' . esc_html__( 'This extension settings tab encountered an error and could not be rendered. Check the error log for details.', 'mds-release-manager' ) . '</p></div>';
			}
			?>
		</div>
	<?php endforeach; ?>
</div>
	<?php
} )( is_array( $args ?? null ) ? $args : array() );

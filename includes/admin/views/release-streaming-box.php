<?php
/**
 * Release streaming and audio meta box.
 *
 * @package MDS_Release_Manager
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

( static function ( array $args ): void {
	ob_start();
	?>
<option value=""><?php esc_html_e( 'Select Provider', 'mds-release-manager' ); ?></option>
	<?php foreach ( $args['providers'] as $provider_name ) : ?>
	<option value="<?php echo esc_attr( $provider_name ); ?>"><?php echo esc_html( $provider_name ); ?></option>
<?php endforeach; ?>
	<?php
	$global_provider_options = ob_get_clean();
	wp_add_inline_script(
		'mds-meta-boxes-js',
		'var mds_global_providers_html = ' . wp_json_encode( $global_provider_options, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';',
		'before'
	);
	?>
<div class="mds-meta-section">
	<div id="mds_streaming_links_wrapper">
		<?php foreach ( $args['streaming_links'] as $provider => $url ) : ?>
			<div class="mds-streaming-row">
				<select name="mds_streaming_provider[]">
					<option value=""><?php esc_html_e( 'Select Provider', 'mds-release-manager' ); ?></option>
					<?php foreach ( $args['providers'] as $provider_name ) : ?>
						<option value="<?php echo esc_attr( $provider_name ); ?>" <?php selected( $provider, $provider_name ); ?>><?php echo esc_html( $provider_name ); ?></option>
					<?php endforeach; ?>
					<?php if ( $provider && ! in_array( $provider, $args['providers'], true ) ) : ?>
						<option value="<?php echo esc_attr( $provider ); ?>" selected="selected"><?php echo esc_html( $provider ); ?></option>
					<?php endif; ?>
				</select>
				<input type="url" name="mds_streaming_url[]" value="<?php echo esc_url( $url ); ?>" placeholder="https://..." />
				<button type="button" class="button mds-remove-row"><?php esc_html_e( 'Remove', 'mds-release-manager' ); ?></button>
			</div>
		<?php endforeach; ?>
	</div>
	<button type="button" class="button button-primary" id="mds_add_streaming_row"><?php esc_html_e( '+ Add Link', 'mds-release-manager' ); ?></button>

	<table class="mds-meta-table">
		<tr>
			<th><label for="audio_preview_url"><?php esc_html_e( 'Audio Preview URL (.mp3)', 'mds-release-manager' ); ?></label></th>
			<td>
				<input type="url" id="audio_preview_url" name="audio_preview_url" value="<?php echo esc_url( $args['audio_preview_url'] ); ?>" />
				<button type="button" class="button" id="mds_upload_audio_btn"><?php esc_html_e( 'Select Audio', 'mds-release-manager' ); ?></button>
			</td>
		</tr>
	</table>
</div>
	<?php
} )( is_array( $args ?? null ) ? $args : array() );

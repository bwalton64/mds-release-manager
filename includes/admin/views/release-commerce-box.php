<?php
/**
 * Release commerce meta box.
 *
 * @package MDS_Release_Manager
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="mds-meta-section">
	<table class="mds-meta-table mds-admin-spaced-table">
		<tr>
			<th><label for="buy_now_link"><?php esc_html_e( 'Buy Now URL', 'mds-release-manager' ); ?></label></th>
			<td>
				<div class="mds-buy-now-field">
					<input type="url" id="buy_now_link" name="buy_now_link" value="<?php echo esc_url( $args['buy_now_link'] ); ?>" />
					<?php do_action( 'mds_rm_after_buy_now_field', $args['post'] ); ?>
				</div>
			</td>
		</tr>
	</table>
</div>

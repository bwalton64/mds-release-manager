<?php
/**
 * Release details meta box: catalog metadata and tags.
 *
 * @package MDS_Release_Manager
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="mds-meta-section">
	<table class="mds-meta-table">
		<tr>
			<th><label for="record_label"><?php esc_html_e( 'Record Label', 'mds-release-manager' ); ?></label></th>
			<td><input type="text" id="record_label" name="record_label" value="<?php echo esc_attr( $args['record_label'] ); ?>" /></td>
		</tr>
		<tr>
			<th><label for="distributor"><?php esc_html_e( 'Distributor', 'mds-release-manager' ); ?></label></th>
			<td><input type="text" id="distributor" name="distributor" value="<?php echo esc_attr( $args['distributor'] ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Release Tags', 'mds-release-manager' ); ?></th>
			<td>
				<?php post_tags_meta_box( $args['post'], array( 'args' => array( 'taxonomy' => 'release_tag' ) ) ); ?>
			</td>
		</tr>
	</table>
</div>

<?php
/**
 * Display blocks for releases: meta, links, and discography.
 *
 * @package MDS_Release_Manager
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MDS_RM_Release_Meta {
	public static function fields() {
		return array(
			'release_year'   => array( 'label' => 'Release Year', 'icon' => 'fas fa-calendar', 'enabled' => true ),
			'record_label'   => array( 'label' => 'Record Label', 'icon' => 'fas fa-compact-disc', 'enabled' => true ),
			'distributor'    => array( 'label' => 'Distributor', 'icon' => 'fas fa-tower-broadcast', 'enabled' => true ),
			'upc_ean'        => array( 'label' => 'UPC/EAN', 'icon' => 'fas fa-barcode', 'enabled' => true ),
			'release_format' => array( 'label' => 'Release Format', 'icon' => 'dashicons dashicons-album', 'enabled' => false ),
			'release_date'   => array( 'label' => 'Full Release Date', 'icon' => 'fas fa-calendar', 'enabled' => false ),
			'release_title'  => array( 'label' => 'Release Title', 'icon' => 'dashicons dashicons-album', 'enabled' => false ),
			'artist'         => array( 'label' => 'Artist', 'icon' => 'dashicons dashicons-groups', 'enabled' => false ),
			'release_tags'   => array( 'label' => 'Release Tags', 'icon' => 'dashicons dashicons-tag', 'enabled' => false ),
		);
	}

	public static function default_items() {
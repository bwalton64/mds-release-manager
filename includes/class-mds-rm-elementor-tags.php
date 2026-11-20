<?php
declare(strict_types=1);

/**
 * Elementor integration for release manager tags and widgets.
 *
 * @package MDS_Release_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers Elementor support, dynamic tags, and widgets.
 *
 * @since 0.9.0
 */
final class MDS_RM_Elementor_Tags {

	/**
	 * Registers Elementor integration hooks.
	 *
	 * @since 0.9.0
	 */
	public static function register_hooks() {
		add_action( 'init', array( self::class, 'add_elementor_support_artist' ), 99 );
		add_action( 'elementor/dynamic_tags/register', array( self::class, 'register_elementor_tags' ) );
		add_action( 'elementor/widgets/register', array( self::class, 'register_elementor_widgets' ) );
	}

	/**
	 * Enables Elementor support for artist posts.
	 *
	 * @since 0.9.0
	 */
	public static function add_elementor_support_artist() {
		add_post_type_support( 'artist', 'elementor' );
	}

	/**
	 * Registers the release metadata dynamic tag when Elementor is available.
	 *
	 * @since 0.9.0
	 * @param object $dynamic_tags_manager Elementor's dynamic tags manager.
	 */
	public static function register_elementor_tags( $dynamic_tags_manager ) {
		if ( ! class_exists( '\Elementor\Core\DynamicTags\Data_Tag' ) ) {
			return;
		}

		$dynamic_tags_manager->register( new MDS_RM_Elementor_Release_Meta_Tag() );
	}

	/**
	 * Registers the release widget when Elementor is available.
	 *
	 * @since 0.9.0
	 * @param object $widgets_manager Elementor's widgets manager.
	 */
	public static function register_elementor_widgets( $widgets_manager ) {
		if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}

		$widgets_manager->register( new MDS_RM_Elementor_Widget() );
		$widgets_manager->register( new MDS_RM_Elementor_Links_Widget() );
	}
}

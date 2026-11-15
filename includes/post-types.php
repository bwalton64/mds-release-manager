<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'init', 'mds_register_cpts' );
function mds_register_cpts() {
    register_post_type( 'release', array(
        'labels'       => array(
            'name'          => 'Releases',
            'singular_name' => 'Release',
            'add_new'       => 'Add Release',
            'add_new_item'  => 'Add New Release',
            'edit_item'     => 'Edit Release',
            'new_item'      => 'New Release',
            'all_items'     => 'All Releases',
            'menu_name'     => 'Releases',
        ),
        'public'       => true,
        'has_archive'  => true,
        'show_in_rest' => true,
        'supports'     => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
        'menu_icon'    => 'dashicons-album',
        'rewrite'      => array( 'slug' => 'release' ),
    ) );

    register_post_type( 'artist', array(
        'labels'       => array(
            'name'          => 'Artists',
            'singular_name' => 'Artist',
            'add_new'       => 'Add Artist',
            'add_new_item'  => 'Add New Artist',
            'edit_item'     => 'Edit Artist',
            'new_item'      => 'New Artist',
            'all_items'     => 'All Artists',
            'menu_name'     => 'Artists',
        ),
        'public'       => true,
        'has_archive'  => true,
        'show_in_rest' => true,
        'show_in_menu' => 'edit.php?post_type=release',
        'supports'     => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
        'menu_icon'    => 'dashicons-groups',
        'rewrite'      => array( 'slug' => 'artist' ),
    ) );

    register_taxonomy( 'release_tag', 'release', array(
        'label'             => 'Release Tags',
        'hierarchical'      => false,
        'show_admin_column' => true,
        'show_in_rest'      => true,
        'meta_box_cb'       => false,
        'rewrite'           => array( 'slug' => 'release-tag' ),
    ) );

    register_taxonomy( 'release_format', 'release', array(
        'labels'            => array(
            'name'          => 'Release Formats',
            'singular_name' => 'Release Format',
            'menu_name'     => 'Release Formats',
        ),
        'hierarchical'      => true,
        'show_admin_column' => true,
        'show_ui'           => true,
        'show_in_rest'      => true,
        'meta_box_cb'       => false,
        'rewrite'           => array( 'slug' => 'release-format' ),
    ) );
}

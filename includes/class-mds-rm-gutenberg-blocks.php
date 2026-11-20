<?php
declare(strict_types=1);

/**
 * Registers Gutenberg blocks and renders release metadata in core blocks.
 *
 * @package MDS_Release_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers custom blocks and renders release metadata in core blocks.
 *
 * @since 0.9.0
 */
final class MDS_RM_Gutenberg_Blocks {

	/**
	 * Registers the block editor, block type, and render hooks.
	 *
	 * @since 0.9.0
	 */
	public static function register_hooks() {
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue_block_editor_assets' ) );
		add_action( 'init', array( self::class, 'register_gutenberg_widget_blocks' ) );
		add_filter( 'render_block', array( self::class, 'render_core_block_with_meta' ), 10, 2 );
	}

	/**
	 * Enqueues the block editor script and styles.
	 *
	 * @since 0.9.0
	 */
	public static function enqueue_block_editor_assets() {
		$script_path = MDS_RM_PATH . 'js/blocks.js';

		wp_register_script(
			'mds-rm-blocks-js',
			MDS_RM_URL . 'js/blocks.js',
			array( 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-compose', 'wp-hooks', 'wp-server-side-render', 'wp-block-editor', 'wp-i18n', 'wp-data' ),
			filemtime( $script_path ),
			true
		);
		wp_enqueue_script( 'mds-rm-blocks-js' );
		wp_localize_script(
			'mds-rm-blocks-js',
			'mdsReleaseMeta',
			array(
				'items'  => MDS_RM_Release_Meta::default_items(),
				'fields' => MDS_RM_Release_Meta::fields(),
				'styles' => MDS_RM_Release_Meta::style_fields(),
			)
		);
		wp_localize_script(
			'mds-rm-blocks-js',
			'mdsReleaseLinks',
			array(
				'providers' => MDS_RM_Release_Links::providers(),
				'styles'    => MDS_RM_Release_Links::style_fields(),
			)
		);

		wp_add_inline_style(
			'wp-block-editor',
			'
        .mds-dynamic-block-active { position: relative; border: 1px dashed #2271b1; padding: 2px; }
        .mds-dynamic-block-active::after { 
            content: "Dynamic: MDS"; position: absolute; top: -10px; right: 0; 
            background: #2271b1; color: #fff; font-size: 10px; padding: 2px 6px; 
            border-radius: 3px; z-index: 10; pointer-events: none;
        }
    '
		);
	}

	/**
	 * Registers the Release Meta Block.
	 *
	 * @since 0.9.0
	 */
	public static function register_gutenberg_widget_blocks() {
		register_block_type(
			'mds-rm/artist-discography',
			array(
				'api_version'     => 3,
				'render_callback' => array( MDS_RM_Discography::class, 'render_block' ),
				'uses_context'    => array( 'postId', 'postType' ),
				'attributes'      => array(
					'artistId' => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
			)
		);
		register_block_type(
			'mds-rm/release-meta',
			array(
				'api_version'     => 3,
				'render_callback' => array( self::class, 'render_gutenberg_release_meta' ),
				'uses_context'    => array( 'postId', 'postType' ),
				'attributes'      => array(
					'upcOverride' => array(
						'type'    => 'string',
						'default' => '',
					),
					'items'       => array(
						'type'    => 'array',
						'default' => MDS_RM_Release_Meta::default_items(),
						'items'   => array(
							'type'       => 'object',
							'properties' => array(
								'field'   => array( 'type' => 'string' ),
								'enabled' => array( 'type' => 'boolean' ),
								'icon'    => array( 'type' => 'string' ),
								'title'   => array( 'type' => 'string' ),
							),
						),
					),
					'styles'      => array(
						'type'                 => 'object',
						'default'              => (object) array(),
						'additionalProperties' => true,
					),
				),
			)
		);
		register_block_type(
			'mds-rm/release-links',
			array(
				'api_version'     => 3,
				'render_callback' => array( self::class, 'render_gutenberg_release_links' ),
				'uses_context'    => array( 'postId', 'postType' ),
				'attributes'      => array(
					'upcOverride' => array(
						'type'    => 'string',
						'default' => '',
					),
					'items'       => array(
						'type'    => 'array',
						'default' => array(),
						'items'   => array(
							'type'                 => 'object',
							'additionalProperties' => true,
						),
					),
					'newTab'      => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'showAudio'   => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'styles'      => array(
						'type'                 => 'object',
						'default'              => (object) array(),
						'additionalProperties' => true,
					),
				),
			)
		);
	}

	public static function render_gutenberg_release_links( $attributes, $_content, $block ) {
		$context_post_id      = isset( $block->context['postId'] ) ? $block->context['postId'] : get_the_ID();
		$attributes['upc']    = isset( $attributes['upcOverride'] ) ? $attributes['upcOverride'] : '';
		$content              = MDS_RM_Release_Links::render( $attributes, $context_post_id );
		return $content ? '<div ' . get_block_wrapper_attributes() . '>' . $content . '</div>' : '';
	}

	/**
	 * Renders the Release Meta Block.
	 *
	 * @since 0.9.0
	 * @param array    $attributes Block attributes.
	 * @param string   $_content   Block content required by the render callback.
	 * @param WP_Block $block      The parsed block.
	 * @return string The rendered metadata.
	 */
	public static function render_gutenberg_release_meta( $attributes, $_content, $block ) {
		$context_post_id      = isset( $block->context['postId'] ) ? $block->context['postId'] : get_the_ID();
		$attributes['upc']    = isset( $attributes['upcOverride'] ) ? $attributes['upcOverride'] : '';
		$content              = MDS_RM_Release_Meta::render( $attributes, $context_post_id );
		return $content ? '<div ' . get_block_wrapper_attributes() . '>' . $content . '</div>' : '';
	}

	/**
	 * Replaces configured release metadata in supported core blocks.
	 *
	 * @since 0.9.0
	 * @param string $block_content The rendered block content.
	 * @param array  $block         The parsed block data.
	 * @return string The updated block content.
	 */
	public static function render_core_block_with_meta( $block_content, $block ) {
		if ( empty( $block['attrs']['mdsMetaField'] ) ) {
			return $block_content;
		}

		$meta_field   = $block['attrs']['mdsMetaField'];
		$upc_override = $block['attrs']['mdsUpcOverride'] ?? '';

		$post_id = isset( $block['context']['postId'] ) ? $block['context']['postId'] : get_the_ID();

		if ( ! empty( $upc_override ) ) {
			$args  = array(
				'post_type'   => 'release',
				'meta_key'    => 'upc_ean',
				'meta_value'  => preg_replace( '/[^0-9]/', '', $upc_override ),
				'post_status' => 'any',
				'numberposts' => 1,
			);
			$found = get_posts( $args );
			if ( empty( $found ) ) {
				return '';
			}

			$post_id = $found[0]->ID;
		}
		if ( ! $post_id ) {
			return '';
		}

		// Fetch the value.
		$val = '';
		if ( 0 === strpos( $meta_field, 'stream_' ) ) {
			$provider = str_replace( 'stream_', '', $meta_field );
			$json     = get_post_meta( $post_id, 'streaming_links', true );
			$links    = json_decode( $json, true );
			$val      = isset( $links[ $provider ] ) ? $links[ $provider ] : '';
		} elseif ( 'featured_image_url' === $meta_field || 'buy_now_link' === $meta_field ) {
			$val = get_post_meta( $post_id, $meta_field, true );
		} elseif ( 'release_format' === $meta_field ) {
			$val = MDS_RM_Release_Model::mds_rm_format_name( $post_id );
			if ( is_wp_error( $val ) ) {
				error_log( '[MDS Release Manager] Release ' . $post_id . ': ' . $val->get_error_message() );
				return '';
			}
		} elseif ( 'release_tags' === $meta_field ) {
			$val = get_post_meta( $post_id, 'release_tags', true );
			if ( ! $val ) {
				$terms = wp_get_post_terms( $post_id, 'release_tag', array( 'fields' => 'names' ) );
				$val   = ! is_wp_error( $terms ) && ! empty( $terms ) ? implode( ', ', $terms ) : '';
			}
		} elseif ( 'release_year' === $meta_field ) {
			$date = get_post_field( 'post_date', $post_id );
			$val  = ( ! empty( $date ) && '0000-00-00 00:00:00' !== $date ) ? substr( $date, 0, 4 ) : '';
		} else {
			$val = get_post_meta( $post_id, $meta_field, true );
			if ( ! $val ) {
				$val = '';
			}
		}

		if ( empty( $val ) ) {
			return '';
		}

		$block_name = $block['blockName'];

		// Use WP_HTML_Tag_Processor for links and images when available.
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $block_content;
		}

		$tags = new WP_HTML_Tag_Processor( $block_content );
		if ( 'core/button' === $block_name ) {
			if ( ! $tags->next_tag( 'a' ) ) {
				return $block_content;
			}

			$tags->set_attribute( 'href', esc_url( $val ) );
			return $tags->get_updated_html();
		}

		if ( 'core/image' === $block_name ) {
			if ( ! $tags->next_tag( 'img' ) ) {
				return $block_content;
			}

			$tags->set_attribute( 'src', esc_url( $val ) );
			$tags->remove_attribute( 'srcset' );
			return $tags->get_updated_html();
		}

		// Replace text block contents because Tag_Processor does not expose inner HTML.
		if ( 'core/paragraph' === $block_name ) {
			return preg_replace( '/(<p[^>]*>)(.*?)(<\/p>)/is', '$1' . esc_html( $val ) . '$3', $block_content );
		}

		if ( 'core/heading' === $block_name ) {
			return preg_replace( '/(<h[1-6][^>]*>)(.*?)(<\/h[1-6]>)/is', '$1' . esc_html( $val ) . '$3', $block_content );
		}

		return $block_content;
	}
}

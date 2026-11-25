<?php
/**
 * Registers Gutenberg blocks and renders release metadata in core blocks.
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers custom blocks and renders release metadata in core blocks.
 *
 * @since 0.9.0
 */
final class GutenbergBlocks {

	/**
	 * Registers the block editor, block type, and render hooks.
	 *
	 * @since 0.9.0
	 */
	public static function register_hooks(): void {
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue_block_editor_assets' ) );
		add_action( 'init', array( self::class, 'register_gutenberg_widget_blocks' ) );
		add_action( 'rest_api_init', array( ReleaseLinksBlock::class, 'register_routes' ) );
		add_filter( 'render_block', array( self::class, 'render_core_block_with_meta' ), 10, 2 );
		add_filter( 'block_categories_all', array( self::class, 'register_block_category' ) );
	}

	/**
	 * Adds the shared MDS Release Manager block category to the inserter.
	 *
	 * Extension plugins place blocks in this section by setting the
	 * `mds-release-manager` category in their block.json and registering the
	 * category the same way: append it on `block_categories_all` only when no
	 * category with this slug exists yet, so any number of MDS plugins can be
	 * active in any combination without duplicating the section.
	 *
	 * @param array $categories Existing block categories.
	 * @return array Categories including the MDS Release Manager category.
	 */
	public static function register_block_category( array $categories ): array {
		foreach ( $categories as $category ) {
			if ( isset( $category['slug'] ) && 'mds-release-manager' === $category['slug'] ) {
				return $categories;
			}
		}

		$categories[] = array(
			'slug'  => 'mds-release-manager',
			'title' => __( 'MDS Release Manager', 'mds-release-manager' ),
			'icon'  => 'album',
		);

		return $categories;
	}

	/**
	 * Enqueues the block editor script and styles.
	 *
	 * @since 0.9.0
	 */
	public static function enqueue_block_editor_assets(): void {
		$script_path = MDS_RM_PATH . 'blocks/core-block-extensions.js';

		wp_register_script(
			'mds-rm-blocks-js',
			MDS_RM_URL . 'blocks/core-block-extensions.js',
			array( 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-compose', 'wp-hooks', 'wp-server-side-render', 'wp-block-editor', 'wp-i18n', 'wp-data' ),
			filemtime( $script_path ),
			true
		);
		wp_enqueue_script( 'mds-rm-blocks-js' );
		$admin_css = 'css/admin-styles.css';
		wp_enqueue_style( 'mds-admin-view-styles', MDS_RM_URL . $admin_css, array(), filemtime( MDS_RM_PATH . $admin_css ) );
		\MDS\ReleaseManager\Frontend\Assets::enqueue_font_awesome();
		wp_add_inline_script(
			'mds-rm-release-meta-editor',
			'var mdsReleaseMeta = ' . wp_json_encode(
				array(
					'items'    => ReleaseMeta::default_items(),
					'fields'   => ReleaseMeta::fields(),
					'defaults' => BlockDefaults::get( 'release-meta' ),
				)
			) . ';',
			'before'
		);
		wp_add_inline_script(
			'mds-rm-release-links-editor',
			'var mdsReleaseLinks = ' . wp_json_encode(
				array(
					'providers' => ReleaseLinks::providers(),
					'defaults'  => BlockDefaults::get( 'release-links' ),
				)
			) . ';',
			'before'
		);
		wp_add_inline_script(
			'mds-rm-artist-discography-editor',
			'var mdsDiscography = ' . wp_json_encode( BlockDefaults::get( 'artist-discography' ) ) . ';',
			'before'
		);
		wp_add_inline_script(
			'mds-rm-release-player-editor',
			'window.mdsReleasePlayer = ' . wp_json_encode(
				array(
					'providers' => \MDS\ReleaseManager\Core\Audio\AudioApi::get_provider_descriptors(),
					'defaults'  => BlockDefaults::get( 'release-player' ),
				)
			) . ';',
			'before'
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
        .mds-section-info { display: flex; gap: 8px; align-items: flex-start; padding: 10px 12px; margin: 0 0 16px; background: #f0f6fc; border: 1px solid #c5d9ed; border-left: 3px solid #2271b1; border-radius: 3px; }
        .mds-section-info__icon { color: #2271b1; margin-top: 1px; flex-shrink: 0; }
        .mds-section-info__title { display: block; font-size: 12px; color: #1e1e1e; margin-bottom: 3px; }
        .mds-section-info__summary, .mds-section-info__inheritance { margin: 0; font-size: 12px; line-height: 1.45; color: #3c434a; }
        .mds-section-info__inheritance { margin-top: 5px; font-style: italic; color: #50575e; }
        .mds-control-group { padding: 12px; margin: 0 0 16px; background: #fcfcfc; border: 1px solid #e2e4e7; border-radius: 4px; }
        .mds-control-group__title { margin: 0 0 4px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; color: #1e1e1e; }
        .mds-control-group__description { margin: 0 0 12px; font-size: 12px; color: #646970; }
        .mds-control-group__fields { display: flex; flex-direction: column; gap: 14px; }
        .mds-style-grid-2col { display: grid; grid-template-columns: 1fr; gap: 14px; }
        .mds-style-field { display: flex; flex-direction: column; gap: 6px; }
        .mds-style-field .components-base-control { margin-bottom: 0; }
        .mds-style-field--paired, .mds-style-field--color { padding: 10px; background: #fff; border: 1px solid #e6e8ea; border-radius: 3px; gap: 8px; }
        .mds-style-field__label { font-size: 11px; font-weight: 600; text-transform: uppercase; color: #1e1e1e; }
        .mds-style-field__help { margin: 2px 0 0; font-size: 11.5px; line-height: 1.4; color: #646970; }
        .mds-site-default-btn { align-self: flex-start; font-size: 12px; }
    '
		);
	}

	/**
	 * Registers the plugin's dynamic blocks.
	 *
	 * @since 0.9.0
	 */
	public static function register_gutenberg_widget_blocks(): void {
		$color_control = 'blocks/color-control.js';
		wp_register_script(
			'mds-rm-color-control',
			MDS_RM_URL . $color_control,
			array( 'wp-element', 'wp-components', 'wp-i18n' ),
			filemtime( MDS_RM_PATH . $color_control ),
			true
		);
		wp_set_script_translations( 'mds-rm-color-control', 'mds-release-manager', MDS_RM_PATH . 'languages' );
		foreach (
			array(
				'release-picker'        => array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-url', 'wp-html-entities', 'wp-i18n' ),
				'release-items'         => array(),
				'native-release-styles' => array( 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n', 'mds-rm-color-control' ),
			) as $script => $dependencies
		) {
			$file = "blocks/{$script}.js";
			wp_register_script( "mds-rm-{$script}", MDS_RM_URL . $file, $dependencies, filemtime( MDS_RM_PATH . $file ), true );
			wp_set_script_translations( "mds-rm-{$script}", 'mds-release-manager', MDS_RM_PATH . 'languages' );
		}
		wp_add_inline_script(
			'mds-rm-release-picker',
			'window.mdsRmDebug = ' . wp_json_encode( defined( 'WP_DEBUG' ) && WP_DEBUG ) . '; window.mdsRmCatalog = ' . wp_json_encode( \MDS\ReleaseManager\Core\ReleaseSearch::editor_catalog() ) . ';',
			'before'
		);
		$edit_script    = 'blocks/artist-discography/edit.js';
		$table_controls = 'blocks/artist-discography/table-controls.js';
		wp_register_script(
			'mds-rm-discography-table-controls',
			MDS_RM_URL . $table_controls,
			array( 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n', 'mds-rm-color-control', 'mds-rm-native-release-styles' ),
			filemtime( MDS_RM_PATH . $table_controls ),
			true
		);
		wp_register_script(
			'mds-rm-artist-discography-editor',
			MDS_RM_URL . $edit_script,
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-server-side-render', 'wp-block-editor', 'wp-api-fetch', 'wp-url', 'wp-html-entities', 'wp-i18n', 'mds-rm-discography-table-controls' ),
			filemtime( MDS_RM_PATH . $edit_script ),
			true
		);
		wp_set_script_translations( 'mds-rm-discography-table-controls', 'mds-release-manager', MDS_RM_PATH . 'languages' );
		wp_set_script_translations( 'mds-rm-artist-discography-editor', 'mds-release-manager', MDS_RM_PATH . 'languages' );

		$table_style = 'blocks/artist-discography/style.css';
		wp_register_style( 'mds-rm-discography-block', MDS_RM_URL . $table_style, array(), filemtime( MDS_RM_PATH . $table_style ) );
		register_block_type(
			MDS_RM_PATH . 'blocks/artist-discography',
			array(
				'render_callback' => array( \MDS\ReleaseManager\Frontend\Discography::class, 'render_block' ),
			)
		);
		self::register_release_block( 'release-meta', 'render_gutenberg_release_meta' );
		self::register_release_block( 'release-links', 'render_gutenberg_release_links' );

		$player_script = 'blocks/release-player/edit.js';
		if ( file_exists( MDS_RM_PATH . $player_script ) ) {
			wp_register_script(
				'mds-rm-release-player-editor',
				MDS_RM_URL . $player_script,
				array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n', 'mds-rm-release-picker', 'mds-rm-color-control' ),
				filemtime( MDS_RM_PATH . $player_script ),
				true
			);
			wp_set_script_translations( 'mds-rm-release-player-editor', 'mds-release-manager', MDS_RM_PATH . 'languages' );
		}
		$player_style = 'css/release-player.css';
		if ( file_exists( MDS_RM_PATH . $player_style ) ) {
			wp_register_style( 'mds-rm-release-player', MDS_RM_URL . $player_style, array(), filemtime( MDS_RM_PATH . $player_style ) );
		}
		register_block_type(
			MDS_RM_PATH . 'blocks/release-player',
			array(
				'render_callback' => array( self::class, 'render_gutenberg_release_player' ),
			)
		);
	}

	/**
	 * Renders the Release Player block in its post context.
	 *
	 * @since 1.2.0
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $_content   Block content required by the render callback.
	 * @param \WP_Block|null       $block      Current block instance.
	 * @return string Rendered HTML.
	 */
	public static function render_gutenberg_release_player( array $attributes, string $_content = '', ?\WP_Block $block = null ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassAfterLastUsed, Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WordPress render_callback signature.
		return ReleasePlayer::render( $attributes, $block );
	}

	/**
	 * Registers a release block and its editor script.
	 *
	 * @param string $slug     Block slug and directory name.
	 * @param string $callback Renderer method on this class.
	 * @param array  $defaults Attribute defaults supplied by PHP.
	 */
	private static function register_release_block( string $slug, string $callback, array $defaults = array() ): void {
		$edit_script = "blocks/{$slug}/edit.js";
		$controls    = "blocks/{$slug}/controls.js";
		$style       = "css/{$slug}.css";
		wp_register_style( "mds-rm-{$slug}-editor-style", MDS_RM_URL . $style, array( 'dashicons' ), filemtime( MDS_RM_PATH . $style ) );
		$icon_picker_script = 'js/icon-picker.js';
		if ( ! wp_script_is( 'mds-rm-icon-picker', 'registered' ) ) {
			wp_register_script(
				'mds-rm-icon-picker',
				MDS_RM_URL . $icon_picker_script,
				array( 'wp-element', 'wp-components', 'wp-i18n', 'wp-a11y' ),
				filemtime( MDS_RM_PATH . $icon_picker_script ),
				true
			);
			wp_set_script_translations( 'mds-rm-icon-picker', 'mds-release-manager', MDS_RM_PATH . 'languages' );
		}

		wp_register_script( "mds-rm-{$slug}-controls", MDS_RM_URL . $controls, array( 'mds-rm-native-release-styles' ), filemtime( MDS_RM_PATH . $controls ), true );
		wp_register_script(
			"mds-rm-{$slug}-editor",
			MDS_RM_URL . $edit_script,
			array( 'wp-blocks', 'wp-server-side-render', 'mds-rm-icon-picker', 'mds-rm-release-picker', 'mds-rm-release-items', "mds-rm-{$slug}-controls" ),
			filemtime( MDS_RM_PATH . $edit_script ),
			true
		);
		wp_set_script_translations( "mds-rm-{$slug}-editor", 'mds-release-manager', MDS_RM_PATH . 'languages' );
		wp_set_script_translations( "mds-rm-{$slug}-controls", 'mds-release-manager', MDS_RM_PATH . 'languages' );

		$directory = MDS_RM_PATH . 'blocks/' . $slug;
		$metadata  = wp_json_file_decode( $directory . '/block.json', array( 'associative' => true ) );
		if ( ! is_array( $metadata ) ) {
			return;
		}
		$attributes = $metadata['attributes'];
		foreach ( $defaults as $name => $default ) {
			$attributes[ $name ]['default'] = $default;
		}
		// Associative JSON decoding turns the empty object into an array.
		$attributes['styles']['default'] = (object) $attributes['styles']['default'];
		register_block_type(
			$directory,
			array(
				'attributes'      => $attributes,
				'render_callback' => array( self::class, $callback ),
			)
		);
	}

	/**
	 * Renders the Release Links block in its post context.
	 *
	 * @param array     $attributes Block attributes.
	 * @param string    $_content   Block content required by the render callback.
	 * @param \WP_Block $block      The parsed block.
	 * @return string The rendered release links.
	 */
	public static function render_gutenberg_release_links( $attributes, $_content, $block ): string {
		return ReleaseLinksBlock::render( $attributes, $_content, $block );
	}

	/**
	 * Renders the Release Meta Block.
	 *
	 * @since 0.9.0
	 * @param array     $attributes Block attributes.
	 * @param string    $_content   Block content required by the render callback.
	 * @param \WP_Block $block      The parsed block.
	 * @return string The rendered metadata.
	 */
	public static function render_gutenberg_release_meta( $attributes, $_content, $block ): string {
		$post = ReleaseLinksBlock::resolve_block_source( $attributes, $block );
		if ( ! $post ) {
			return '';
		}
		$fields                   = ReleaseMeta::style_fields();
		$fields['font_size']      = array( 'type' => 'font_size' );
		$fields['font_style']     = array( 'type' => 'font_style' );
		$fields['text_align']     = array( 'type' => 'text_align' );
		$fields['letter_spacing'] = array( 'type' => 'length' );
		$wrapper                  = ReleaseLinksBlock::native_style_wrapper( $attributes, 'metaStyles', $fields, 'meta' );
		$content                  = ReleaseMeta::render_post( $post, $attributes );
		return $content ? '<div ' . get_block_wrapper_attributes( $wrapper ) . '>' . $content . '</div>' : '';
	}

	/**
	 * Replaces configured release metadata in supported core blocks.
	 *
	 * @since 0.9.0
	 * @param mixed $block_content The current rendered block content.
	 * @param array $block         The parsed block data.
	 * @return mixed The updated content or the original filter value.
	 */
	public static function render_core_block_with_meta( mixed $block_content, $block ): mixed {
		if ( empty( $block['attrs']['mdsMetaField'] ) ) {
			return $block_content;
		}

		$meta_field   = $block['attrs']['mdsMetaField'];
		$upc_override = $block['attrs']['mdsUpcOverride'] ?? '';

		$post_id = isset( $block['context']['postId'] ) ? $block['context']['postId'] : get_the_ID();

		if ( ! empty( $upc_override ) ) {
			$args  = array(
				'post_type'   => 'release',
				'meta_key'    => 'upc_ean', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- An explicit block binding resolves by the stored UPC, not the current post.
				'meta_value'  => preg_replace( '/[^0-9]/', '', $upc_override ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
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
		} elseif ( 'buy_now_link' === $meta_field ) {
			$val = \MDS\ReleaseManager\Core\ReleaseModel::get_primary_buy_now_url( (int) $post_id );
		} elseif ( 'featured_image_url' === $meta_field ) {
			$val = get_post_meta( $post_id, $meta_field, true );
		} elseif ( 'release_type' === $meta_field || 'release_format' === $meta_field ) {
			$val = \MDS\ReleaseManager\Core\ReleaseModel::format_name( $post_id );
			if ( is_wp_error( $val ) ) {
				\MDS\ReleaseManager\Core\Logger::report_failure( 'Release ' . $post_id . ': ' . $val->get_error_message() );
				return '';
			}
		} elseif ( 'release_media_format' === $meta_field ) {
			$terms = wp_get_post_terms( $post_id, 'release_media_format', array( 'fields' => 'names' ) );
			$val   = ! is_wp_error( $terms ) && ! empty( $terms ) ? implode( ', ', $terms ) : '';
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

		$tags = new \WP_HTML_Tag_Processor( $block_content );
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

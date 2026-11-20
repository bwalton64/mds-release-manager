<?php
/**
 * Renders the plugin's Release fallback content.
 *
 * @package MDS_Release_Manager
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders fallback content without replacing the theme's Release template.
 *
 * @since 0.9.0
 */
final class MDS_RM_Frontend_Templates {

	/**
	 * Registers the fallback content and Artist template hooks.
	 *
	 * @since 0.9.0
	 */
	public static function register_hooks() {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_release_fallback_assets' ) );
		add_action( 'enqueue_block_assets', array( self::class, 'enqueue_artist_assets' ) );
		add_filter( 'the_content', array( self::class, 'filter_release_content' ), 20 );
		add_filter( 'the_content', array( self::class, 'filter_artist_content' ), 20 );
		add_filter( 'post_thumbnail_html', array( self::class, 'filter_release_featured_image' ), 10, 2 );
		add_filter( 'render_block', array( self::class, 'filter_release_author_group' ), 20, 3 );
	}

	public static function enqueue_artist_assets() {
		MDS_RM_Template_Loader::enqueue_editable_style( 'mds-discography', 'css/artist-fallback.css' );
	}

	public static function filter_artist_content( $content ) {
		$post = self::get_fallback_artist_post();
		if ( ! $post ) {
			return $content;
		}
		$visited_blocks = array();
		$has_discography = self::content_has_component( $post->post_content, 'mds-rm/artist-discography', 'artist-discography', $visited_blocks );
		$args = array(
			'content' => $content,
			'image' => wp_get_attachment_image( get_post_thumbnail_id( $post->ID ), 'large', false, array( 'class' => 'mds-artist-fallback__image' ) ),
			'discography' => $has_discography ? '' : MDS_RM_Discography::render( $post->ID ),
			'contact' => get_post_meta( $post->ID, 'artist_contact', true ),
			'style' => MDS_RM_Customizer::get_artist_fallback_style_attribute(),
		);
		return MDS_RM_Template_Loader::render( 'templates/artist-fallback.php', $args );
	}

	private static function get_fallback_artist_post() {
		if ( is_admin() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! is_singular( 'artist' ) || ! in_the_loop() || ! is_main_query() ) {
			return false;
		}
		$post = get_post();
		if ( ! $post instanceof WP_Post || 'artist' !== $post->post_type || MDS_RM_Builder_Detection::detect_builder( $post->ID ) ) {
			return false;
		}
		return $post;
	}

	/**
	 * Enqueues the fallback stylesheet before the theme renders its header.
	 *
	 * @since 0.9.0
	 */
	public static function enqueue_release_fallback_assets() {
		$post_id = get_queried_object_id();
		if (
			! is_singular( 'release' )
			|| is_admin()
			|| '1' === get_option( 'mds_rm_global_redirect', '0' )
			|| ! $post_id
			|| MDS_RM_Builder_Detection::detect_builder( $post_id )
		) {
			return;
		}

		MDS_RM_Template_Loader::enqueue_editable_style( 'mds-release-fallback', 'css/release-fallback.css' );
	}

	/**
	 * Adds the default Release fallback components to the main singular content.
	 *
	 * @since 0.9.0
	 * @param string $content The filtered post content.
	 * @return string The content with fallback components, when applicable.
	 */
	public static function filter_release_content( $content ) {
		$post = self::get_fallback_release_post();
		if ( ! $post instanceof WP_Post ) {
			return $content;
		}

		$visited_blocks = array();
		$has_meta       = self::content_has_component( $post->post_content, 'mds-rm/release-meta', 'release-meta', $visited_blocks );
		$visited_blocks = array();
		$has_links      = self::content_has_component( $post->post_content, 'mds-rm/release-links', 'release-links', $visited_blocks );
		$layout = get_theme_mod( 'mds_rm_layout_style', 'split' );
		if ( ! in_array( $layout, array( 'split', 'stacked' ), true ) ) {
			$layout = 'split';
		}

		$args = array(
			'content'    => $content,
			'image'      => self::get_release_image( $post ),
			'layout'     => $layout,
			'style'      => MDS_RM_Customizer::get_release_fallback_style_attribute(),
			'meta_html' => $has_meta ? '' : MDS_RM_Release_Meta::render( array(), $post->ID ),
			'links_html' => $has_links ? '' : MDS_RM_Release_Links::render( array(), $post->ID ),
		);

		return MDS_RM_Template_Loader::render( 'templates/release-fallback.php', $args );
	}

	/**
	 * Removes theme author blocks and the Twenty Twenty-Five byline pattern.
	 *
	 * @since 0.9.0
	 * @param string   $block_content The rendered block HTML.
	 * @param array    $block The parsed block.
	 * @param WP_Block $instance The rendered block instance.
	 * @return string The block HTML, or an empty string for the byline.
	 */
	public static function filter_release_author_group( $block_content, $block, $instance ) {
		if (
			is_admin()
			|| is_feed()
			|| doing_filter( 'the_content' )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ! is_singular( array( 'release', 'artist' ) )
			|| ! is_array( $block )
			|| (
				! in_array( $block['blockName'], array( 'core/post-author-name', 'core/post-author' ), true )
				&& ! self::is_theme_byline( $block )
			)
		) {
			return $block_content;
		}

		$post_id = get_queried_object_id();
		if (
			MDS_RM_Builder_Detection::detect_builder( $post_id )
			|| (
				$instance instanceof WP_Block
				&& isset( $instance->context['postId'] )
				&& $post_id !== absint( $instance->context['postId'] )
			)
		) {
			return $block_content;
		}

		return '';
	}

	/**
	 * Identifies the theme's complete byline, including its text labels.
	 *
	 * @param array $block The parsed block.
	 * @return bool Whether this is the Twenty Twenty-Five byline pattern.
	 */
	private static function is_theme_byline( $block ) {
		if ( 'twentytwentyfive' !== get_template() || 'core/group' !== $block['blockName'] || ! isset( $block['innerBlocks'] ) || 4 !== count( $block['innerBlocks'] ) ) {
			return false;
		}

		$children = $block['innerBlocks'];
		if (
			'core/paragraph' !== $children[0]['blockName']
			|| 'core/post-author-name' !== $children[1]['blockName']
			|| 'core/paragraph' !== $children[2]['blockName']
			|| 'core/post-terms' !== $children[3]['blockName']
			|| 'category' !== ( isset( $children[3]['attrs']['term'] ) ? $children[3]['attrs']['term'] : '' )
		) {
			return false;
		}

		return trim( wp_strip_all_tags( $children[0]['innerHTML'] ) ) === trim( __( 'Written by ', 'twentytwentyfive' ) )
			&& trim( wp_strip_all_tags( $children[2]['innerHTML'] ) ) === trim( __( 'in', 'twentytwentyfive' ) );
	}

	/**
	 * Hides the theme's own thumbnail when the fallback renders that image.
	 *
	 * @since 0.9.0
	 * @param string $html The theme-generated thumbnail markup.
	 * @param int    $post_id The post ID.
	 * @return string The thumbnail markup, or an empty string when duplicated.
	 */
	public static function filter_release_featured_image( $html, $post_id ) {
		$artist = self::get_fallback_artist_post();
		if ( $artist && $artist->ID === absint( $post_id ) && wp_get_attachment_image( get_post_thumbnail_id( $post_id ), 'large' ) ) {
			return '';
		}
		$post = self::get_fallback_release_post( $post_id );
		if ( ! $post instanceof WP_Post || '' === $html || '' === self::get_release_image( $post ) ) {
			return $html;
		}

		return '';
	}

	/**
	 * Resolves the Release only when fallback composition is permitted.
	 *
	 * @param int $post_id Optional post ID being filtered.
	 * @return WP_Post|false The eligible Release, or false.
	 */
	private static function get_fallback_release_post( $post_id = 0 ) {
		if (
			is_admin()
			|| is_feed()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ! is_singular( 'release' )
			|| ! in_the_loop()
			|| ! is_main_query()
		) {
			return false;
		}

		$post = get_post();
		if (
			! $post instanceof WP_Post
			|| 'release' !== $post->post_type
			|| ( $post_id && $post->ID !== absint( $post_id ) )
			|| '1' === get_option( 'mds_rm_global_redirect', '0' )
			|| MDS_RM_Builder_Detection::detect_builder( $post->ID )
		) {
			return false;
		}

		return $post;
	}

	/**
	 * Checks post content and referenced synced patterns for an existing component.
	 *
	 * @param string $content The serialized post content.
	 * @param string $block_name The registered block name.
	 * @param string $shortcode_name The shortcode name.
	 * @param array  $visited_blocks Referenced synced patterns already inspected.
	 * @return bool Whether the component is already present.
	 */
	private static function content_has_component( $content, $block_name, $shortcode_name, &$visited_blocks ) {
		if ( has_block( $block_name, $content ) || has_shortcode( $content, $shortcode_name ) ) {
			return true;
		}
		if ( false === strpos( $content, '<!-- wp:block ' ) ) {
			return false;
		}

		return self::block_tree_has_component(
			parse_blocks( $content ),
			$block_name,
			$shortcode_name,
			$visited_blocks
		);
	}

	/**
	 * Searches nested blocks for synced patterns containing the component.
	 *
	 * @param array  $blocks The parsed block tree.
	 * @param string $block_name The registered block name.
	 * @param string $shortcode_name The shortcode name.
	 * @param array  $visited_blocks Referenced synced patterns already inspected.
	 * @return bool Whether the component is already present.
	 */
	private static function block_tree_has_component( $blocks, $block_name, $shortcode_name, &$visited_blocks ) {
		foreach ( $blocks as $block ) {
			if ( 'core/block' !== $block['blockName'] || empty( $block['attrs']['ref'] ) ) {
				if (
					! empty( $block['innerBlocks'] )
					&& self::block_tree_has_component( $block['innerBlocks'], $block_name, $shortcode_name, $visited_blocks )
				) {
					return true;
				}
				continue;
			}

			$reference = absint( $block['attrs']['ref'] );
			if ( $reference && ! isset( $visited_blocks[ $reference ] ) ) {
				$visited_blocks[ $reference ] = true;
				$reusable_block = get_post( $reference );
				if (
					$reusable_block instanceof WP_Post
					&& 'wp_block' === $reusable_block->post_type
					&& self::content_has_component( $reusable_block->post_content, $block_name, $shortcode_name, $visited_blocks )
				) {
					return true;
				}
			}
			if (
				! empty( $block['innerBlocks'] )
				&& self::block_tree_has_component( $block['innerBlocks'], $block_name, $shortcode_name, $visited_blocks )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Renders a responsive featured image, preserving the legacy URL fallback.
	 *
	 * @since 0.9.0
	 * @param WP_Post $post The Release post.
	 * @return string The image markup, or an empty string.
	 */
	private static function get_release_image( WP_Post $post ) {
		$image_url = get_post_meta( $post->ID, 'featured_image_url', true );
		if ( ! is_string( $image_url ) || '' === esc_url( $image_url ) ) {
			$image_url = '';
		}
		$attachment_id = $image_url ? attachment_url_to_postid( $image_url ) : get_post_thumbnail_id( $post->ID );

		if ( $attachment_id ) {
			$image = wp_get_attachment_image(
				$attachment_id,
				'large',
				false,
				array(
					'class' => 'mds-release-fallback__image',
					'sizes' => '(max-width: 767px) 100vw, (max-width: 1200px) 45vw, 540px',
				)
			);
			if ( $image ) {
				return $image;
			}
		}

		if ( '' === $image_url ) {
			$image_url = get_the_post_thumbnail_url( $post->ID, 'large' );
		}
		if ( ! is_string( $image_url ) || '' === esc_url( $image_url ) ) {
			return '';
		}

		return sprintf(
			'<img class="mds-release-fallback__image" src="%1$s" alt="%2$s" loading="lazy" decoding="async">',
			esc_url( $image_url ),
			esc_attr( get_the_title( $post->ID ) )
		);
	}

}

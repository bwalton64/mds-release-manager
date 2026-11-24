<?php
/**
 * Renders the plugin's Release fallback content.
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders fallback content without replacing the theme's Release template.
 *
 * @since 0.9.0
 */
final class FrontendTemplates {

	/**
	 * Registers the fallback content and Artist template hooks.
	 *
	 * @since 0.9.0
	 */
	public static function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_release_fallback_assets' ) );
		add_action( 'enqueue_block_assets', array( self::class, 'enqueue_artist_assets' ) );
		add_filter( 'the_content', array( self::class, 'filter_release_content' ), 20 );
		add_filter( 'the_content', array( self::class, 'filter_artist_content' ), 20 );
		add_filter( 'post_thumbnail_html', array( self::class, 'filter_release_featured_image' ), 10, 2 );
		add_filter( 'render_block', array( self::class, 'filter_release_author_group' ), 20, 3 );
	}

	/** Enqueues fallback styles for eligible Artist pages. */
	public static function enqueue_artist_assets(): void {
		\MDS\ReleaseManager\Core\TemplateLoader::enqueue_editable_style( 'mds-discography', 'css/artist-fallback.css' );
	}

	/**
	 * Wraps eligible Artist content in the fallback template.
	 *
	 * @param string $content The filtered post content.
	 * @return string The content with fallback components, when applicable.
	 */
	public static function filter_artist_content( $content ) {
		$post = self::get_fallback_artist_post();
		if ( ! $post ) {
			return $content;
		}
		$tpl             = TemplateDefaults::get( 'artist' );
		$visited_blocks  = array();
		$has_discography = self::content_has_component( $post->post_content, 'mds-rm/artist-discography', 'artist-discography', $visited_blocks );
		$show_portrait   = ! empty( $tpl['show_portrait'] );
		$show_discog     = ! empty( $tpl['show_discography'] );
		$show_contact    = ! empty( $tpl['show_contact'] );
		$args            = array(
			'content'             => $content,
			'image'               => $show_portrait ? wp_get_attachment_image( get_post_thumbnail_id( $post->ID ), 'large', false, array( 'class' => 'mds-artist-fallback__image' ) ) : '',
			'discography'         => ( $show_discog && ! $has_discography ) ? self::render_fallback_block( 'mds-rm/artist-discography', array( 'artistId' => $post->ID ) ) : '',
			'discography_heading' => (string) ( $tpl['discography_heading'] ?? '' ),
			'contact'             => $show_contact ? get_post_meta( $post->ID, 'artist_contact', true ) : '',
			'contact_heading'     => (string) ( $tpl['contact_heading'] ?? __( 'Contact Information', 'mds-release-manager' ) ),
			'layout'              => (string) ( $tpl['layout'] ?? 'stacked' ),
			'section_order'       => (string) ( $tpl['section_order'] ?? 'discography_first' ),
			'style'               => TemplateDefaults::get_artist_style_attribute(),
		);
		return \MDS\ReleaseManager\Core\TemplateLoader::render( 'templates/artist-fallback.php', $args );
	}

	/**
	 * Finds the Artist post eligible for fallback rendering.
	 *
	 * @return WP_Post|false The eligible Artist, or false.
	 */
	private static function get_fallback_artist_post(): \WP_Post|false {
		if ( is_admin() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! is_singular( 'artist' ) || ! in_the_loop() || ! is_main_query() ) {
			return false;
		}
		$post = get_post();
		if ( ! $post instanceof \WP_Post || 'artist' !== $post->post_type || \MDS\ReleaseManager\Core\BuilderDetection::detect_builder( $post->ID ) ) {
			return false;
		}
		return $post;
	}

	/**
	 * Enqueues the fallback stylesheet before the theme renders its header.
	 *
	 * @since 0.9.0
	 */
	public static function enqueue_release_fallback_assets(): void {
		$post_id = get_queried_object_id();
		if (
			! is_singular( 'release' )
			|| is_admin()
			|| ! $post_id
			|| '' !== ArtistRouting::resolve_release_redirect( $post_id )
			|| \MDS\ReleaseManager\Core\BuilderDetection::detect_builder( $post_id )
		) {
			return;
		}

		\MDS\ReleaseManager\Core\TemplateLoader::enqueue_editable_style( 'mds-release-fallback', 'css/release-fallback.css' );
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
		if ( ! $post instanceof \WP_Post ) {
			return $content;
		}

		$tpl            = TemplateDefaults::get( 'release' );
		$visited_blocks = array();
		$has_meta       = self::content_has_component( $post->post_content, 'mds-rm/release-meta', 'release-meta', $visited_blocks );
		$visited_blocks = array();
		$has_links      = self::content_has_component( $post->post_content, 'mds-rm/release-links', 'release-links', $visited_blocks );
		$layout         = (string) ( $tpl['layout'] ?? 'split' );
		if ( ! in_array( $layout, array( 'split', 'stacked' ), true ) ) {
			$layout = 'split';
		}

		$args = array(
			'content'         => $content,
			'image'           => ! empty( $tpl['show_image'] ) ? self::get_release_image( $post ) : '',
			'layout'          => $layout,
			'image_position'  => (string) ( $tpl['image_position'] ?? 'left' ),
			'sticky_image'    => ! empty( $tpl['sticky_image'] ),
			'mobile_order'    => (string) ( $tpl['mobile_order'] ?? 'image_first' ),
			'component_order' => (string) ( $tpl['component_order'] ?? 'meta_body_links' ),
			'style'           => TemplateDefaults::get_release_style_attribute(),
			'meta_html'       => ( ! empty( $tpl['show_meta'] ) && ! $has_meta ) ? self::render_fallback_block( 'mds-rm/release-meta', array( 'releaseId' => $post->ID ) ) : '',
			'links_html'      => ( ! empty( $tpl['show_links'] ) && ! $has_links ) ? self::render_fallback_block( 'mds-rm/release-links', array( 'releaseId' => $post->ID ) ) : '',
		);

		return \MDS\ReleaseManager\Core\TemplateLoader::render( 'templates/release-fallback.php', $args );
	}

	/** Renders an automatic component with the same defaults and block supports as an inserted block. */
	private static function render_fallback_block( string $name, array $attributes ): string {
		return render_block(
			array(
				'blockName'    => $name,
				'attrs'        => $attributes,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
	}

	/**
	 * Removes theme author blocks and the Twenty Twenty-Five byline pattern.
	 *
	 * @since 0.9.0
	 * @param string   $block_content The rendered block HTML.
	 * @param array    $block         The parsed block.
	 * @param WP_Block $instance      The rendered block instance.
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
			\MDS\ReleaseManager\Core\BuilderDetection::detect_builder( $post_id )
			|| (
				$instance instanceof \WP_Block
				&& isset( $instance->context['postId'] )
				&& absint( $instance->context['postId'] ) !== $post_id
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
	private static function is_theme_byline( $block ): bool {
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

		// Compare against the theme's translations, not the plugin's translations.
		// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- This text is rendered by Twenty Twenty-Five.
		$written_by = __( 'Written by ', 'twentytwentyfive' );
		// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- This text is rendered by Twenty Twenty-Five.
		$in = __( 'in', 'twentytwentyfive' );

		return trim( wp_strip_all_tags( $children[0]['innerHTML'] ) ) === trim( $written_by )
			&& trim( wp_strip_all_tags( $children[2]['innerHTML'] ) ) === trim( $in );
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
		if ( $artist && absint( $post_id ) === $artist->ID ) {
			$artist_tpl = TemplateDefaults::get( 'artist' );
			if ( ! empty( $artist_tpl['show_portrait'] ) && wp_get_attachment_image( get_post_thumbnail_id( $post_id ), 'large' ) ) {
				return '';
			}
			return $html;
		}
		$post = self::get_fallback_release_post( $post_id );
		if ( ! $post instanceof \WP_Post || '' === $html ) {
			return $html;
		}
		$release_tpl = TemplateDefaults::get( 'release' );
		if ( empty( $release_tpl['show_image'] ) || '' === self::get_release_image( $post ) ) {
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
	private static function get_fallback_release_post( $post_id = 0 ): \WP_Post|false {
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
			! $post instanceof \WP_Post
			|| 'release' !== $post->post_type
			|| ( $post_id && absint( $post_id ) !== $post->ID )
			|| '' !== ArtistRouting::resolve_release_redirect( $post->ID )
			|| \MDS\ReleaseManager\Core\BuilderDetection::detect_builder( $post->ID )
		) {
			return false;
		}

		return $post;
	}

	/**
	 * Checks post content and referenced synced patterns for an existing component.
	 *
	 * @param string $content        The serialized post content.
	 * @param string $block_name     The registered block name.
	 * @param string $shortcode_name The shortcode name.
	 * @param array  $visited_blocks  Referenced synced patterns already inspected.
	 * @return bool Whether the component is already present.
	 */
	private static function content_has_component( $content, string $block_name, string $shortcode_name, array &$visited_blocks ): bool {
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
	 * @param array  $blocks         The parsed block tree.
	 * @param string $block_name     The registered block name.
	 * @param string $shortcode_name The shortcode name.
	 * @param array  $visited_blocks  Referenced synced patterns already inspected.
	 * @return bool Whether the component is already present.
	 */
	private static function block_tree_has_component( array $blocks, string $block_name, string $shortcode_name, array &$visited_blocks ): bool {
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
				$reusable_block               = get_post( $reference );
				if (
					$reusable_block instanceof \WP_Post
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
	private static function get_release_image( \WP_Post $post ): string {
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

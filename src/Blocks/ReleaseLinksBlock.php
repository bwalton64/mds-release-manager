<?php
/**
 * Release Links block source selection and rendering.
 *
 * @package MDS\ReleaseManager
 */

declare(strict_types=1);

namespace MDS\ReleaseManager\Blocks;

use WP_Block;
use WP_Post;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Provides block-specific Release selection without changing shortcode or widget context. */
final class ReleaseLinksBlock {
	/** Registers the editor lookup routes. */
	public static function register_routes(): void {
		$permission = static function (): bool {
			return current_user_can( 'edit_posts' ) || current_user_can( 'edit_pages' );
		};
		register_rest_route(
			'mds/v1',
			'/release-links-choices',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => $permission,
				'callback'            => array( self::class, 'choices' ),
				'args'                => array(
					'search' => array(
						'type'      => 'string',
						'maxLength' => 160,
						'default'   => '',
					),
					'page'   => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 10000,
						'default' => 1,
					),
				),
			)
		);
		register_rest_route(
			'mds/v1',
			'/release-links-source',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => $permission,
				'callback'            => array( self::class, 'source' ),
				'args'                => array(
					'release_id' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
					'context_id' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
					'upc'        => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * Returns published Release choices matching title, Artist name, or exact UPC/EAN.
	 *
	 * @param WP_REST_Request $request Editor lookup request.
	 * @return array Paginated results.
	 */
	public static function choices( WP_REST_Request $request ): array {
		$result = \MDS\ReleaseManager\Core\ReleaseSearch::search_paginated(
			array(
				'search' => (string) $request['search'],
				'page'   => (int) $request['page'],
			)
		);
		return array(
			'items' => $result['items'],
			'pages' => $result['pages'],
		);
	}

	/**
	 * Gets the current source and any explicitly selected source for the editor.
	 *
	 * @param WP_REST_Request $request Editor context request.
	 * @return array Source state without link or audio data.
	 */
	public static function source( WP_REST_Request $request ): array {
		$context = (int) $request['context_id'];
		$post    = $context ? get_post( $context ) : null;
		if ( ! $post || ( 'publish' !== $post->post_status && ! current_user_can( 'read_post', $context ) ) ) {
			$context = 0;
		}
		$attributes = array(
			'releaseId'   => (int) $request['release_id'],
			'upcOverride' => $request['upc'],
		);
		$explicit   = self::explicit( $attributes );
		$has_choice = $attributes['releaseId'] || '' !== $attributes['upcOverride'];
		$resolved   = $explicit ?? self::context( $context );
		return array(
			'missing' => $has_choice && ! $explicit,
			'item'    => $resolved ? self::labels( array( $resolved ) )[0] : null,
		);
	}

	/**
	 * Converts Release posts to picker labels, loading related Artist names in one query.
	 *
	 * @param WP_Post[] $posts Release posts.
	 * @return array Picker records.
	 */
	private static function labels( array $posts ): array {
		return \MDS\ReleaseManager\Core\ReleaseSearch::describe( $posts );
	}

	/**
	 * Resolves the saved choice, preferring a stable post ID over the legacy UPC.
	 *
	 * @param array $attributes Block attributes.
	 * @return WP_Post|null Explicit Release.
	 */
	private static function explicit( array $attributes ): ?WP_Post {
		if ( ! empty( $attributes['releaseId'] ) ) {
			$post = get_post( $attributes['releaseId'] );
			return $post && 'release' === $post->post_type && 'publish' === $post->post_status && ! $post->post_password ? $post : null;
		}
		return ! empty( $attributes['upcOverride'] ) || '0' === ( $attributes['upcOverride'] ?? '' )
			? ReleaseMeta::resolve_post( $attributes['upcOverride'] )
			: null;
	}

	/**
	 * Resolves the latest published Artist Release or the existing Release/product context.
	 *
	 * @param int $id Context post ID.
	 * @return WP_Post|null Contextual Release.
	 */
	private static function context( int $id ): ?WP_Post {
		if ( ! $id ) {
			return null;
		}
		if ( 'artist' !== get_post_type( $id ) ) {
			return ReleaseMeta::resolve_post( '', $id );
		}
		$uuid = get_post_meta( $id, '_mds_artist_uuid', true );
		if ( ! is_string( $uuid ) || '' === $uuid ) {
			return null;
		}
		$posts = get_posts(
			array(
				'post_type'      => 'release',
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => 1,
				'orderby'        => array(
					'date' => 'DESC',
					'ID'   => 'DESC',
				),
				'meta_key'       => 'artist_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Match the same Artist UUID used by the Discography block.
				'meta_value'     => $uuid, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return $posts[0] ?? null;
	}

	/**
	 * Renders the links for the selected or contextual Release.
	 *
	 * @param array    $attributes Block attributes.
	 * @param string   $_content   Saved content (none for this dynamic block).
	 * @param WP_Block $block      Block context.
	 * @return string Rendered block.
	 */
	public static function render( array $attributes, string $_content, WP_Block $block ): string {
		$post = self::resolve_block_source( $attributes, $block );
		if ( ! $post ) {
			return '';
		}
		$wrapper = self::native_style_wrapper( $attributes, 'linkStyles', ReleaseLinks::style_fields(), 'links' );
		$content = ReleaseLinks::render_post( $post, $attributes );
		return $content ? '<div ' . get_block_wrapper_attributes( $wrapper ) . '>' . $content . '</div>' : '';
	}

	/**
	 * Resolves the source for either Release block.
	 *
	 * @param array    $attributes Block attributes.
	 * @param WP_Block $block      Context provider.
	 * @return WP_Post|null Resolved Release.
	 */
	public static function resolve_block_source( array $attributes, WP_Block $block ): ?WP_Post {
		return self::explicit( $attributes ) ?? self::context( (int) ( $block->context['postId'] ?? get_the_ID() ) );
	}

	/**
	 * Applies native overrides on the wrapper, preserving narrower legacy rules.
	 *
	 * @param array  $attributes Block attributes, with replaced desktop values removed for this render.
	 * @param string $style_key  Attribute containing native styles.
	 * @param array  $fields     Allowed CSS fields.
	 * @param string $prefix     CSS variable namespace.
	 * @return array Wrapper attributes.
	 */
	public static function native_style_wrapper( array &$attributes, string $style_key, array $fields, string $prefix ): array {
		$style = array();
		$saved = $attributes[ $style_key ] ?? array();
		foreach ( $fields as $key => $field ) {
			$value = $saved[ $key ] ?? null;
			$type  = $field['type'];
			if ( 'spacing' === $type && is_array( $value ) ) {
				$sides = array();
				foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
					$sides[] = self::style_value( $value[ $side ] ?? null, 'length' );
				}
				$value = array_filter( $sides, static fn( $side ) => '' !== $side )
					? implode( ' ', array_map( static fn( $side ) => '' === $side ? '0' : $side, $sides ) )
					: '';
			} else {
				$value = self::style_value( $value, $type );
			}
			if ( '' !== $value ) {
				if ( is_array( $attributes['styles'] ?? null ) && is_array( $attributes['styles']['desktop'] ?? null ) ) {
					unset( $attributes['styles']['desktop'][ $key ] );
				}
				$style[] = '--mds-' . $prefix . '-' . str_replace( '_', '-', $key ) . '-desktop:' . $value;
			}
		}
		return $style ? array( 'style' => implode( ';', $style ) ) : array();
	}

	/**
	 * Validates theme preset references and native control values.
	 *
	 * @param mixed  $value Saved value.
	 * @param string $type  Style field type.
	 * @return string Valid CSS value.
	 */
	public static function style_value( $value, string $type ): string {
		if ( is_string( $value ) ) {
			$presets = array(
				'font'      => 'font-family',
				'font_size' => 'font-size',
			);
			if ( isset( $presets[ $type ] ) && preg_match( '/\Avar\(--wp--preset--' . $presets[ $type ] . '--[a-z0-9_-]+\)\z/i', $value ) ) {
				return $value;
			}
			if ( 'font_style' === $type ) {
				return in_array( $value, array( 'normal', 'italic' ), true ) ? $value : '';
			}
			if ( 'text_align' === $type ) {
				return in_array( $value, array( 'start', 'center', 'end' ), true ) ? $value : '';
			}
			if ( 'color' === $type && preg_match( '/\A(?:#[a-f0-9]{4}|#[a-f0-9]{8}|rgba?\([0-9.,% \/]+\)|var\(--wp--preset--color--[a-z0-9_-]+\))\z/i', $value ) ) {
				return $value;
			}
			if ( in_array( $type, array( 'length', 'spacing', 'width' ), true ) && preg_match( '/\Avar\(--wp--preset--spacing--[a-z0-9_-]+\)\z/i', $value ) ) {
				return $value;
			}
		}
		return ReleaseMeta::valid_style( $value, 'font_size' === $type ? 'length' : $type );
	}
}

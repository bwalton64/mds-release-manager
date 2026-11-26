<?php
/**
 * Release fallback content partial.
 *
 * @package MDS_Release_Manager
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

( static function ( array $args ): void {
	$has_image      = '' !== (string) ( $args['image'] ?? '' );
	$layout         = (string) ( $args['layout'] ?? 'split' );
	$image_position = (string) ( $args['image_position'] ?? 'left' );
	$sticky_image   = ! empty( $args['sticky_image'] );
	$mobile_order   = (string) ( $args['mobile_order'] ?? 'image_first' );
	$slots          = \MDS\ReleaseManager\Frontend\TemplateDefaults::normalize_release_component_order( $args['component_order'] ?? null );

	$classes = array(
		'mds-release-fallback',
		'mds-release-fallback--' . $layout,
		$has_image ? 'has-featured-image' : 'no-featured-image',
	);
	if ( 'split' === $layout && 'right' === $image_position ) {
		$classes[] = 'mds-release-fallback--image-right';
	}
	if ( 'split' === $layout && $sticky_image ) {
		$classes[] = 'mds-release-fallback--sticky-image';
	}
	if ( 'content_first' === $mobile_order ) {
		$classes[] = 'mds-release-fallback--mobile-content-first';
	}
	?>
<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" style="<?php echo esc_attr( $args['style'] ); ?>">
	<?php if ( $has_image ) : ?>
		<div class="mds-release-fallback__media"><?php echo $args['image']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Image markup is generated with WordPress image APIs and escaped fallback attributes. ?></div>
	<?php endif; ?>
	<div class="mds-release-fallback__main">
		<?php foreach ( $slots as $slot ) : ?>
			<?php if ( 'meta' === $slot && ! empty( $args['meta_html'] ) ) : ?>
				<div class="mds-release-fallback__component"><?php echo $args['meta_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered by the plugin's escaped Release Meta renderer. ?></div>
			<?php elseif ( 'player' === $slot && ! empty( $args['player_html'] ) ) : ?>
				<div class="mds-release-fallback__component"><?php echo $args['player_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered by the plugin's escaped Release Player renderer. ?></div>
			<?php elseif ( 'body' === $slot ) : ?>
				<div class="mds-release-fallback__body"><?php echo $args['content']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content has already passed through WordPress's the_content filters. ?></div>
			<?php elseif ( 'links' === $slot && ! empty( $args['links_html'] ) ) : ?>
				<div class="mds-release-fallback__component"><?php echo $args['links_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered by the plugin's escaped Release Links renderer. ?></div>
			<?php endif; ?>
		<?php endforeach; ?>
	</div>
</div>
	<?php
} )( is_array( $args ?? null ) ? $args : array() );

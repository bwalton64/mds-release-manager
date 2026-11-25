<?php
/**
 * Functional verification of every styling control across all four Gutenberg blocks:
 * Release Meta, Release Links, Release Player, and Artist Discography.
 *
 * Run with: php tests/block-styles-parity.php
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

class WP_Error {
	public $code;
	public function __construct( $code, $_message, $_data ) {
		$this->code = $code;
	}
}
function __( $message, $domain ) {
	return $message;
}
function esc_attr( $value ) {
	return (string) $value;
}
function apply_filters( $hook, $value ) {
	return $value;
}
function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}
function sanitize_text_field( $value ) {
	return trim( strip_tags( $value ) );
}
function sanitize_hex_color( $value ) {
	return preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $value ) ? $value : '';
}
function get_option( $name, $fallback = false ) {
	return 'mds_rm_block_defaults' === $name ? ( $GLOBALS['defaults'] ?? array() ) : $fallback;
}

require __DIR__ . '/../vendor/autoload.php';
use MDS\ReleaseManager\Blocks\BlockDefaults;
use MDS\ReleaseManager\Blocks\ReleaseLinks;
use MDS\ReleaseManager\Blocks\ReleaseLinksBlock;
use MDS\ReleaseManager\Blocks\ReleaseMeta;
use MDS\ReleaseManager\Frontend\Discography;

function assert_style( bool $cond, string $msg ): void {
	if ( ! $cond ) {
		throw new RuntimeException( $msg );
	}
}

// 1. Release Meta: test every style field (hex, rgba, theme preset, spacing object, typography, icons).
$meta_fields                   = ReleaseMeta::style_fields();
$meta_fields['font_size']      = array( 'type' => 'font_size' );
$meta_fields['font_style']     = array( 'type' => 'font_style' );
$meta_fields['text_align']     = array( 'type' => 'text_align' );
$meta_fields['letter_spacing'] = array( 'type' => 'length' );

$meta_attrs = array(
	'metaStyles' => array(
		'background'     => 'var(--wp--preset--color--accent-1)',
		'color'          => 'rgba(255, 255, 255, 0.9)',
		'icon_color'     => '#a5b4fc',
		'font_family'    => 'var(--wp--preset--font-family--manrope)',
		'font_size'      => 'var(--wp--preset--font-size--medium)',
		'font_weight'    => '600',
		'font_style'     => 'italic',
		'line_height'    => '1.5',
		'letter_spacing' => '0.5px',
		'text_align'     => 'center',
		'width'          => '100%',
		'gap'            => '16px',
		'padding'        => array( 'top' => '10px', 'right' => '16px', 'bottom' => '10px', 'left' => '16px' ),
		'margin'         => '12px',
		'direction'      => 'row',
		'wrap'           => 'wrap',
		'align'          => 'center',
		'vertical_align' => 'center',
		'border_style'   => 'solid',
		'border_width'   => '1px',
		'border_color'   => 'var(--wp--preset--color--contrast)',
		'radius'         => '8px',
		'icon_size'      => '18px',
		'icon_opacity'   => '0.85',
		'icon_gap'       => '8px',
	),
);
assert_style( true === BlockDefaults::validate( array( 'release-meta' => $meta_attrs ) ), 'Release Meta full style set must validate.' );
$meta_wrapper = ReleaseLinksBlock::native_style_wrapper( $meta_attrs, 'metaStyles', $meta_fields, 'meta' );
foreach ( array_keys( $meta_attrs['metaStyles'] ) as $k ) {
	$css_var = '--mds-meta-' . str_replace( '_', '-', $k ) . '-desktop:';
	assert_style( str_contains( $meta_wrapper['style'] ?? '', $css_var ), "Release Meta missing rendered CSS var {$css_var}" );
}

// 2. Release Links: test every style field (container + button styles + hover + theme preset).
$links_attrs = array(
	'linkStyles' => array(
		'background'          => 'var(--wp--preset--color--base)',
		'width'               => '100%',
		'padding'             => array( 'top' => '8px', 'bottom' => '8px' ),
		'margin'              => '0px',
		'direction'           => 'row',
		'wrap'                => 'wrap',
		'align'               => 'center',
		'vertical_align'      => 'center',
		'border_style'        => 'solid',
		'border_width'        => '1px',
		'border_color'        => '#32343d',
		'radius'              => '8px',
		'link_gap'            => '12px',
		'button_size'         => '48px',
		'button_radius'       => '50%',
		'button_color'        => '#ffffff',
		'button_background'   => 'var(--wp--preset--color--accent-1)',
		'button_border_width' => '2px',
		'button_border_style' => 'solid',
		'button_border_color' => 'rgba(255,255,255,0.2)',
		'icon_size'           => '20px',
		'hover_opacity'       => '0.8',
	),
);
assert_style( true === BlockDefaults::validate( array( 'release-links' => $links_attrs ) ), 'Release Links full style set must validate.' );
$links_wrapper = ReleaseLinksBlock::native_style_wrapper( $links_attrs, 'linkStyles', ReleaseLinks::style_fields(), 'links' );
foreach ( array_keys( $links_attrs['linkStyles'] ) as $k ) {
	$css_var = '--mds-links-' . str_replace( '_', '-', $k ) . '-desktop:';
	assert_style( str_contains( $links_wrapper['style'] ?? '', $css_var ), "Release Links missing rendered CSS var {$css_var}" );
}

// 3. Release Player: verify theme presets, rgba, hex, width presets, and shadow validate AND resolve in style_value().
$player_attrs = array(
	'backgroundColor' => 'var(--wp--preset--color--base)',
	'textColor'       => 'var(--wp--preset--color--contrast)',
	'mutedColor'      => 'rgba(243, 244, 246, 0.7)',
	'accentColor'     => '#a5b4fc',
	'playIconColor'   => '#111827',
	'borderColor'     => 'var(--wp--preset--color--accent-2)',
	'borderRadius'    => 16,
	'borderWidth'     => 2,
	'padding'         => 16,
	'maxWidth'        => '480px',
	'shadow'          => 'elevated',
);
assert_style( true === BlockDefaults::validate( array( 'release-player' => $player_attrs ) ), 'Release Player full style set (including theme presets & rgba) must validate.' );
foreach ( array( 'backgroundColor', 'textColor', 'mutedColor', 'accentColor', 'playIconColor', 'borderColor' ) as $ck ) {
	$resolved_color = ReleaseLinksBlock::style_value( $player_attrs[ $ck ], 'color' );
	assert_style( '' !== $resolved_color, "Release Player color {$ck} ({$player_attrs[$ck]}) must pass ReleaseLinksBlock::style_value()." );
}
assert_style( '480px' === ReleaseLinksBlock::style_value( $player_attrs['maxWidth'], 'width' ), 'Release Player maxWidth must pass ReleaseLinksBlock::style_value().' );

// 4. Artist Discography: verify tableStyles (including theme presets + striped dark/light fallback) emit CSS vars.
$disco_styles = array(
	'striped'            => true,
	'table-background'   => 'var(--wp--preset--color--base)',
	'table-color'        => 'var(--wp--preset--color--contrast)',
	'table-radius'       => '8px',
	'header-background'  => '#1c1d22',
	'header-color'       => '#f3f4f6',
	'body-color'         => '#e5e7eb',
	'cell-padding-top'   => '10px',
	'cell-border-style'  => 'solid',
	'cell-border-width'  => '1px',
	'cell-border-color'  => 'rgba(255,255,255,0.15)',
	'link-color'         => 'var(--wp--preset--color--accent-1)',
	'link-decoration'    => 'underline',
);
assert_style( true === BlockDefaults::validate( array( 'artist-discography' => array( 'tableStyles' => $disco_styles ) ) ), 'Artist Discography full style set must validate.' );
$disco_css = Discography::table_style_variables( $disco_styles );
foreach ( array_keys( $disco_styles ) as $k ) {
	if ( 'striped' === $k ) {
		assert_style( str_contains( $disco_css, '--mds-discography-stripe-background:' ), 'Discography striped must emit adaptive stripe-background.' );
		continue;
	}
	assert_style( str_contains( $disco_css, "--mds-discography-{$k}:" ), "Discography missing rendered CSS var --mds-discography-{$k}:" );
}

echo "All 4 block styling parity & functional checks passed.\n";

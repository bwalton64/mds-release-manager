<?php
get_header();

$upc = get_post_meta( get_the_ID(), 'upc_ean', true );
$layout = get_theme_mod( 'mds_rm_layout_style', 'split' );
$accent = get_theme_mod( 'mds_rm_primary_color', '#2271b1' );
$img = get_post_meta( get_the_ID(), 'featured_image_url', true );
?>
<div class="mds-container <?php echo esc_attr("mds-layout-{$layout}"); ?>" style="max-width:1200px; margin: 0 auto; padding: 40px 20px;">
    <style>
        .mds-layout-split { display: flex; gap: 40px; flex-wrap: wrap; }
        .mds-layout-split .mds-media { flex: 1 1 400px; }
        .mds-layout-split .mds-content { flex: 2 1 500px; }
        .mds-layout-stacked { text-align: center; }
        .mds-layout-stacked .mds-media { max-width: 500px; margin: 0 auto 30px; }
        .mds-title { color: <?php echo esc_attr($accent); ?>; margin-bottom: 10px; }
    </style>
    
    <div class="mds-media">
        <?php if ( $img ) : ?>
            <img src="<?php echo esc_url($img); ?>" alt="<?php the_title_attribute(); ?>" style="width:100%; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.1);" />
        <?php elseif ( has_post_thumbnail() ) : ?>
            <?php the_post_thumbnail( 'large', array('style' => 'width:100%; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.1);') ); ?>
        <?php endif; ?>
    </div>
    
    <div class="mds-content">
        <h1 class="mds-title"><?php the_title(); ?></h1>
        <?php if ( $upc ) echo mds_render_meta_bar_html( $upc, false ); ?>
        
        <div class="mds-about" style="margin: 25px 0; line-height:1.6;">
            <?php the_content(); ?>
        </div>
        
        <?php if ( $upc ) echo do_shortcode('[release_links upc="'.esc_attr($upc).'"]'); ?>
    </div>
</div>
<?php
get_footer();

<?php
get_header();
$img = get_post_meta( get_the_ID(), 'artist_featured_image', true );
$contact = get_post_meta( get_the_ID(), 'artist_contact', true );
?>
<div class="mds-container mds-layout-stacked" style="max-width:900px; margin: 0 auto; padding: 40px 20px; text-align: center;">
    <?php if ( $img ) : ?>
        <img src="<?php echo esc_url($img); ?>" alt="<?php the_title_attribute(); ?>" style="width:300px; height:300px; object-fit:cover; border-radius:50%; margin-bottom:20px; box-shadow:0 4px 12px rgba(0,0,0,0.1);" />
    <?php elseif ( has_post_thumbnail() ) : ?>
        <?php the_post_thumbnail( 'large', array('style' => 'width:300px; height:300px; object-fit:cover; border-radius:50%; margin-bottom:20px; box-shadow:0 4px 12px rgba(0,0,0,0.1);') ); ?>
    <?php endif; ?>
    
    <h1><?php the_title(); ?></h1>
    
    <div class="mds-about" style="margin: 25px 0; line-height:1.6; text-align:left;">
        <?php the_content(); ?>
    </div>
    
    <?php if ( $contact ) : ?>
        <div class="mds-contact" style="background:#f9f9f9; padding:20px; border-radius:8px; text-align:left;">
            <h3>Contact Information</h3>
            <?php echo wp_kses_post($contact); ?>
        </div>
    <?php endif; ?>
</div>
<?php
get_footer();

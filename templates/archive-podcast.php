<?php
/**
 * Episode listing page (/podcast/). Copy to your theme as archive-podcast.php to override.
 * Works with classic themes (get_header/get_footer) and block themes (header/footer template parts).
 */
if (!defined('ABSPATH')) exit;

$show_player = get_option('podcast_archive_player', '1') === '1';
$is_block    = function_exists('wp_is_block_theme') && wp_is_block_theme();

if ($is_block) {
    ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php
    wp_body_open();
    echo '<div class="wp-site-blocks">';
    echo do_blocks('<!-- wp:template-part {"slug":"header","tagName":"header"} /-->'); // phpcs:ignore WordPress.Security.EscapeOutput
} else {
    get_header();
}
?>
<?php $wrap = $is_block ? 'main' : 'div'; // classic themes already open their own <main>; avoid nesting a second one ?>
<<?php echo $wrap; ?> id="primary" class="site-main podcast-archive<?php echo $is_block ? ' wp-block-group' : ''; ?>">
    <?php echo PodcastingManager::breadcrumbs(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
    <header class="page-header">
        <h1 class="page-title"><?php echo esc_html(get_option('podcast_name') ?: post_type_archive_title('', false)); ?></h1>
    </header>

    <?php if (have_posts()) : ?>
        <div class="podcast-episodes">
            <?php while (have_posts()) : the_post();
                echo PodcastingManager::render_episode_item(true, $show_player); // phpcs:ignore WordPress.Security.EscapeOutput
            endwhile; ?>
        </div>
        <?php the_posts_pagination(['prev_text' => '&larr; Newer', 'next_text' => 'Older &rarr;']); ?>
    <?php else : ?>
        <p class="podcast-episodes-empty">No episodes yet.</p>
    <?php endif; ?>
</<?php echo $wrap; ?>>
<?php
if ($is_block) {
    echo do_blocks('<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->'); // phpcs:ignore WordPress.Security.EscapeOutput
    echo '</div>';
    wp_footer();
    echo '</body></html>';
} else {
    get_footer();
}

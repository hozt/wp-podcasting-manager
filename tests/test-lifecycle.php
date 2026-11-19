<?php

class LifecycleTest extends Podcasting_Manager_TestCase {
    public function test_constructors_register_all_hooks() {
        // Safe: the suite backs up and restores $wp_filter around each test.
        $m = new PodcastingManager();
        $f = new PodcastingManagerFeed();

        $this->assertSame(10, has_action('admin_menu', [$m, 'add_admin_menu']));
        $this->assertSame(10, has_action('admin_init', [$m, 'register_settings']));
        $this->assertSame(10, has_action('init', [$m, 'register_post_type']));
        $this->assertSame(10, has_action('add_meta_boxes', [$m, 'add_meta_boxes']));
        $this->assertSame(10, has_action('save_post', [$m, 'save_meta_box_data']));
        $this->assertSame(10, has_action('save_post_podcast', [$m, 'reset_number_owners']));
        $this->assertSame(10, has_action('transition_post_status', [$m, 'reset_number_owners']));
        $this->assertSame(10, has_action('graphql_register_types', [$m, 'register_graphql_fields']));
        $this->assertSame(10, has_action('admin_enqueue_scripts', [$m, 'enqueue_admin_scripts']));
        // query_vars uses a closure (not assertable by identity); its behavior is
        // covered by EpisodeUrlsTest::test_query_vars_filter_adds_episode_number.
        $this->assertSame(10, has_action('pre_get_posts', [$m, 'query_by_episode_number']));
        $this->assertSame(10, has_action('pre_get_posts', [$m, 'archive_query']));
        $this->assertSame(10, has_filter('template_include', [$m, 'archive_template']));
        $this->assertSame(99, has_action('init', [$m, 'maybe_flush_rewrite']));
        $this->assertSame(10, has_action('loop_start', [$m, 'breadcrumbs_before_loop']));
        $this->assertSame(10, has_filter('the_content', [$m, 'prepend_player']));
        $this->assertTrue(shortcode_exists('podcast_episodes'));
        $this->assertSame(10, has_filter('post_type_link', [$m, 'episode_permalink']));

        $this->assertSame(10, has_action('init', [$f, 'register_feed']));
        $this->assertSame(10, has_action('pre_get_posts', [$f, 'limit_feed_query']));
        $this->assertSame(10, has_action('wp_head', [$f, 'print_alternate_link']));
        $this->assertSame(10, has_filter('post_type_archive_feed_link', [$f, 'archive_feed_link']));
        $this->assertSame(10, has_action('save_post_podcast', [$f, 'bust_cache']));
        $this->assertSame(10, has_action('transition_post_status', [$f, 'bust_cache_for_transition']));
        $this->assertSame(10, has_action('deleted_post', [$f, 'bust_cache_for_post']));
        $this->assertSame(10, has_action('updated_post_meta', [$f, 'bust_cache_for_meta']));
        $this->assertSame(10, has_action('added_post_meta', [$f, 'bust_cache_for_meta']));
        $this->assertSame(10, has_action('updated_option', [$f, 'bust_cache_for_option']));
        $this->assertSame(10, has_action('added_option', [$f, 'bust_cache_for_option']));
    }

    public function test_register_feed_adds_podcast_feed() {
        $this->feed()->register_feed();
        $this->assertContains('podcast', $GLOBALS['wp_rewrite']->feeds);
        $this->assertSame(get_feed_link('podcast'), PodcastingManagerFeed::get_feed_url());
    }

    public function test_activate_registers_everything_and_flushes() {
        delete_option('rewrite_rules');
        PodcastingManager::activate();

        $this->assertContains('podcast', $GLOBALS['wp_rewrite']->feeds);
        $this->assertTrue(post_type_exists('podcast'));
        $this->assertArrayHasKey('^podcast/([0-9]+)/?$', (array) get_option('rewrite_rules'));
    }
}

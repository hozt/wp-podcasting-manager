<?php

class ListingsTest extends Podcasting_Manager_TestCase {
    private function render_item($id, $excerpt = true, $player = false) {
        global $post;
        $post = get_post($id);
        setup_postdata($post);
        try {
            return PodcastingManager::render_episode_item($excerpt, $player);
        } finally {
            wp_reset_postdata();
        }
    }

    public function test_episode_item_labels_and_date() {
        update_option('date_format', 'F j, Y');
        $id = $this->create_episode(
            ['post_title' => 'The One', 'post_excerpt' => 'Teaser'],
            ['_episode_number' => '3', '_season_number' => '2', '_episode_date' => '2024-02-03']
        );

        $html = $this->render_item($id);
        $this->assertStringContainsString('The One', $html);
        $this->assertStringContainsString('S2 Episode 3', $html);
        $this->assertStringContainsString('February 3, 2024', $html);
        $this->assertStringContainsString('Teaser', $html);
        $this->assertStringContainsString('href="' . esc_url(get_permalink($id)) . '"', $html);
    }

    public function test_episode_item_without_season_defaults_date() {
        $id = $this->create_episode(['post_title' => 'Plain'], ['_episode_number' => '1']);
        $html = $this->render_item($id);
        $this->assertStringContainsString('Episode 1', $html);
        $this->assertStringNotContainsString('S0', $html);
    }

    public function test_episode_item_player_and_excerpt_toggles() {
        $id = $this->create_episode(
            ['post_excerpt' => 'Teaser'],
            ['_episode_number' => '1', '_mp3_file' => 'https://example.com/a.mp3']
        );

        $this->assertStringNotContainsString('<audio', $this->render_item($id, true, false));
        $this->assertStringContainsString('<audio', $this->render_item($id, true, true));
        $this->assertStringNotContainsString('Teaser', $this->render_item($id, false, false));

        $no_mp3 = $this->create_episode([], ['_episode_number' => '2']);
        $this->assertStringNotContainsString('<audio', $this->render_item($no_mp3, true, true));
    }

    public function test_episode_item_hides_player_for_protected() {
        $id = $this->create_episode(
            ['post_password' => 'secret'],
            ['_episode_number' => '1', '_mp3_file' => 'https://example.com/s.mp3']
        );
        $html = $this->render_item($id, true, true);
        $this->assertStringNotContainsString('<audio', $html);
        $this->assertStringNotContainsString('example.com/s.mp3', $html);
    }

    public function test_shortcode_lists_in_order_with_limit() {
        $this->create_episode(['post_title' => 'One'], ['_episode_number' => '1']);
        $this->create_episode(['post_title' => 'Two'], ['_episode_number' => '2']);
        $this->create_episode(['post_title' => 'Three'], ['_episode_number' => '3']);
        $this->create_episode(['post_title' => 'Unnumbered']); // excluded
        $this->create_episode(['post_title' => 'Draft', 'post_status' => 'draft'], ['_episode_number' => '4']);

        $html = do_shortcode('[podcast_episodes limit="2"]');
        $this->assertSame(2, substr_count($html, '<article'));
        $this->assertLessThan(strpos($html, 'Two'), strpos($html, 'Three')); // DESC default
        $this->assertStringNotContainsString('Unnumbered', $html);
        $this->assertStringNotContainsString('Draft', $html);

        $asc = do_shortcode('[podcast_episodes limit="3" order="asc"]');
        $this->assertLessThan(strpos($asc, 'Two'), strpos($asc, 'One'));

        $bogus_order = do_shortcode('[podcast_episodes limit="3" order="random"]');
        $this->assertLessThan(strpos($bogus_order, 'Two'), strpos($bogus_order, 'Three')); // falls back to DESC
    }

    public function test_shortcode_filters_season_and_toggles() {
        $this->create_episode(['post_title' => 'S1', 'post_excerpt' => 'E1'], ['_episode_number' => '1', '_season_number' => '1', '_mp3_file' => 'https://example.com/1.mp3']);
        $this->create_episode(['post_title' => 'S2'], ['_episode_number' => '2', '_season_number' => '2']);

        $html = do_shortcode('[podcast_episodes season="2"]');
        $this->assertStringContainsString('S2', $html);
        $this->assertStringNotContainsString('>S1<', $html);

        $player = do_shortcode('[podcast_episodes player="yes" excerpt="no" paginate="no"]');
        $this->assertStringContainsString('<audio', $player);
        $this->assertStringNotContainsString('E1', $player);
        $this->assertStringNotContainsString('podcast-episodes-nav', $player);
        $clamped = do_shortcode('[podcast_episodes limit="0"]'); // clamped to 1 episode
        $this->assertSame(1, substr_count($clamped, '<article'));
        $this->assertStringContainsString('S2', $clamped);
    }

    public function test_shortcode_paginates_and_handles_empty() {
        $this->create_episode(['post_title' => 'One'], ['_episode_number' => '1']);
        $this->create_episode(['post_title' => 'Two'], ['_episode_number' => '2']);

        $page1 = do_shortcode('[podcast_episodes limit="1"]');
        $this->assertStringContainsString('Older', $page1);
        $this->assertStringNotContainsString('Newer', $page1);

        $page2 = $this->with_get(['ep_page' => '2'], fn() => do_shortcode('[podcast_episodes limit="1"]'));
        $this->assertStringContainsString('Newer', $page2);
        $this->assertStringContainsString('One', $page2);

        $this->assertStringContainsString('No episodes yet', do_shortcode('[podcast_episodes season="99"]'));
    }

    public function test_archive_query_orders_filters_and_limits() {
        $this->use_pretty_permalinks();
        $this->create_episode(['post_title' => 'One'], ['_episode_number' => '1', '_season_number' => '1']);
        $this->create_episode(['post_title' => 'Two'], ['_episode_number' => '2', '_season_number' => '2']);
        $this->create_episode(['post_title' => 'NoNumber']);

        $this->go_to(get_post_type_archive_link('podcast'));
        global $wp_query;
        $this->assertTrue($wp_query->is_post_type_archive('podcast'));
        $titles = array_map(fn($p) => $p->post_title, $wp_query->posts);
        $this->assertSame(['Two', 'One'], $titles);
        $this->assertSame(10, $wp_query->get('posts_per_page'));

        $this->go_to(get_post_type_archive_link('podcast') . '?season=2');
        $titles = array_map(fn($p) => $p->post_title, $GLOBALS['wp_query']->posts);
        $this->assertSame(['Two'], $titles);

        $this->go_to(get_post_type_archive_link('podcast') . '?season=2x'); // ignored
        $this->assertSame(2, count($GLOBALS['wp_query']->posts));

        add_filter('podcast_archive_per_page', fn() => 1);
        $this->go_to(get_post_type_archive_link('podcast'));
        $this->assertSame(1, count($GLOBALS['wp_query']->posts));
    }

    public function test_archive_query_preserves_existing_meta_clauses() {
        $this->use_pretty_permalinks();
        $this->create_episode(['post_title' => 'One'], ['_episode_number' => '1', '_season_number' => '1']);
        $this->create_episode(['post_title' => 'Two'], ['_episode_number' => '2', '_season_number' => '2']);

        add_action('pre_get_posts', function ($q) {
            if ($q->is_main_query() && !is_admin()) {
                $q->set('meta_query', [['key' => '_season_number', 'value' => '2']]);
            }
        }, 9);
        $this->go_to(get_post_type_archive_link('podcast'));

        $mq = $GLOBALS['wp_query']->get('meta_query');
        $this->assertArrayHasKey('number', $mq); // ours, still top-level for orderby
        $found = false;
        foreach ($mq as $clause) {
            if (is_array($clause) && ($clause['key'] ?? '') === '_season_number' && ($clause['value'] ?? '') === '2') {
                $found = true;
            }
            if (is_array($clause) && isset($clause[0]['key']) && $clause[0]['key'] === '_season_number') {
                $found = true; // nested group form
            }
        }
        $this->assertTrue($found, 'existing clause preserved');
        $this->assertSame(['Two'], array_map(fn($p) => $p->post_title, $GLOBALS['wp_query']->posts));
    }

    public function test_archive_query_ignores_other_queries() {
        $q = new WP_Query(); // not the main query
        $this->manager()->archive_query($q);
        $this->assertSame('', $q->get('posts_per_page'));

        $this->go_to(home_url('/'));
        $before = $GLOBALS['wp_query']->get('posts_per_page');
        $this->manager()->archive_query($GLOBALS['wp_query']); // main query, not the archive
        $this->assertSame($before, $GLOBALS['wp_query']->get('posts_per_page'));
    }

    public function test_archive_template_selection() {
        $this->use_pretty_permalinks();
        $m = $this->manager();

        $this->go_to(get_post_type_archive_link('podcast'));
        $this->assertStringEndsWith('templates/archive-podcast.php', $m->archive_template('/theme/index.php'));
        $this->assertSame('/theme/archive-podcast.php', $m->archive_template('/theme/archive-podcast.php'));

        $this->go_to(home_url('/'));
        $this->assertSame('/theme/index.php', $m->archive_template('/theme/index.php'));
    }

    public function test_archive_template_renders_block_theme() {
        $this->use_pretty_permalinks();
        update_option('podcast_name', 'Block Show');
        $this->create_episode(['post_title' => 'Ep One'], ['_episode_number' => '1', '_mp3_file' => 'https://example.com/1.mp3']);

        $original = get_stylesheet();
        switch_theme('twentytwentyfive');
        try {
            $this->assertTrue(wp_is_block_theme());
            $this->go_to(get_post_type_archive_link('podcast'));
            ob_start();
            include dirname(__DIR__) . '/templates/archive-podcast.php';
            $html = ob_get_clean();
        } finally {
            switch_theme($original);
        }

        $this->assertStringContainsString('<!doctype html>', strtolower($html));
        $this->assertStringContainsString('Block Show', $html);
        $this->assertStringContainsString('Ep One', $html);
        $this->assertStringContainsString('<audio', $html);
        $this->assertStringContainsString('podcast-breadcrumbs', $html);
    }

    public function test_archive_template_renders_classic_theme_and_empty_state() {
        $this->use_pretty_permalinks();
        register_theme_directory(DIR_TESTDATA . '/themedir1');

        $original = get_stylesheet();
        switch_theme('default');
        try {
            $this->assertFalse(wp_is_block_theme());
            $this->go_to(get_post_type_archive_link('podcast')); // no episodes
            ob_start();
            include dirname(__DIR__) . '/templates/archive-podcast.php';
            $empty = ob_get_clean();
        } finally {
            switch_theme($original);
        }

        $this->assertStringContainsString('No episodes yet', $empty);
        $this->assertStringContainsString('podcast-breadcrumbs', $empty);
    }
}

<?php

class SingleTest extends Podcasting_Manager_TestCase {
    public function set_up() {
        parent::set_up();
        // Deterministic plain permalinks (pretty-permalink tests opt in explicitly).
        // In-memory rewrite state otherwise leaks between tests.
        $this->set_permalink_structure('');
    }

    /** Render the_content for an episode inside its loop, plus anything echoed on loop_start. */
    private function render_episode_page($id, $content = 'BODY') {
        $this->go_to(get_permalink($id));
        ob_start();
        while (have_posts()) {
            the_post(); // fires loop_start, where classic themes echo the breadcrumbs
            echo apply_filters('the_content', $content);
        }
        wp_reset_postdata();
        return ob_get_clean();
    }

    public function test_player_transcript_and_video_embed() {
        add_filter('pre_oembed_result', fn() => '<div class="fake-embed">vid</div>');
        $id = $this->create_episode(
            ['post_content' => 'Shownotes'],
            [
                '_episode_number' => '1',
                '_mp3_file' => 'https://example.com/a.mp3',
                '_video_url' => 'https://www.youtube.com/watch?v=abc',
                '_transcript' => 'Hello <b>world</b>',
            ]
        );

        $html = $this->render_episode_page($id);
        $this->assertStringContainsString('podcast-player', $html);
        $this->assertStringContainsString('<audio', $html);
        $this->assertStringContainsString('podcast-video', $html);
        $this->assertStringContainsString('fake-embed', $html);
        $this->assertStringContainsString('<details class="podcast-transcript">', $html);
        $this->assertStringContainsString('Hello <b>world</b>', $html);
        $this->assertStringContainsString('BODY', $html);
    }

    public function test_video_falls_back_to_link_and_can_stand_alone() {
        add_filter('pre_oembed_result', fn() => false); // not embeddable
        $id = $this->create_episode([], ['_episode_number' => '1', '_video_url' => 'https://example.com/v.mp4']);

        $html = $this->render_episode_page($id);
        $this->assertStringNotContainsString('podcast-player', $html); // no mp3, no player
        $this->assertStringContainsString('Watch the video', $html);
        $this->assertStringContainsString('https://example.com/v.mp4', $html);
    }

    public function test_empty_fields_render_nothing_extra() {
        $id = $this->create_episode([], ['_episode_number' => '1']);
        $html = $this->render_episode_page($id);
        $this->assertStringNotContainsString('podcast-player', $html);
        $this->assertStringNotContainsString('podcast-video', $html);
        $this->assertStringNotContainsString('podcast-transcript', $html);
    }

    public function test_protected_episode_leaks_nothing() {
        add_filter('pre_oembed_result', fn() => '<div class="fake-embed">vid</div>');
        $id = $this->create_episode(
            ['post_password' => 'secret'],
            [
                '_episode_number' => '1',
                '_mp3_file' => 'https://example.com/secret.mp3',
                '_video_url' => 'https://example.com/secret.mp4',
                '_transcript' => 'Secret words',
            ]
        );

        $html = $this->render_episode_page($id);
        $this->assertStringNotContainsString('secret.mp3', $html);
        $this->assertStringNotContainsString('secret.mp4', $html);
        $this->assertStringNotContainsString('Secret words', $html);
        $this->assertStringNotContainsString('<audio', $html);
        $this->assertStringContainsString('podcast-breadcrumbs', $html);
    }

    public function test_player_only_on_episode_pages_inside_the_loop() {
        $post_id = self::factory()->post->create(['post_type' => 'post', 'post_title' => 'Blog']);
        $this->assertStringNotContainsString('podcast-player', $this->render_episode_page($post_id));

        // Singular episode but outside the loop: untouched.
        $ep = $this->create_episode([], ['_episode_number' => '1', '_mp3_file' => 'https://example.com/a.mp3']);
        $this->go_to(get_permalink($ep));
        $this->assertSame('BODY', $this->manager()->prepend_player('BODY'));
    }

    public function test_breadcrumbs_single_archive_and_home() {
        $this->use_pretty_permalinks();
        $id = $this->create_episode(['post_title' => ' crumb ep '], ['_episode_number' => '1']);

        $this->go_to(get_permalink($id));
        $single = PodcastingManager::breadcrumbs();
        $this->assertStringContainsString('Home', $single);
        $this->assertStringContainsString(get_post_type_archive_link('podcast'), $single);
        $this->assertStringContainsString('aria-current="page"', $single);
        $this->assertStringContainsString('BreadcrumbList', $single);

        $this->go_to(get_post_type_archive_link('podcast'));
        $archive = PodcastingManager::breadcrumbs();
        $this->assertStringContainsString('Podcasts', $archive);

        $this->go_to(home_url('/'));
        $this->assertSame('', PodcastingManager::breadcrumbs());
    }

    public function test_breadcrumbs_can_be_disabled_or_relabeled() {
        $id = $this->create_episode(['post_title' => 'x'], ['_episode_number' => '1']);
        $this->go_to(get_permalink($id));

        update_option('podcast_breadcrumbs', '0');
        $this->assertSame('', PodcastingManager::breadcrumbs());

        update_option('podcast_breadcrumbs', '1');
        add_filter('podcast_show_breadcrumbs', '__return_false');
        $this->assertSame('', PodcastingManager::breadcrumbs());

        remove_all_filters('podcast_show_breadcrumbs');
        add_filter('podcast_breadcrumb_label', fn() => 'Cast');
        $this->assertStringContainsString('Cast', PodcastingManager::breadcrumbs());
    }

    public function test_breadcrumbs_without_archive_link() {
        $slug = 'cast-crumb-' . uniqid();
        update_option('podcast_archive_slug', $slug);
        self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug]);
        $id = $this->create_episode(['post_title' => 'Lonely'], ['_episode_number' => '1']);
        try {
            $this->manager()->register_post_type(); // archive off: no archive link
            $this->assertFalse(get_post_type_archive_link('podcast'));
            $this->go_to(get_permalink($id));
            $html = PodcastingManager::breadcrumbs();
            $this->assertStringContainsString('Home', $html);
            $this->assertStringNotContainsString('Podcasts', $html);
            $this->assertStringContainsString('Lonely', $html);
        } finally {
            delete_option('podcast_archive_slug');
            $this->manager()->register_post_type();
        }
    }

    public function test_breadcrumbs_before_loop_classic_vs_block() {
        $id = $this->create_episode(['post_title' => 'x'], ['_episode_number' => '1']);
        $this->go_to(get_permalink($id));

        register_theme_directory(DIR_TESTDATA . '/themedir1');
        $original = get_stylesheet();
        switch_theme('default');
        try {
            $this->assertFalse(wp_is_block_theme());
            ob_start();
            $this->manager()->breadcrumbs_before_loop($GLOBALS['wp_query']);
            $first = ob_get_clean();
            $this->assertStringContainsString('podcast-breadcrumbs', $first);

            ob_start(); // printed flag: second call is silent
            $this->manager()->breadcrumbs_before_loop($GLOBALS['wp_query']);
            $this->assertSame('', ob_get_clean());
        } finally {
            switch_theme($original);
        }
        $this->reset_manager_static('crumbs_printed', false);

        $original = get_stylesheet();
        switch_theme('twentytwentyfive');
        try {
            $this->assertTrue(wp_is_block_theme());
            ob_start();
            $this->manager()->breadcrumbs_before_loop($GLOBALS['wp_query']);
            $this->assertSame('', ob_get_clean());
        } finally {
            switch_theme($original);
        }
    }

    public function test_breadcrumbs_before_loop_ignores_other_contexts() {
        $this->go_to(home_url('/'));
        ob_start();
        $this->manager()->breadcrumbs_before_loop($GLOBALS['wp_query']);
        $this->assertSame('', ob_get_clean());

        $this->manager()->breadcrumbs_before_loop(new WP_Query()); // not main: silent
        $this->assertTrue(true); // no output, no crash
    }
}

<?php

class EpisodeUrlsTest extends Podcasting_Manager_TestCase {
    public function test_episode_base_defaults_and_sanitizes() {
        $this->assertSame('podcast', $this->manager()->episode_base());

        add_filter('podcast_episode_base', fn() => 'My Shows!');
        $this->assertSame('my-shows', $this->manager()->episode_base());

        remove_all_filters('podcast_episode_base');
        add_filter('podcast_episode_base', fn() => '');
        $this->assertSame('podcast', $this->manager()->episode_base());

        remove_all_filters('podcast_episode_base');
        add_filter('podcast_episode_base', fn() => ['x']);
        $this->assertSame('podcast', $this->manager()->episode_base());
    }

    public function test_owner_gets_number_url_duplicate_keeps_slug_url() {
        $this->use_pretty_permalinks();
        $old = $this->create_episode(['post_name' => 'old-five'], ['_episode_number' => '5', '_season_number' => '1']);
        $new = $this->create_episode(['post_name' => 'new-five'], ['_episode_number' => '5', '_season_number' => '2']);

        $this->assertSame(home_url('/podcast/5/'), get_permalink($old));
        $this->assertStringContainsString('new-five', get_permalink($new));
        $this->assertStringNotContainsString('/podcast/5/', get_permalink($new));
    }

    public function test_permalink_falls_back_without_number_or_pretty_permalinks() {
        $this->use_pretty_permalinks();
        $id = $this->create_episode(['post_name' => 'no-number']);
        $this->assertStringContainsString('no-number', get_permalink($id));

        $this->set_permalink_structure('');
        $num = $this->create_episode([], ['_episode_number' => '9']);
        $this->assertStringNotContainsString('/podcast/9/', get_permalink($num));
    }

    public function test_number_url_resolves_oldest_and_only_one() {
        $this->use_pretty_permalinks();
        $old = $this->create_episode(['post_title' => 'Old'], ['_episode_number' => '7', '_season_number' => '1']);
        $this->create_episode(['post_title' => 'New'], ['_episode_number' => '7', '_season_number' => '2']);

        $this->go_to(home_url('/podcast/7/'));

        global $wp_query;
        $this->assertTrue($wp_query->is_single());
        $this->assertSame(1, count($wp_query->posts));
        $this->assertSame($old, get_queried_object_id());
    }

    public function test_unknown_number_url_is_404() {
        $this->use_pretty_permalinks();
        $this->go_to(home_url('/podcast/9876/'));
        $this->assertTrue(is_404());
    }

    public function test_number_query_ignores_secondary_queries() {
        $q = new WP_Query();
        $this->assertFalse($q->is_main_query());
        $this->manager()->query_by_episode_number($q);
        $this->assertSame('', $q->get('meta_query'));
    }

    public function test_archive_slug_cleaning() {
        $m = $this->manager();
        $this->assertSame('podcast', $m->archive_slug());

        update_option('podcast_archive_slug', 'My Shows!');
        $this->assertSame('my-shows', $m->archive_slug());

        foreach (['feed', 'wp-json', 'page', ''] as $bad) {
            update_option('podcast_archive_slug', $bad);
            $this->assertSame('podcast', $m->archive_slug(), "slug '$bad'");
        }

        update_option('podcast_archive_slug', ['array']);
        $this->assertSame('podcast', $m->archive_slug());
    }

    public function test_conflicting_page_detection() {
        // Each slug is looked up once: the lookup is memoized per request (L-5).
        $empty = 'cast-empty-' . uniqid();
        update_option('podcast_archive_slug', $empty);
        $this->assertNull($this->manager()->archive_conflicting_page());

        $slug = 'cast-conflict-' . uniqid();
        update_option('podcast_archive_slug', $slug);
        $page = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug]);
        $this->assertSame($page, $this->manager()->archive_conflicting_page()->ID);

        $draft = 'cast-draft-' . uniqid();
        update_option('podcast_archive_slug', $draft);
        self::factory()->post->create(['post_type' => 'page', 'post_status' => 'draft', 'post_name' => $draft]);
        $this->assertNull($this->manager()->archive_conflicting_page());
    }

    public function test_conflicting_page_lookup_is_cached_per_request() {
        global $wpdb;
        $slug = 'cast-cache-' . uniqid();
        update_option('podcast_archive_slug', $slug);

        $before = $wpdb->num_queries;
        $this->manager()->archive_conflicting_page();
        $after_first = $wpdb->num_queries;
        $this->manager()->archive_conflicting_page();
        $after_second = $wpdb->num_queries;

        $this->assertGreaterThan($before, $after_first); // first call queries
        $this->assertSame($after_first, $after_second); // second call is cached
    }

    public function test_maybe_flush_rewrite_tracks_state() {
        $m = $this->manager();
        delete_option('podcast_archive_state');

        $m->maybe_flush_rewrite();
        $this->assertSame('podcast|on|feed-alias|ep:podcast', get_option('podcast_archive_state'));

        // Second run with no change rewrites nothing.
        $rules = get_option('rewrite_rules');
        $m->maybe_flush_rewrite();
        $this->assertSame($rules, get_option('rewrite_rules'));

        // A conflicting page flips the state to off.
        $slug = 'cast-flush-' . uniqid();
        update_option('podcast_archive_slug', $slug);
        self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug]);
        $m->maybe_flush_rewrite();
        $this->assertSame("$slug|off|feed-alias|ep:podcast", get_option('podcast_archive_state'));
    }

    public function test_query_vars_filter_adds_episode_number() {
        $this->assertContains('episode_number', apply_filters('query_vars', ['p']));
    }
}

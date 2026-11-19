<?php

class PostTypeTest extends Podcasting_Manager_TestCase {
    public function test_post_type_registration() {
        $this->assertTrue(post_type_exists('podcast'));
        $this->assertTrue(shortcode_exists('podcast_episodes'));

        $o = get_post_type_object('podcast');
        $this->assertSame('podcast', $o->rewrite['slug']);
        $this->assertSame('podcast', $o->has_archive);
        $this->assertTrue($o->show_in_graphql);
        $this->assertContains('ContentNode', (array) $o->graphql_interfaces);
    }

    public function test_archive_disables_when_page_conflicts() {
        $slug = 'cast-pt-' . uniqid();
        update_option('podcast_archive_slug', $slug);
        self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug]);
        try {
            $this->manager()->register_post_type();
            $this->assertFalse(get_post_type_object('podcast')->has_archive);
        } finally {
            delete_option('podcast_archive_slug');
            $this->manager()->register_post_type();
        }
    }

    public function test_rewrite_rules_include_episode_and_feed_alias() {
        $this->use_pretty_permalinks();
        flush_rewrite_rules();
        $rules = get_option('rewrite_rules');
        $this->assertArrayHasKey('^podcast/([0-9]+)/?$', $rules);
        $this->assertArrayHasKey('^podcast/feed/?$', $rules);
    }

    public function test_feed_alias_follows_archive_slug() {
        $slug = 'cast-alias-' . uniqid();
        update_option('podcast_archive_slug', $slug);
        $this->use_pretty_permalinks();
        try {
            $this->manager()->register_post_type();
            flush_rewrite_rules();
            $this->assertArrayHasKey('^' . preg_quote($slug, '#') . '/feed/?$', get_option('rewrite_rules'));
        } finally {
            delete_option('podcast_archive_slug');
            $this->manager()->register_post_type();
            flush_rewrite_rules();
        }
    }
}

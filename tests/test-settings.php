<?php

class SettingsTest extends Podcasting_Manager_TestCase {
    private function sanitize($setting, $value) {
        $this->manager()->register_settings();
        $cb = $GLOBALS['wp_registered_settings'][$setting]['sanitize_callback'] ?? null;
        $this->assertNotEmpty($cb, "no sanitizer for $setting");
        return $cb($value);
    }

    public function test_choice_sanitizers() {
        $this->assertSame('yes', $this->sanitize('podcast_explicit_rating', 'yes'));
        $this->assertSame('', $this->sanitize('podcast_explicit_rating', 'sometimes'));
        $this->assertSame('', $this->sanitize('podcast_explicit_rating', ['yes']));
        $this->assertSame('serial', $this->sanitize('podcast_series', 'serial'));
        $this->assertSame('', $this->sanitize('podcast_series', 'x'));
        $this->assertSame('CC BY 4.0', $this->sanitize('podcast_license', 'CC BY 4.0'));
        $this->assertSame('', $this->sanitize('podcast_license', 'Custom License'));
        $this->assertSame('weekly', $this->sanitize('podcast_update_frequency', 'weekly'));
        $this->assertSame('', $this->sanitize('podcast_update_frequency', 'yearly'));
        $this->assertSame('en-gb', $this->sanitize('podcast_language', 'en-gb'));
        $this->assertSame('', $this->sanitize('podcast_language', 'xx'));
        $this->assertSame('', $this->sanitize('podcast_language', ''));
        $this->assertSame('text/plain', $this->sanitize('podcast_default_transcript_type', 'text/plain'));
        $this->assertSame('text/html', $this->sanitize('podcast_default_transcript_type', 'bogus'));
    }

    public function test_scalar_sanitizers() {
        $this->assertSame('Hi', $this->sanitize('podcast_name', 'Hi'));
        $this->assertSame('a@b.co', $this->sanitize('podcast_owner_email', 'a@b.co'));
        $this->assertSame('', $this->sanitize('podcast_owner_email', 'not-an-email'));
        $this->assertSame('https://e.com/x', $this->sanitize('podcast_image', 'https://e.com/x'));
        $this->assertSame('', $this->sanitize('podcast_image', 'javascript:alert(1)'));
        $this->assertSame('1', $this->sanitize('podcast_locked', '1'));
        $this->assertSame('', $this->sanitize('podcast_locked', ''));
        $this->assertSame('1', $this->sanitize('podcast_autonumber', 'yes'));
        $this->assertSame('1', $this->sanitize('podcast_archive_player', true));
        $this->assertSame('', $this->sanitize('podcast_breadcrumbs', false));
        $this->assertSame(3, $this->sanitize('podcast_current_season', '3'));
        $this->assertSame('', $this->sanitize('podcast_current_season', ''));
        $this->assertSame(300, $this->sanitize('podcast_feed_limit', '999'));
        $this->assertSame(1, $this->sanitize('podcast_feed_limit', '0'));
        $this->assertSame(20, $this->sanitize('podcast_feed_limit', '20'));
        $this->assertSame('my-shows', $this->sanitize('podcast_archive_slug', 'My Shows!'));
        $this->assertSame('podcast', $this->sanitize('podcast_archive_slug', 'feed'));
    }

    public function test_categories_sanitizer_registration() {
        $this->assertSame('News,Arts', $this->sanitize('podcast_categories', ['News', 'Bogus', 'Arts']));
    }

    public function test_settings_page_renders_all_sections() {
        update_option('podcast_name', 'My Show');

        ob_start();
        $this->manager()->settings_page();
        $html = ob_get_clean();

        $this->assertStringContainsString('<h1>Podcast Settings</h1>', $html);
        $this->assertStringContainsString('My Show', $html);
        $this->assertStringContainsString(PodcastingManagerFeed::get_feed_url(), $html);
        $this->assertStringContainsString('<h2>Episode Defaults</h2>', $html);
        $this->assertStringContainsString('<h2>Podcast Links</h2>', $html);
        $this->assertStringContainsString('podcast-upload-image', $html);
        $this->assertStringContainsString('podcast-image-preview', $html);
        $this->assertStringContainsString('name="podcast_categories[]"', $html);
        $this->assertStringContainsString('type="checkbox"', $html);
        $this->assertStringContainsString('<textarea', $html);
        $this->assertStringNotContainsString('already uses', $html); // no conflict notice
    }

    public function test_settings_page_keeps_legacy_select_values() {
        update_option('podcast_license', 'Custom License');

        ob_start();
        $this->manager()->settings_page();
        $html = ob_get_clean();

        $this->assertStringContainsString('value="Custom License" selected', $html);
    }

    public function test_settings_page_warns_on_archive_conflict() {
        $slug = 'cast-settings-' . uniqid();
        update_option('podcast_archive_slug', $slug);
        self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => 'Shows']);

        ob_start();
        $this->manager()->settings_page();
        $html = ob_get_clean();

        $this->assertStringContainsString('already uses', $html);
        $this->assertStringContainsString("/$slug/", $html);
    }

    public function test_admin_menu_registers_options_page() {
        $this->as_admin();
        global $submenu;
        $submenu = [];
        $this->manager()->add_admin_menu();

        $slugs = array_column($submenu['options-general.php'] ?? [], 2);
        $this->assertContains('podcast-settings', $slugs);
    }
}

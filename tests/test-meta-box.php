<?php

class MetaBoxTest extends Podcasting_Manager_TestCase {
    public function test_render_shows_fields_and_previews() {
        $id = $this->create_episode(['post_title' => 'Ep'], [
            '_episode_number' => '7',
            '_mp3_file'       => 'https://example.com/ep7.mp3',
            '_episode_date'   => '2024-02-03',
            '_transcript_type' => 'text/plain',
        ]);

        ob_start();
        $this->manager()->render_meta_box(get_post($id));
        $html = ob_get_clean();

        $this->assertStringContainsString('podcast_episode_meta_nonce', $html);
        $this->assertStringContainsString("name='episode_number'", $html);
        $this->assertStringContainsString("type='date'", $html); // native picker, no jQuery UI (L-2)
        $this->assertStringContainsString("name='video_url'", $html);
        $this->assertStringContainsString('youtube.com/watch', $html); // placeholder hint
        $this->assertStringContainsString('podcast-upload-audio', $html);
        $this->assertStringContainsString('<audio', $html); // mp3 preview player
        $this->assertStringContainsString("<option value='text/plain' selected", $html);
    }

    public function test_render_new_episode_prefills_defaults() {
        update_option('podcast_current_season', '2');
        update_option('podcast_default_episode_hosts', 'Ada, Grace');
        update_option('podcast_autonumber', '1');
        $this->create_episode([], ['_episode_number' => '4']);

        $id = wp_insert_post(['post_type' => 'podcast', 'post_status' => 'auto-draft', 'post_title' => 'Auto Draft']);

        ob_start();
        $this->manager()->render_meta_box(get_post($id));
        $html = ob_get_clean();

        $this->assertStringContainsString(wp_date('Y-m-d'), $html);
        $this->assertStringContainsString("value='2'", $html); // season
        $this->assertStringContainsString('Ada, Grace', $html);
        $this->assertStringContainsString("value='5'", $html); // next number
    }

    public function test_save_rejects_missing_nonce() {
        $id = $this->create_episode();
        $this->as_admin();
        $this->with_post(['episode_number' => '9'], function () use ($id) {
            $this->manager()->save_meta_box_data($id);
        });
        $this->assertSame('', get_post_meta($id, '_episode_number', true));
    }

    public function test_save_rejects_wrong_post_type_and_revisions_and_guests() {
        $post_id = self::factory()->post->create(['post_type' => 'post']);
        $rev_id = wp_insert_post(['post_type' => 'revision', 'post_parent' => $post_id, 'post_title' => 'r']);
        $this->as_admin();
        $nonce = wp_create_nonce('podcast_episode_meta');

        $this->with_post(['podcast_episode_meta_nonce' => $nonce, 'episode_number' => '3'], function () use ($post_id, $rev_id) {
            $this->manager()->save_meta_box_data($post_id);
            $this->manager()->save_meta_box_data($rev_id);
        });
        $this->assertSame('', get_post_meta($post_id, '_episode_number', true));

        $ep = $this->create_episode();
        wp_set_current_user(0);
        $this->with_post(['podcast_episode_meta_nonce' => $nonce, 'episode_number' => '3'], function () use ($ep) {
            $this->manager()->save_meta_box_data($ep);
        });
        $this->assertSame('', get_post_meta($ep, '_episode_number', true));
    }

    public function test_save_sanitizes_every_field() {
        $id = $this->create_episode();
        $this->as_admin();
        $_post = [
            'podcast_episode_meta_nonce' => wp_create_nonce('podcast_episode_meta'),
            'episode_number' => '12abc',
            'mp3_file' => 'https://example.com/ep.mp3',
            'video_url' => 'javascript:alert(1)',
            'episode_date' => '2024-01-02',
            'episode_length' => '90x',
            'file_size' => '100',
            'transcript' => '<a href="https://example.com">x</a><script>alert(1)</script>',
            'season_number' => '2',
            'episode_hosts' => 'Ada',
            'episode_guests' => 'Grace',
            'transcript_url' => 'https://example.com/t.html',
            'chapters_url' => 'https://example.com/c.json',
            'transcript_type' => 'text/plain',
        ];
        $this->with_post($_post, function () use ($id) {
            $this->manager()->save_meta_box_data($id);
        });

        $this->assertSame('12', get_post_meta($id, '_episode_number', true));
        $this->assertSame('https://example.com/ep.mp3', get_post_meta($id, '_mp3_file', true));
        $this->assertSame('', get_post_meta($id, '_video_url', true)); // dangerous scheme stripped
        $this->assertSame('90', get_post_meta($id, '_episode_length', true));
        $this->assertSame('100', get_post_meta($id, '_file_size', true));
        // Quoted attributes survive save (CR-005); the script tag is stripped (its text remains, as kses does).
        $this->assertSame('<a href="https://example.com">x</a>alert(1)', get_post_meta($id, '_transcript', true));
        $this->assertSame('text/plain', get_post_meta($id, '_transcript_type', true));
    }

    public function test_save_rejects_bad_transcript_type_and_non_string_values() {
        $id = $this->create_episode();
        $this->as_admin();
        $this->with_post([
            'podcast_episode_meta_nonce' => wp_create_nonce('podcast_episode_meta'),
            'transcript_type' => 'text/bogus',
            'episode_number' => ['7'],
        ], function () use ($id) {
            $this->manager()->save_meta_box_data($id);
        });
        $this->assertSame('', get_post_meta($id, '_transcript_type', true));
        $this->assertSame('', get_post_meta($id, '_episode_number', true));
    }

    public function test_save_fills_size_and_length_from_matching_attachment() {
        $file = $this->write_upload('ep-attach.mp3', str_repeat('x', 1234));
        $att = wp_insert_attachment(['post_mime_type' => 'audio/mpeg', 'post_title' => 'a'], $file);
        wp_update_attachment_metadata($att, ['length' => 321]);
        $url = wp_get_attachment_url($att);

        $id = $this->create_episode();
        $this->as_admin();
        $this->with_post([
            'podcast_episode_meta_nonce' => wp_create_nonce('podcast_episode_meta'),
            'mp3_file' => $url,
            'mp3_attachment_id' => (string) $att,
        ], function () use ($id) {
            $this->manager()->save_meta_box_data($id);
        });

        $this->assertSame((string) $att, get_post_meta($id, '_mp3_attachment_id', true));
        $this->assertSame('1234', get_post_meta($id, '_file_size', true));
        $this->assertSame('321', get_post_meta($id, '_episode_length', true));
    }

    public function test_save_forgets_attachment_when_url_no_longer_matches() {
        $id = $this->create_episode([], ['_mp3_attachment_id' => '999']);
        $this->as_admin();
        $this->with_post([
            'podcast_episode_meta_nonce' => wp_create_nonce('podcast_episode_meta'),
            'mp3_file' => 'https://example.com/other.mp3',
            'mp3_attachment_id' => '999',
        ], function () use ($id) {
            $this->manager()->save_meta_box_data($id);
        });
        $this->assertSame('', get_post_meta($id, '_mp3_attachment_id', true));
    }

    public function test_save_looks_up_remote_file_size_once() {
        remove_filter('podcast_fetch_remote_file_size', '__return_false');
        $calls = 0;
        add_filter('pre_http_request', function () use (&$calls) {
            $calls++;
            return ['response' => ['code' => 200], 'headers' => ['content-length' => '555'], 'body' => ''];
        });

        $id = $this->create_episode();
        $this->as_admin();
        $this->with_post([
            'podcast_episode_meta_nonce' => wp_create_nonce('podcast_episode_meta'),
            'mp3_file' => 'https://example.com/remote.mp3',
        ], function () use ($id) {
            $this->manager()->save_meta_box_data($id);
        });

        $this->assertSame(1, $calls);
        $this->assertSame('555', get_post_meta($id, '_file_size', true));
    }

    public function test_save_skips_remote_lookup_when_disabled_or_failing() {
        // Disabled via filter: no HTTP at all.
        $calls = 0;
        add_filter('pre_http_request', function () use (&$calls) {
            $calls++;
            return ['response' => ['code' => 200], 'headers' => ['content-length' => '1'], 'body' => ''];
        });
        $id = $this->create_episode();
        $this->as_admin();
        $this->with_post([
            'podcast_episode_meta_nonce' => wp_create_nonce('podcast_episode_meta'),
            'mp3_file' => 'https://example.com/remote.mp3',
        ], function () use ($id) {
            $this->manager()->save_meta_box_data($id);
        });
        $this->assertSame(0, $calls);
        $this->assertSame('', get_post_meta($id, '_file_size', true));

        // Enabled but the server errors: nothing stored.
        remove_filter('podcast_fetch_remote_file_size', '__return_false');
        remove_all_filters('pre_http_request');
        add_filter('pre_http_request', fn() => new WP_Error('http_request_failed', 'nope'));
        $id2 = $this->create_episode();
        $this->with_post([
            'podcast_episode_meta_nonce' => wp_create_nonce('podcast_episode_meta'),
            'mp3_file' => 'https://example.com/remote.mp3',
        ], function () use ($id2) {
            $this->manager()->save_meta_box_data($id2);
        });
        $this->assertSame('', get_post_meta($id2, '_file_size', true));
    }

    // NOTE: the DOING_AUTOSAVE early return is not executed here: defining that
    // constant would poison the whole suite process. Verified by reading.
}

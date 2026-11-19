<?php

class AdminTest extends Podcasting_Manager_TestCase {
    public function set_up() {
        parent::set_up();
        $GLOBALS['wp_scripts'] = new WP_Scripts();
        $GLOBALS['wp_styles'] = new WP_Styles();
    }

    public function test_add_meta_boxes_registers_podcast_box() {
        global $wp_meta_boxes;
        $wp_meta_boxes = [];
        $this->manager()->add_meta_boxes();
        $this->assertArrayHasKey('podcast_meta', $wp_meta_boxes['podcast']['normal']['high']);
    }

    public function test_settings_screen_enqueues_media_and_inline_help() {
        $this->manager()->enqueue_admin_scripts('settings_page_podcast-settings');

        $after = $GLOBALS['wp_scripts']->get_data('jquery', 'after');
        $this->assertStringContainsString('podcast-upload-image', implode("\n", (array) $after));
    }

    public function test_podcast_edit_screen_enqueues_audio_help_without_datepicker() {
        $GLOBALS['post'] = get_post($this->create_episode());
        $this->manager()->enqueue_admin_scripts('post.php');

        $after = $GLOBALS['wp_scripts']->get_data('jquery', 'after');
        $this->assertStringContainsString('podcast-upload-audio', implode("\n", (array) $after));
        // L-2: no jQuery UI datepicker anymore (native date input instead).
        $this->assertFalse(wp_script_is('jquery-ui-datepicker', 'enqueued'));
        $this->assertFalse(wp_style_is('jquery-ui', 'enqueued'));
    }

    public function test_other_screens_enqueue_nothing() {
        $GLOBALS['post'] = get_post(self::factory()->post->create(['post_type' => 'post']));
        $this->manager()->enqueue_admin_scripts('post.php');
        $this->assertEmpty($GLOBALS['wp_scripts']->get_data('jquery', 'after'));

        unset($GLOBALS['post']);
        $this->manager()->enqueue_admin_scripts('post.php'); // no current post: quiet no-op
        $this->assertEmpty($GLOBALS['wp_scripts']->get_data('jquery', 'after'));

        $this->manager()->enqueue_admin_scripts('edit.php');
        $this->assertEmpty($GLOBALS['wp_scripts']->get_data('jquery', 'after'));
    }
}

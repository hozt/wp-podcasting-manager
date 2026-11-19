<?php

class OptionsTest extends Podcasting_Manager_TestCase {
    public function test_choice_lists_have_expected_keys() {
        $this->assertArrayHasKey('CC BY 4.0', PodcastingManagerOptions::licenses());
        $this->assertArrayHasKey('weekly', PodcastingManagerOptions::frequencies());
        $this->assertSame(['Weekly', 'Weekly', 'FREQ=WEEKLY'], PodcastingManagerOptions::frequencies()['weekly']);
        $this->assertArrayHasKey('en-us', PodcastingManagerOptions::languages());
        $this->assertSame('HTML', PodcastingManagerOptions::transcript_types()['text/html']);
        $this->assertContains('feed', PodcastingManagerOptions::reserved_slugs());
        $this->assertContains('wp-json', PodcastingManagerOptions::reserved_slugs());
        $this->assertArrayHasKey('serial', PodcastingManagerOptions::series_types());
        $this->assertArrayHasKey('yes', PodcastingManagerOptions::explicit_ratings());
        $this->assertContains('Books', PodcastingManagerOptions::categories()['Arts']);
    }

    public function test_valid_categories_flattens_parents_and_children() {
        $valid = PodcastingManagerOptions::valid_categories();
        $this->assertContains('News', $valid);
        $this->assertContains('Arts > Books', $valid);
        $this->assertNotContains('Bogus', $valid);
    }

    public function test_sanitize_categories_keeps_up_to_three_valid_unique() {
        $this->assertSame(
            'News,Arts,History',
            PodcastingManagerOptions::sanitize_categories(['News', 'Bogus', 'News', 'Arts', 'History', 'Science'])
        );
    }

    public function test_sanitize_categories_accepts_child_values_and_comma_strings() {
        $this->assertSame('Arts > Books', PodcastingManagerOptions::sanitize_categories(['Arts > Books']));
        $this->assertSame('News,Arts', PodcastingManagerOptions::sanitize_categories('News, Arts,Bogus'));
    }

    public function test_sanitize_categories_ignores_non_strings_and_unslashes() {
        $this->assertSame('News', PodcastingManagerOptions::sanitize_categories([['x'], 42, 'News']));
        $this->assertSame('', PodcastingManagerOptions::sanitize_categories([]));
    }

    public function test_sanitize_choice_accepts_only_known_keys() {
        $cb = PodcastingManagerOptions::sanitize_choice(['a' => 'A', '' => 'None']);
        $this->assertSame('a', $cb('a'));
        $this->assertSame('', $cb(''));
        $this->assertSame('', $cb('zzz'));
        $this->assertSame('', $cb(['a']));
        $this->assertSame('', $cb(null));
    }
}

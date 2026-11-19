<?php

class SmokeTest extends WP_UnitTestCase {
    public function test_plugin_loads() {
        $this->assertTrue(class_exists('PodcastingManager'));
        $this->assertTrue(class_exists('PodcastingManagerFeed'));
        $this->assertTrue(class_exists('PodcastingManagerOptions'));
        $this->assertTrue(post_type_exists('podcast'));
    }
}

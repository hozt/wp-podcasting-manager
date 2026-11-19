<?php
// Shared base for the plugin suite: episode factory, manager instances without
// re-registering hooks, and resets for the plugin's per-process static caches.

abstract class Podcasting_Manager_TestCase extends WP_UnitTestCase {
    public function set_up() {
        parent::set_up();
        wp_cache_flush(); // in-memory object cache (transients!) is NOT rolled back between tests
        $this->reset_manager_static('crumbs_printed', false);
        $this->reset_manager_static('number_owners', null);
        // Keep saves offline by default; the L-4 tests opt back in with a mocked transport.
        add_filter('podcast_fetch_remote_file_size', '__return_false');
    }

    /** A manager instance that does NOT run the constructor (hooks are already registered). */
    protected function manager() {
        return (new ReflectionClass('PodcastingManager'))->newInstanceWithoutConstructor();
    }

    protected function feed() {
        return (new ReflectionClass('PodcastingManagerFeed'))->newInstanceWithoutConstructor();
    }

    protected function reset_manager_static($prop, $value) {
        if (!property_exists('PodcastingManager', $prop)) {
            return; // older code under test may not have this cache yet
        }
        $r = new ReflectionProperty('PodcastingManager', $prop);
        $r->setValue(null, $value);
    }

    protected function call_private($object_or_class, $method, ...$args) {
        $r = new ReflectionMethod($object_or_class, $method);
        return $r->invokeArgs(is_object($object_or_class) ? $object_or_class : null, $args);
    }

    protected function create_episode(array $args = [], array $meta = []) {
        $id = self::factory()->post->create(
            array_merge(['post_type' => 'podcast', 'post_status' => 'publish'], $args)
        );
        foreach ($meta as $k => $v) {
            update_post_meta($id, $k, $v);
        }
        return $id;
    }

    /**
     * Pretty permalinks that actually match: WP only registers CPT rewrite
     * tags/permastructs when a structure exists at registration time, and the
     * suite boots with plain permalinks — so re-register after switching.
     */
    protected function use_pretty_permalinks($structure = '/%postname%/') {
        $this->set_permalink_structure($structure);
        $this->manager()->register_post_type();
        flush_rewrite_rules();
    }

    /** Run $fn with $_GET replaced, restoring it afterwards. */
    protected function with_get(array $get, callable $fn) {
        $old = $_GET;
        $_GET = $get;
        try {
            return $fn();
        } finally {
            $_GET = $old;
        }
    }

    /** Run $fn with $_POST replaced, restoring it afterwards. */
    protected function with_post(array $post, callable $fn) {
        $old = $_POST;
        $_POST = $post;
        try {
            return $fn();
        } finally {
            $_POST = $old;
        }
    }

    protected function with_cookie($name, $value, callable $fn) {
        $old = $_COOKIE;
        $_COOKIE[$name] = $value;
        try {
            return $fn();
        } finally {
            $_COOKIE = $old;
        }
    }

    /** Write a file into the (dated) uploads dir, creating it first. Returns the full path. */
    protected function write_upload($name, $content) {
        $dir = wp_upload_dir()['path'];
        wp_mkdir_p($dir);
        $path = trailingslashit($dir) . $name;
        file_put_contents($path, $content);
        return $path;
    }

    protected function as_admin() {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        return $admin;
    }
}

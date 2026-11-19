<?php
// WPGraphQL is optional in production, so these tests stub its registration API
// and exercise the plugin's resolvers directly.

if (!function_exists('register_graphql_object_type')) {
    function register_graphql_object_type($name, $config) {
        $GLOBALS['graphql_test_types'][$name] = $config;
    }
    function register_graphql_field($type, $name, $config) {
        $GLOBALS['graphql_test_fields']["$type.$name"] = $config;
    }
    function register_graphql_fields($type, $fields) {
        foreach ($fields as $name => $config) {
            $GLOBALS['graphql_test_fields']["$type.$name"] = $config;
        }
    }
}

class GraphQLTest extends Podcasting_Manager_TestCase {
    public function set_up() {
        parent::set_up();
        $GLOBALS['graphql_test_types'] = [];
        $GLOBALS['graphql_test_fields'] = [];
        $this->manager()->register_graphql_fields();
    }

    private function resolve($key, $post_id) {
        $fn = $GLOBALS['graphql_test_fields']["podcast.$key"]['resolve'];
        return $fn((object) ['ID' => $post_id]);
    }

    public function test_settings_type_and_resolver() {
        $this->assertArrayHasKey('PodcastSettings', $GLOBALS['graphql_test_types']);
        foreach (['name', 'feedUrl', 'podcastAppleLink', 'podcastSpotifyLink'] as $field) {
            $this->assertArrayHasKey($field, $GLOBALS['graphql_test_types']['PodcastSettings']['fields']);
        }

        update_option('podcast_name', 'GQL Show');
        update_option('podcast_apple_link', 'https://example.com/apple');
        $resolve = $GLOBALS['graphql_test_fields']['RootQuery.podcastSettings']['resolve'];
        $settings = $resolve();
        $this->assertSame('GQL Show', $settings['name']);
        $this->assertSame('https://example.com/apple', $settings['podcastAppleLink']);
        $this->assertSame(PodcastingManagerFeed::get_feed_url(), $settings['feedUrl']);
    }

    public function test_episode_resolvers_return_meta() {
        $id = $this->create_episode([], [
            '_episode_number' => '7', '_season_number' => '2', '_episode_date' => '2024-05-06',
            '_mp3_file' => 'https://example.com/g.mp3', '_video_url' => 'https://example.com/g.mp4',
            '_episode_length' => '90', '_file_size' => '800',
            '_transcript' => 'Words', '_transcript_url' => 'https://example.com/g.html',
            '_chapters_url' => 'https://example.com/g.json', '_episode_guests' => 'Kim',
        ]);

        $this->assertSame(7, $this->resolve('episodeNumber', $id));
        $this->assertSame(2, $this->resolve('seasonNumber', $id));
        $this->assertSame('2024-05-06', $this->resolve('episodeDate', $id));
        $this->assertSame('https://example.com/g.mp3', $this->resolve('mp3File', $id));
        $this->assertSame('https://example.com/g.mp4', $this->resolve('videoUrl', $id));
        $this->assertSame(90, $this->resolve('episodeLength', $id));
        $this->assertSame(800, $this->resolve('fileSize', $id));
        $this->assertSame('Words', $this->resolve('transcript', $id));
        $this->assertSame('https://example.com/g.html', $this->resolve('transcriptUrl', $id));
        $this->assertSame('https://example.com/g.json', $this->resolve('chaptersUrl', $id));
        $this->assertSame('Kim', $this->resolve('guests', $id));
    }

    public function test_protected_episode_gates_content_fields() {
        $id = $this->create_episode(['post_password' => 'secret'], [
            '_episode_number' => '7', '_season_number' => '2', '_episode_date' => '2024-05-06',
            '_mp3_file' => 'https://example.com/g.mp3', '_video_url' => 'https://example.com/g.mp4',
            '_episode_length' => '90', '_file_size' => '800',
            '_transcript' => 'Words', '_transcript_url' => 'https://example.com/g.html',
            '_chapters_url' => 'https://example.com/g.json', '_episode_guests' => 'Kim',
        ]);

        // Identifiers stay public, matching the archive listing.
        $this->assertSame(7, $this->resolve('episodeNumber', $id));
        $this->assertSame(2, $this->resolve('seasonNumber', $id));
        $this->assertSame('2024-05-06', $this->resolve('episodeDate', $id));

        // Everything content-carrying is hidden (M-1).
        foreach (['mp3File', 'videoUrl', 'episodeLength', 'fileSize', 'transcript', 'transcriptUrl', 'chaptersUrl', 'guests'] as $key) {
            $this->assertNull($this->resolve($key, $id), $key);
        }
    }

    public function test_cookie_unlocks_gated_fields() {
        require_once ABSPATH . WPINC . '/class-phpass.php';
        $hasher = new PasswordHash(8, true);
        $id = $this->create_episode(['post_password' => 'secret'], ['_mp3_file' => 'https://example.com/g.mp3']);

        $this->assertNull($this->resolve('mp3File', $id));
        $this->with_cookie('wp-postpass_' . COOKIEHASH, $hasher->HashPassword('secret'), function () use ($id) {
            $this->assertSame('https://example.com/g.mp3', $this->resolve('mp3File', $id));
        });
    }
}

<?php

class FeedTest extends Podcasting_Manager_TestCase {
    public function set_up() {
        parent::set_up();
        $this->set_permalink_structure('');
    }

    private function render_feed($url = null) {
        $this->go_to($url ?: PodcastingManagerFeed::get_feed_url());
        ob_start();
        $this->feed()->render_feed();
        return ob_get_clean();
    }

    private function full_show_options() {
        update_option('podcast_name', 'My Show');
        update_option('podcast_description', 'A <b>great</b> show');
        update_option('podcast_owner', 'Ada Owner');
        update_option('podcast_owner_email', 'ada@example.com');
        update_option('podcast_image', 'https://example.com/art.jpg');
        update_option('podcast_license', 'CC BY 4.0');
        update_option('podcast_location', 'Austin, TX');
        update_option('podcast_hosts', 'Ada, Grace');
        update_option('podcast_explicit_rating', 'yes');
        update_option('podcast_series', 'serial');
        update_option('podcast_keywords', 'tech, science');
        update_option('podcast_categories', 'News,Arts > Books');
        update_option('podcast_locked', '1');
        update_option('podcast_donation_link', 'https://example.com/donate');
        update_option('podcast_trailer', 'https://example.com/trailer.mp3');
        update_option('podcast_update_frequency', 'weekly');
        update_option('podcast_language', 'en-gb');
    }

    public function test_channel_tags_and_full_item() {
        $this->full_show_options();
        $this->create_episode(
            ['post_title' => 'Ep One', 'post_excerpt' => 'Excerpt one', 'post_content' => 'Content one'],
            [
                '_episode_number' => '1', '_season_number' => '2', '_episode_date' => '2024-01-10',
                '_mp3_file' => 'https://example.com/ep1.mp3', '_file_size' => '111', '_episode_length' => '60',
                '_transcript_url' => 'https://example.com/t1.html', '_chapters_url' => 'https://example.com/c1.json',
                '_episode_hosts' => 'Ada', '_episode_guests' => 'Grace, Linus',
            ]
        );

        $xml = $this->render_feed();

        $this->assertStringContainsString('<title>My Show</title>', $xml);
        $this->assertStringContainsString('<description><![CDATA[A great show]]></description>', $xml);
        $this->assertStringContainsString('<language>en-gb</language>', $xml);
        $this->assertStringContainsString('<itunes:author>Ada Owner</itunes:author>', $xml);
        $this->assertStringContainsString('<itunes:name>Ada Owner</itunes:name>', $xml);
        $this->assertStringContainsString('<itunes:email>ada@example.com</itunes:email>', $xml);
        $this->assertStringContainsString('<itunes:image href="https://example.com/art.jpg"', $xml);
        $this->assertStringContainsString('<itunes:explicit>true</itunes:explicit>', $xml);
        $this->assertStringContainsString('<itunes:type>serial</itunes:type>', $xml);
        $this->assertStringContainsString('<itunes:category text="News" />', $xml);
        $this->assertStringContainsString('<itunes:category text="Arts">', $xml);
        $this->assertStringContainsString('<itunes:category text="Books" />', $xml);
        $this->assertStringContainsString('<itunes:keywords>tech, science</itunes:keywords>', $xml);
        $this->assertStringContainsString('<podcast:locked owner="ada@example.com">yes</podcast:locked>', $xml);
        $this->assertStringContainsString('<podcast:funding url="https://example.com/donate">', $xml);
        $this->assertStringContainsString('<podcast:location>Austin, TX</podcast:location>', $xml);
        $this->assertStringContainsString('<podcast:person role="host">Ada</podcast:person>', $xml);
        $this->assertStringContainsString('<podcast:person role="host">Grace</podcast:person>', $xml);
        $this->assertStringContainsString('url="https://example.com/trailer.mp3"', $xml);
        $this->assertStringContainsString('type="audio/mpeg"', $xml);
        $this->assertStringContainsString('<podcast:updateFrequency complete="false" rrule="FREQ=WEEKLY">Weekly</podcast:updateFrequency>', $xml);
        $this->assertMatchesRegularExpression('#<podcast:guid>[0-9a-f-]{36}</podcast:guid>#', $xml);

        $this->assertStringContainsString('<title>Ep One</title>', $xml);
        $this->assertStringContainsString('<pubDate>Wed, 10 Jan 2024 00:00:00 +0000</pubDate>', $xml);
        $this->assertStringContainsString('<description><![CDATA[Excerpt one]]></description>', $xml);
        $this->assertStringContainsString('Content one', $xml);
        $this->assertStringContainsString('<enclosure url="https://example.com/ep1.mp3" length="111" type="audio/mpeg" />', $xml);
        $this->assertStringContainsString('<itunes:duration>60</itunes:duration>', $xml);
        $this->assertStringContainsString('<itunes:episode>1</itunes:episode>', $xml);
        $this->assertStringContainsString('<podcast:episode>1</podcast:episode>', $xml);
        $this->assertStringContainsString('<itunes:season>2</itunes:season>', $xml);
        $this->assertStringContainsString('<podcast:season>2</podcast:season>', $xml);
        $this->assertStringContainsString('<podcast:transcript url="https://example.com/t1.html" type="text/html" />', $xml);
        $this->assertStringContainsString('<podcast:chapters url="https://example.com/c1.json" type="application/json+chapters" />', $xml);
        $this->assertStringContainsString('<podcast:person role="guest">Grace</podcast:person>', $xml);
        $this->assertStringContainsString('<podcast:person role="guest">Linus</podcast:person>', $xml);
    }

    public function test_items_order_by_episode_date_newest_first() {
        $this->create_episode(['post_title' => 'OldDated'], ['_episode_number' => '1', '_mp3_file' => 'https://example.com/a.mp3', '_episode_date' => '2024-01-01']);
        $this->create_episode(['post_title' => 'NewDated'], ['_episode_number' => '2', '_mp3_file' => 'https://example.com/b.mp3', '_episode_date' => '2024-03-01']);
        $this->create_episode(['post_title' => 'Undated'], ['_episode_number' => '3', '_mp3_file' => 'https://example.com/c.mp3']); // post date = now

        $xml = $this->render_feed();
        $pos = fn($t) => strpos($xml, "<title>$t</title>");
        $this->assertLessThan($pos('NewDated'), $pos('Undated')); // now beats March
        $this->assertLessThan($pos('OldDated'), $pos('NewDated')); // March beats January
    }

    public function test_feed_excludes_protected_audioless_and_drafts() {
        $this->create_episode(['post_title' => 'Secret', 'post_password' => 'pw'], ['_episode_number' => '1', '_mp3_file' => 'https://example.com/s.mp3']);
        $this->create_episode(['post_title' => 'Silent'], ['_episode_number' => '2']);
        $this->create_episode(['post_title' => 'Drafty', 'post_status' => 'draft'], ['_episode_number' => '3', '_mp3_file' => 'https://example.com/d.mp3']);
        $this->create_episode(['post_title' => 'Public'], ['_episode_number' => '4', '_mp3_file' => 'https://example.com/p.mp3']);

        $xml = $this->render_feed();
        $this->assertStringNotContainsString('Secret', $xml);
        $this->assertStringNotContainsString('s.mp3', $xml);
        $this->assertStringNotContainsString('Silent', $xml);
        $this->assertStringNotContainsString('Drafty', $xml);
        $this->assertStringContainsString('Public', $xml);
        $this->assertSame(1, substr_count($xml, '<item>'));
    }

    public function test_feed_limit_option() {
        update_option('podcast_feed_limit', '2');
        for ($i = 1; $i <= 3; $i++) {
            $this->create_episode(['post_title' => "Ep$i"], ['_episode_number' => (string) $i, '_mp3_file' => "https://example.com/$i.mp3"]);
        }
        $this->assertSame(2, substr_count($this->render_feed(), '<item>'));
    }

    public function test_minimal_feed_and_empty_state() {
        $xml = $this->render_feed(); // no options, no episodes

        $this->assertStringContainsString('<title>' . get_option('blogname') . '</title>', $xml); // blog name fallback
        $this->assertStringContainsString('<itunes:explicit>false</itunes:explicit>', $xml);
        $this->assertStringContainsString('<itunes:type>episodic</itunes:type>', $xml);
        $this->assertStringNotContainsString('<item>', $xml);
        $this->assertStringNotContainsString('itunes:image', $xml);
        $this->assertStringNotContainsString('itunes:author', $xml);
        $this->assertStringNotContainsString('podcast:locked', $xml);
        $this->assertStringNotContainsString('podcast:funding', $xml);
        $this->assertStringNotContainsString('podcast:location', $xml);
        $this->assertStringNotContainsString('updateFrequency', $xml);
        $this->assertMatchesRegularExpression('#<lastBuildDate>\w+, \d+ \w+ \d{4} \d+:\d+:\d+ \+0000</lastBuildDate>#', $xml);
    }

    public function test_minimal_item_branches() {
        $this->create_episode(
            ['post_title' => 'Bare', 'post_content' => 'Just some words here for the trimmed excerpt fallback text.'],
            ['_mp3_file' => 'https://example.com/bare.ogg']
        );

        $xml = $this->render_feed();
        $this->assertStringContainsString('length="0"', $xml);
        $this->assertStringContainsString('type="audio/ogg"', $xml);
        $this->assertStringNotContainsString('itunes:duration', $xml);
        $this->assertStringNotContainsString('itunes:episode', $xml);
        $this->assertStringNotContainsString('itunes:season', $xml);
        $this->assertStringNotContainsString('podcast:transcript', $xml);
        $this->assertStringNotContainsString('podcast:chapters', $xml);
        $this->assertStringNotContainsString('podcast:person', $xml);
        $this->assertStringContainsString('Just some words here', $xml); // trimmed-content excerpt
    }

    public function test_enclosure_falls_back_to_attachment_size() {
        $file = $this->write_upload('feed-attach.mp3', str_repeat('x', 4321));
        $att = wp_insert_attachment(['post_mime_type' => 'audio/mpeg', 'post_title' => 'a'], $file);
        $this->create_episode(
            ['post_title' => 'Sized'],
            ['_episode_number' => '1', '_mp3_file' => wp_get_attachment_url($att), '_mp3_attachment_id' => (string) $att]
        );

        $this->assertStringContainsString('length="4321"', $this->render_feed());
    }

    public function test_item_thumb_and_transcript_type_override() {
        $thumb = $this->create_episode(['post_title' => 'Thumb'], [
            '_episode_number' => '1', '_mp3_file' => 'https://example.com/t.mp3',
            '_transcript_url' => 'https://example.com/t.srt', '_transcript_type' => 'application/srt',
            '_thumbnail_id' => '123',
        ]);
        add_filter('wp_get_attachment_image_src', fn() => ['https://example.com/thumb.jpg', 1, 1, false]);

        $xml = $this->render_feed();
        $this->assertStringContainsString('<itunes:image href="https://example.com/thumb.jpg" />', $xml);
        $this->assertStringContainsString('<podcast:transcript url="https://example.com/t.srt" type="application/srt" />', $xml);
    }

    public function test_trailer_mime_and_irregular_frequency() {
        update_option('podcast_trailer', 'https://example.com/t.m4a');
        update_option('podcast_update_frequency', 'irregular');
        $this->create_episode([], ['_episode_number' => '1', '_mp3_file' => 'https://example.com/a.mp3']);

        $xml = $this->render_feed();
        $this->assertStringContainsString('type="audio/mp4"', $xml);
        $this->assertStringContainsString('<podcast:updateFrequency complete="false">Irregular</podcast:updateFrequency>', $xml);
    }

    public function test_locked_needs_email() {
        update_option('podcast_locked', '1');
        $this->create_episode([], ['_episode_number' => '1', '_mp3_file' => 'https://example.com/a.mp3']);
        $this->assertStringNotContainsString('podcast:locked', $this->render_feed());
    }

    public function test_render_is_cached_and_busted() {
        $this->create_episode(['post_title' => 'Cached'], ['_episode_number' => '1', '_mp3_file' => 'https://example.com/a.mp3']);

        $first = $this->render_feed();
        $this->assertStringContainsString('Cached', $first);
        $this->assertSame($first, $this->render_feed(), 'second render served from transient');

        $v0 = get_option(PodcastingManagerFeed::CACHE_VERSION_OPTION);
        update_post_meta($this->create_episode(), '_mp3_file', 'https://example.com/b.mp3'); // whitelisted key
        $this->assertNotEquals($v0, get_option(PodcastingManagerFeed::CACHE_VERSION_OPTION));

        $other = $this->create_episode(); // creating busts; measure after
        $v1 = get_option(PodcastingManagerFeed::CACHE_VERSION_OPTION);
        update_post_meta($other, '_edit_lock', 'x'); // not feed output
        $this->assertSame($v1, get_option(PodcastingManagerFeed::CACHE_VERSION_OPTION));

        $regular = self::factory()->post->create(['post_type' => 'post']);
        $v2 = get_option(PodcastingManagerFeed::CACHE_VERSION_OPTION);
        update_post_meta($regular, '_mp3_file', 'https://example.com/c.mp3'); // not an episode
        $this->assertSame($v2, get_option(PodcastingManagerFeed::CACHE_VERSION_OPTION));
    }

    public function test_bust_hooks() {
        $v = fn() => get_option(PodcastingManagerFeed::CACHE_VERSION_OPTION);
        $ep = $this->create_episode();

        do_action('save_post_podcast', $ep, get_post($ep), true);
        $this->assertNotEquals(false, $v());

        $before = $v();
        do_action('transition_post_status', 'publish', 'draft', get_post($ep));
        $this->assertNotEquals($before, $v());

        $before = $v();
        do_action('transition_post_status', 'publish', 'publish', get_post($ep)); // unchanged
        $regular = self::factory()->post->create(['post_type' => 'post']);
        do_action('transition_post_status', 'publish', 'draft', get_post($regular)); // not an episode
        do_action('deleted_post', $regular, get_post($regular)); // not an episode
        update_option('some_random_option', 'x'); // not a podcast option
        $this->assertSame($before, $v());

        do_action('deleted_post', $ep, get_post($ep));
        $this->assertNotEquals($before, $v());

        $before = $v();
        update_option('podcast_name', 'Busted'); // podcast option
        $this->assertNotEquals($before, $v());

        $before = $v(); // the version option itself must not recurse
        $this->feed()->bust_cache_for_option(PodcastingManagerFeed::CACHE_VERSION_OPTION);
        $this->feed()->bust_cache_for_option(['array']);
        $this->assertSame($before, $v());
    }

    public function test_etag_returns_304_for_matching_none_match() {
        $this->create_episode([], ['_episode_number' => '1', '_mp3_file' => 'https://example.com/a.mp3']);
        $body = $this->render_feed();
        $etag = '"' . md5($body) . '"';

        $old = $_SERVER['HTTP_IF_NONE_MATCH'] ?? null;
        $_SERVER['HTTP_IF_NONE_MATCH'] = $etag;
        try {
            $this->assertSame('', $this->render_feed());
            $this->assertSame(304, http_response_code());
        } finally {
            if ($old === null) {
                unset($_SERVER['HTTP_IF_NONE_MATCH']);
            } else {
                $_SERVER['HTTP_IF_NONE_MATCH'] = $old;
            }
            // NB: no http_response_code(200) reset — PHP warns once a status header was sent.
        }
    }

    public function test_paged_feed_skips_cache() {
        $this->create_episode([], ['_episode_number' => '1', '_mp3_file' => 'https://example.com/a.mp3']);
        delete_option(PodcastingManagerFeed::CACHE_VERSION_OPTION);

        $this->go_to(PodcastingManagerFeed::get_feed_url());
        $GLOBALS['wp_query']->is_paged = true; // the flag is computed at parse time
        $this->assertTrue($GLOBALS['wp_query']->is_paged());
        ob_start();
        $this->feed()->render_feed();
        ob_end_clean();

        $key = 'podcast_feed_' . md5((string) get_option(PodcastingManagerFeed::CACHE_VERSION_OPTION, '0'));
        $this->assertFalse(get_transient($key));
    }

    public function test_feed_query_setup_and_guard() {
        update_option('podcast_feed_limit', '5');
        for ($i = 1; $i <= 6; $i++) {
            $this->create_episode([], ['_episode_number' => (string) $i, '_mp3_file' => "https://example.com/$i.mp3"]);
        }
        $this->go_to(PodcastingManagerFeed::get_feed_url());
        $q = $GLOBALS['wp_query'];
        $this->assertSame('podcast', $q->get('post_type'));
        $this->assertFalse($q->get('has_password'));
        $this->assertSame(5, $q->get('posts_per_rss'));
        $this->assertCount(5, $q->posts, 'feed limit actually limits the query');

        $other = new WP_Query(); // not the feed query: untouched
        $this->feed()->limit_feed_query($other);
        $this->assertSame('', $other->get('post_type'));
    }

    public function test_private_helpers() {
        $mime = fn($u) => $this->call_private('PodcastingManagerFeed', 'mime_type', $u);
        $this->assertSame('audio/mpeg', $mime('https://e.com/a.mp3'));
        $this->assertSame('audio/mp4', $mime('https://e.com/a.m4a'));
        $this->assertSame('video/mp4', $mime('https://e.com/a.mp4'));
        $this->assertSame('audio/ogg', $mime('https://e.com/a.oga'));
        $this->assertSame('audio/mpeg', $mime('https://e.com/a.xyz'));
        $this->assertSame('audio/mpeg', $mime('not a url'));

        $explicit = fn($v) => $this->call_private('PodcastingManagerFeed', 'explicit', $v);
        $this->assertSame('true', $explicit('yes'));
        $this->assertSame('true', $explicit(' Explicit '));
        $this->assertSame('true', $explicit('1'));
        $this->assertSame('false', $explicit('no'));
        $this->assertSame('false', $explicit('bogus'));

        $cdata = fn($v) => $this->call_private('PodcastingManagerFeed', 'cdata', $v);
        $this->assertSame('<![CDATA[x]]>', $cdata('x'));
        $this->assertSame('<![CDATA[a]]]]><![CDATA[>b]]>', $cdata('a]]>b'));

        $this->assertSame(
            'Thu, 01 Jan 1970 00:00:00 +0000',
            $this->call_private('PodcastingManagerFeed', 'rfc2822', 0)
        );

        $split = fn($v) => $this->call_private('PodcastingManagerFeed', 'split_list', $v);
        $this->assertSame(['a', 2 => 'b'], $split(' a,,b ')); // array_filter preserves keys
        $this->assertSame([], $split(''));

        $tree = $this->call_private('PodcastingManagerFeed', 'category_tree', 'News,Arts > Books');
        $this->assertSame(['News' => [], 'Arts' => ['Books']], $tree);

        $ts = fn($p) => $this->call_private('PodcastingManagerFeed', 'item_timestamp', $p);
        $dated = $this->create_episode([], ['_episode_date' => '2024-06-01']);
        $this->assertSame(strtotime('2024-06-01'), $ts(get_post($dated)));
        $bogus = $this->create_episode([], ['_episode_date' => 'not-a-date']);
        $this->assertSame(strtotime(get_post($bogus)->post_date_gmt . ' UTC'), $ts(get_post($bogus)));
        $plain = $this->create_episode();
        $this->assertSame(strtotime(get_post($plain)->post_date_gmt . ' UTC'), $ts(get_post($plain)));
    }

    public function test_guid_is_stable_uuid_v5() {
        delete_option('podcast_guid');
        $g1 = PodcastingManagerFeed::get_podcast_guid();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $g1
        );
        $this->assertSame($g1, PodcastingManagerFeed::get_podcast_guid());

        update_option('podcast_guid', 'custom-guid');
        $this->assertSame('custom-guid', PodcastingManagerFeed::get_podcast_guid());
    }

    public function test_feed_url_alternate_link_archive_link_and_registration() {
        $this->assertSame(get_feed_link('podcast'), PodcastingManagerFeed::get_feed_url());
        $this->assertContains('podcast', $GLOBALS['wp_rewrite']->feeds);

        update_option('podcast_name', 'Linked');
        ob_start();
        $this->feed()->print_alternate_link();
        $linked = ob_get_clean();
        $this->assertStringContainsString('title="Linked"', $linked);
        $this->assertStringContainsString(PodcastingManagerFeed::get_feed_url(), $linked);

        delete_option('podcast_name');
        ob_start();
        $this->feed()->print_alternate_link();
        $this->assertStringContainsString('title="' . get_option('blogname') . '"', ob_get_clean());

        $this->use_pretty_permalinks();
        $this->go_to(get_post_type_archive_link('podcast'));
        $this->assertSame(PodcastingManagerFeed::get_feed_url(), $this->feed()->archive_feed_link('orig', 'rss2'));

        $this->go_to(home_url('/'));
        $this->assertSame('orig', $this->feed()->archive_feed_link('orig', 'rss2'));
    }
}

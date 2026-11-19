<?php
/**
 * Plugin Name: Podcasting Manager
 * Description: Manage podcasts with admin interface, GraphQL support and a Podcasting 2.0 RSS feed.
 * Version:     1.5.0
 * Author:      Jeffrey Haug
 * Author URI:  https://hozt.com
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/includes/class-podcast-options.php';
require_once __DIR__ . '/includes/class-podcast-feed.php';

class PodcastingManager {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('init', [$this, 'register_post_type']);
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post', [$this, 'save_meta_box_data']);
        add_action('save_post_podcast', [$this, 'reset_number_owners']);
        add_action('transition_post_status', [$this, 'reset_number_owners']);
        add_action('graphql_register_types', [$this, 'register_graphql_fields']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_filter('query_vars', fn($v) => array_merge($v, ['episode_number']));
        add_action('pre_get_posts', [$this, 'query_by_episode_number']);
        add_action('pre_get_posts', [$this, 'archive_query']);
        add_filter('template_include', [$this, 'archive_template']);
        add_action('init', [$this, 'maybe_flush_rewrite'], 99);
        add_action('loop_start', [$this, 'breadcrumbs_before_loop']);
        add_filter('the_content', [$this, 'prepend_player']);
        add_shortcode('podcast_episodes', [$this, 'episodes_shortcode']);
        add_filter('post_type_link', [$this, 'episode_permalink'], 10, 2);
        new PodcastingManagerFeed();
    }

    /**
     * [podcast_episodes limit="10" season="" order="DESC" player="no" excerpt="yes" paginate="yes"]
     */
    public function episodes_shortcode($atts) {
        $a = shortcode_atts([
            'limit' => 10, 'season' => '', 'order' => 'DESC',
            'player' => 'no', 'excerpt' => 'yes', 'paginate' => 'yes',
        ], $atts, 'podcast_episodes');

        $limit = max(1, min(100, (int) $a['limit']));
        $paged = max(1, (int) ($_GET['ep_page'] ?? 1));
        $meta_query = [
            'relation' => 'AND',
            'number'   => ['key' => '_episode_number', 'type' => 'NUMERIC', 'compare' => 'EXISTS'],
        ];
        if ($a['season'] !== '') {
            $meta_query[] = ['key' => '_season_number', 'value' => (int) $a['season'], 'type' => 'NUMERIC'];
        }
        $q = new WP_Query([
            'post_type'      => 'podcast',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'paged'          => $paged,
            'meta_query'     => $meta_query,
            'orderby'        => 'number',
            'order'          => strtoupper($a['order']) === 'ASC' ? 'ASC' : 'DESC',
        ]);
        if (!$q->have_posts()) return '<p class="podcast-episodes-empty">No episodes yet.</p>';

        $yes = fn($v) => in_array(strtolower($v), ['yes', 'true', '1'], true);
        $out = '<div class="podcast-episodes">';
        while ($q->have_posts()) {
            $q->the_post();
            $out .= self::render_episode_item($yes($a['excerpt']), $yes($a['player']));
        }
        wp_reset_postdata();
        $out .= '</div>';

        if ($yes($a['paginate']) && $q->max_num_pages > 1) {
            $out .= '<nav class="podcast-episodes-nav">';
            if ($paged > 1) $out .= '<a href="' . esc_url(add_query_arg('ep_page', $paged - 1)) . '">&larr; Newer</a> ';
            if ($paged < $q->max_num_pages) $out .= '<a href="' . esc_url(add_query_arg('ep_page', $paged + 1)) . '">Older &rarr;</a>';
            $out .= '</nav>';
        }
        return $out;
    }

    /** HTML for one episode in a listing. Call inside the loop (current post = the episode). */
    public static function render_episode_item($show_excerpt = true, $show_player = false) {
        $id     = get_the_ID();
        $number = (int) get_post_meta($id, '_episode_number', true);
        $season = (int) get_post_meta($id, '_season_number', true);
        $date   = get_post_meta($id, '_episode_date', true) ?: get_the_date('Y-m-d');
        $mp3    = get_post_meta($id, '_mp3_file', true);
        $label  = ($season ? 'S' . $season . ' ' : '') . 'Episode ' . $number;
        $out = '<article class="podcast-episode">'
            . '<h3 class="podcast-episode-title"><a href="' . esc_url(get_permalink()) . '">' . esc_html(get_the_title()) . '</a></h3>'
            . '<p class="podcast-episode-meta">' . esc_html($label) . ' &middot; ' . esc_html(mysql2date(get_option('date_format'), $date)) . '</p>';
        if ($show_excerpt) {
            $out .= '<div class="podcast-episode-excerpt">' . wp_kses_post(wpautop(get_the_excerpt())) . '</div>';
        }
        if ($show_player && $mp3 && !post_password_required()) {
            $out .= wp_audio_shortcode(['src' => $mp3, 'preload' => 'none']);
        }
        return $out . '</article>';
    }

    /** Order/filter the native /podcast/ archive by episode number. */
    public function archive_query($query) {
        if (is_admin() || !$query->is_main_query() || !$query->is_post_type_archive('podcast')) return;
        $meta_query = [
            'relation' => 'AND',
            'number'   => ['key' => '_episode_number', 'type' => 'NUMERIC', 'compare' => 'EXISTS'],
        ];
        $season = isset($_GET['season']) ? trim((string) $_GET['season']) : '';
        if ($season !== '' && ctype_digit($season)) {
            $meta_query[] = ['key' => '_season_number', 'value' => (int) $season, 'type' => 'NUMERIC'];
        }
        $existing = $query->get('meta_query');
        if (is_array($existing) && $existing) {
            $meta_query[] = $existing; // keep other plugins' clauses (with their relation) as a nested group
        }
        $query->set('meta_query', $meta_query);
        $query->set('orderby', 'number');
        $query->set('order', 'DESC');
        $query->set('posts_per_page', (int) apply_filters('podcast_archive_per_page', 10));
    }

    /** Use the theme's archive-podcast.php if it has one, otherwise the plugin's. */
    public function archive_template($template) {
        if (is_post_type_archive('podcast') && basename($template) !== 'archive-podcast.php') {
            return __DIR__ . '/templates/archive-podcast.php';
        }
        return $template;
    }

    /** Flush rewrite rules whenever the archive's path or on/off state changes (slug edit, Page created/renamed/removed). */
    public function maybe_flush_rewrite() {
        $state = $this->archive_slug() . '|' . ($this->archive_conflicting_page() ? 'off' : 'on') . '|feed-alias|ep:' . $this->episode_base();
        if (get_option('podcast_archive_state') !== $state) {
            update_option('podcast_archive_state', $state);
            flush_rewrite_rules();
        }
    }

    private function clean_archive_slug($value) {
        $slug = is_string($value) ? sanitize_title($value) : '';
        return ($slug === '' || in_array($slug, PodcastingManagerOptions::reserved_slugs(), true)) ? 'podcast' : $slug;
    }

    public function archive_slug() {
        return $this->clean_archive_slug(get_option('podcast_archive_slug', 'podcast'));
    }

    /** A published Page already living at the archive slug (the archive rule would override it). */
    public function archive_conflicting_page() {
        static $cache = [];
        $slug = $this->archive_slug();
        if (!array_key_exists($slug, $cache)) {
            $page = get_page_by_path($slug); // uncached query, so memoize it per request
            $cache[$slug] = ($page && $page->post_status === 'publish') ? $page : null;
        }
        return $cache[$slug];
    }

    /**
     * Breadcrumb trail for the archive and single episodes: Home > Podcast > Episode.
     * Accessible <nav> plus schema.org BreadcrumbList JSON-LD. Turn off in settings or with the
     * `podcast_show_breadcrumbs` filter (e.g. if an SEO plugin already prints breadcrumbs).
     */
    public static function breadcrumbs() {
        if (get_option('podcast_breadcrumbs', '1') !== '1' || !apply_filters('podcast_show_breadcrumbs', true)) return '';

        $archive = get_post_type_archive_link('podcast');
        $label   = apply_filters('podcast_breadcrumb_label', 'Podcasts');
        $crumbs  = [['Home', home_url('/')]];
        if (is_singular('podcast')) {
            if ($archive) $crumbs[] = [$label, $archive];
            $crumbs[] = [get_the_title(), ''];
        } elseif (is_post_type_archive('podcast')) {
            $crumbs[] = [$label, ''];
        } else {
            return '';
        }

        static $css_done = false;
        $out = '';
        if (!$css_done) {
            $css_done = true;
            // Specific enough to beat theme rules like `.entry-content ol li` / `.entry-content ol a` (the trail sits inside post content on episode pages).
            $out .= '<style>'
                . 'nav.podcast-breadcrumbs ol{list-style:none;margin:0 0 1em;padding:0;display:flex;flex-wrap:wrap;gap:.4em;font-size:.9em}'
                . 'nav.podcast-breadcrumbs ol li{list-style:none;margin:0;padding:0}'
                . 'nav.podcast-breadcrumbs ol li:not(:last-child)::after{content:"\\203A";margin-left:.4em;opacity:.6}'
                . 'nav.podcast-breadcrumbs ol a{color:inherit;text-decoration:none}'
                . 'nav.podcast-breadcrumbs ol a:hover{text-decoration:underline}'
                . '</style>';
        }
        $out .= '<nav class="podcast-breadcrumbs" aria-label="Breadcrumb"><ol>';
        $ld = [];
        foreach ($crumbs as $i => [$name, $url]) {
            $last = $i === count($crumbs) - 1;
            $out .= '<li' . ($last ? ' aria-current="page"' : '') . '>'
                . ($url && !$last ? '<a href="' . esc_url($url) . '">' . esc_html($name) . '</a>' : esc_html($name)) . '</li>';
            $item = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => wp_strip_all_tags($name)];
            if ($url) $item['item'] = $url;
            elseif ($last && is_singular('podcast')) $item['item'] = get_permalink();
            $ld[] = $item;
        }
        $out .= '</ol></nav>';
        $out .= '<script type="application/ld+json">' . wp_json_encode(
            ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $ld],
            JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
        ) . '</script>';
        return $out;
    }

    private static $crumbs_printed = false;

    /**
     * Classic themes: print the trail where the archive prints it, just before the episode markup
     * (not inside .entry-content, where themes restyle lists and links). Block themes can't print
     * here safely, so they fall back to the_content below.
     */
    public function breadcrumbs_before_loop($query) {
        if (self::$crumbs_printed || !$query->is_main_query() || !is_singular('podcast')) return;
        if (function_exists('wp_is_block_theme') && wp_is_block_theme()) return;
        $html = self::breadcrumbs();
        if ($html === '') return;
        self::$crumbs_printed = true;
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in breadcrumbs()
    }

    /** Show an audio player (and video, when set) at the top of a single episode page. */
    public function prepend_player($content) {
        if (!is_singular('podcast') || !in_the_loop() || !is_main_query()) return $content;
        $crumbs = self::$crumbs_printed ? '' : self::breadcrumbs();
        if (post_password_required()) return $crumbs . $content; // don't leak audio/transcript around the password form
        $url = get_post_meta(get_the_ID(), '_mp3_file', true);
        $video = get_post_meta(get_the_ID(), '_video_url', true);
        $transcript = get_post_meta(get_the_ID(), '_transcript', true);
        $before = $url ? '<div class="podcast-player">' . wp_audio_shortcode(['src' => $url, 'preload' => 'none']) . '</div>' : '';
        if ($video) {
            $embed = wp_oembed_get($video, ['width' => 640]);
            if (!$embed) {
                $embed = '<a href="' . esc_url($video) . '" target="_blank" rel="noopener">Watch the video</a>';
            }
            $before .= '<div class="podcast-video">' . $embed . '</div>';
        }
        $after = $transcript ? '<details class="podcast-transcript"><summary>Transcript</summary>' . wpautop(wp_kses_post($transcript)) . '</details>' : '';
        return $crumbs . $before . $content . $after;
    }

    /**
     * URL base for single episodes and /{n}/ number URLs. Deliberately independent of the
     * archive slug so episode links stay stable when the archive path changes.
     */
    public function episode_base() {
        $base = apply_filters('podcast_episode_base', 'podcast');
        return is_string($base) && $base !== '' ? sanitize_title($base) : 'podcast';
    }

    /** Add /{base}/{episode number}/ rewrite rule (runs after post type registration). */
    public function add_episode_rewrite() {
        add_rewrite_rule('^' . preg_quote($this->episode_base(), '#') . '/([0-9]+)/?$', 'index.php?post_type=podcast&episode_number=$matches[1]', 'top');
        // {archive}/feed/ serves the podcast (iTunes / Podcasting 2.0) feed instead of WordPress's generic RSS.
        add_rewrite_rule('^' . preg_quote($this->archive_slug(), '#') . '/feed/?$', 'index.php?feed=podcast', 'top');
    }

    public function query_by_episode_number($query) {
        if (!$query->is_main_query() || is_admin()) return;
        $number = (int) $query->get('episode_number');
        if (!$number) return;
        $query->set('post_type', 'podcast');
        $query->set('meta_query', [['key' => '_episode_number', 'value' => $number, 'type' => 'NUMERIC']]);
        $query->set('posts_per_page', 1);
        // Several episodes can share a number (numbering restarts each season); the oldest one owns /podcast/{n}/.
        $query->set('orderby', 'ID');
        $query->set('order', 'ASC');
        // WP skips LIMIT for queries flagged singular, so enforce it or every duplicate renders on the page.
        add_filter('post_limits', function ($limits, $q) use ($query) {
            return $q === $query ? 'LIMIT 1' : $limits;
        }, 10, 2);
        $query->is_singular = true;
        $query->is_single = true;
        $query->is_archive = false;
        $query->is_post_type_archive = false;
        $query->is_home = false;
    }

    private static $number_owners = null;

    /** episode number => ID of the published episode that owns /podcast/{n}/ (lowest ID). One query per request. */
    private function number_owners() {
        if (self::$number_owners === null) {
            global $wpdb;
            $rows = $wpdb->get_results(
                "SELECT CAST(m.meta_value AS UNSIGNED) AS n, MIN(p.ID) AS id
                 FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
                 WHERE m.meta_key = '_episode_number' AND p.post_type = 'podcast' AND p.post_status = 'publish'
                 GROUP BY n"
            );
            self::$number_owners = [];
            foreach ((array) $rows as $r) self::$number_owners[(int) $r->n] = (int) $r->id;
        }
        return self::$number_owners;
    }

    public function episode_permalink($link, $post) {
        if ($post->post_type !== 'podcast' || !get_option('permalink_structure')) return $link;
        $number = (int) get_post_meta($post->ID, '_episode_number', true);
        if (!$number) return $link;
        $owner = $this->number_owners()[$number] ?? 0;
        // Use /podcast/{n}/ only for the episode that owns the number; a duplicate keeps its slug URL so both stay reachable.
        if ($owner && $owner !== (int) $post->ID) return $link;
        return home_url('/' . $this->episode_base() . '/' . $number . '/');
    }

    public function reset_number_owners() {
        self::$number_owners = null;
    }

    public function add_admin_menu() {
        add_options_page('Podcast Settings', 'Podcast Settings', 'manage_options', 'podcast-settings', [$this, 'settings_page']);
    }

    public function register_settings() {
        $settings = [
            'podcast_name' => 'sanitize_text_field',
            'podcast_description' => 'wp_kses_post',
            'podcast_image' => 'esc_url_raw',
            'podcast_donation_link' => 'esc_url_raw',
            'podcast_location' => 'sanitize_text_field',
            'podcast_hosts' => 'sanitize_text_field',
            'podcast_explicit_rating' => PodcastingManagerOptions::sanitize_choice(PodcastingManagerOptions::explicit_ratings()),
            'podcast_series' => PodcastingManagerOptions::sanitize_choice(PodcastingManagerOptions::series_types()),
            'podcast_owner' => 'sanitize_text_field',
            'podcast_license' => PodcastingManagerOptions::sanitize_choice(PodcastingManagerOptions::licenses()),
            'podcast_trailer' => 'esc_url_raw',
            'podcast_update_frequency' => PodcastingManagerOptions::sanitize_choice(PodcastingManagerOptions::frequencies()),
            'podcast_categories' => ['PodcastingManagerOptions', 'sanitize_categories'],
            'podcast_keywords' => 'sanitize_text_field',
            'podcast_apple_link' => 'esc_url_raw',
            'podcast_podcasting_index_link' => 'esc_url_raw',
            'podcast_spotify_link' => 'esc_url_raw',
            'podcast_amazon_link' => 'esc_url_raw',
            'podcast_iheart_link' => 'esc_url_raw',
            'podcast_owner_email' => 'sanitize_email',
            'podcast_language' => PodcastingManagerOptions::sanitize_choice(['' => ''] + PodcastingManagerOptions::languages()),
            'podcast_locked' => fn($v) => $v ? '1' : '',
            'podcast_current_season' => fn($v) => trim((string) $v) === '' ? '' : absint($v),
            'podcast_default_episode_hosts' => 'sanitize_text_field',
            'podcast_autonumber' => fn($v) => $v ? '1' : '',
            'podcast_default_transcript_type' => fn($v) => is_string($v) && isset(PodcastingManagerOptions::transcript_types()[$v]) ? $v : 'text/html',
            'podcast_feed_limit' => fn($v) => max(1, min(300, (int) $v)),
            'podcast_archive_player' => fn($v) => $v ? '1' : '',
            'podcast_breadcrumbs' => fn($v) => $v ? '1' : '',
            'podcast_archive_slug' => fn($v) => $this->clean_archive_slug($v),
        ];

        foreach ($settings as $setting => $callback) {
            register_setting('podcast_settings', $setting, ['sanitize_callback' => $callback]);
        }
    }

    private function tip($text) {
        return ' <span class="dashicons dashicons-editor-help" title="' . esc_attr($text) . '" style="color:#787c82;cursor:help"></span>';
    }

    private function row($field, $label, $tip, $type = 'text', $choices = [], $default = '') {
        $value = get_option($field, $default);
        echo '<tr><th scope="row"><label for="' . esc_attr($field) . '">' . esc_html($label) . '</label>' . $this->tip($tip) . '</th><td>';
        if ($type === 'select') {
            if ($value !== '' && !array_key_exists($value, $choices)) $choices = [$value => $value] + $choices; // keep legacy values
            echo '<select id="' . esc_attr($field) . '" name="' . esc_attr($field) . '" title="' . esc_attr($tip) . '">';
            foreach ($choices as $k => $l) {
                printf('<option value="%s"%s>%s</option>', esc_attr($k), selected((string) $value, (string) $k, false), esc_html(is_array($l) ? $l[0] : $l));
            }
            echo '</select>';
        } elseif ($type === 'image') {
            printf('<input type="url" id="%1$s" name="%1$s" value="%2$s" class="regular-text" title="%3$s" placeholder="Paste a URL or upload an image"> ', esc_attr($field), esc_attr($value), esc_attr($tip));
            echo '<button type="button" class="button" id="podcast-upload-image">Upload / Choose Image</button>';
            echo '<p id="podcast-image-warning" class="description" style="display:none;color:#b32d2e"></p>';
            printf('<p><img id="podcast-image-preview" src="%s" alt="" style="max-width:150px;height:auto;border:1px solid #dcdcde;%s"></p>', esc_url($value), $value ? '' : 'display:none');
        } elseif ($type === 'checkbox') {
            printf('<input type="checkbox" id="%1$s" name="%1$s" value="1" title="%2$s"%3$s>', esc_attr($field), esc_attr($tip), checked($value, '1', false));
        } elseif ($type === 'textarea') {
            printf('<textarea id="%1$s" name="%1$s" rows="4" class="large-text" title="%3$s">%2$s</textarea>', esc_attr($field), esc_textarea($value), esc_attr($tip));
        } else {
            $class = $type === 'number' ? 'small-text' : 'regular-text';
            printf('<input type="%1$s" id="%2$s" name="%2$s" value="%3$s" class="%4$s" title="%5$s">', esc_attr($type), esc_attr($field), esc_attr($value), $class, esc_attr($tip));
        }
        echo '</td></tr>';
    }

    public function settings_page() {
        $categories = array_values(array_filter(explode(',', (string) get_option('podcast_categories'))));
        ?>
        <div class="wrap">
            <h1>Podcast Settings</h1>
            <?php if ($page = $this->archive_conflicting_page()) : ?>
                <div class="notice notice-warning"><p>The episode archive is off because the page <a href="<?php echo esc_url(get_edit_post_link($page->ID)); ?>"><?php echo esc_html(get_the_title($page)); ?></a> already uses <code>/<?php echo esc_html($this->archive_slug()); ?>/</code>. Rename or unpublish that page, or change the Archive URL below, to enable it.</p></div>
            <?php endif; ?>
            <p>Podcast feed: <a href="<?php echo esc_url(PodcastingManagerFeed::get_feed_url()); ?>" target="_blank"><code><?php echo esc_html(PodcastingManagerFeed::get_feed_url()); ?></code></a>
                &middot; <a href="https://podba.se/validate/?url=<?php echo rawurlencode(PodcastingManagerFeed::get_feed_url()); ?>" target="_blank">Validate</a></p>
            <form method="post" action="options.php">
                <?php settings_fields('podcast_settings'); ?>

                <table class="form-table">
                    <?php
                    $this->row('podcast_name', 'Podcast Name', 'The title of your show as it appears in podcast apps.');
                    $this->row('podcast_description', 'Description', 'A short summary of what your show is about. Shown in podcast directories.', 'textarea');
                    $this->row('podcast_image', 'Artwork', 'Cover art for the show. Paste a URL or upload one. Apple requires a square JPG or PNG between 1400x1400 and 3000x3000 pixels.', 'image');
                    $this->row('podcast_donation_link', 'Donation Link', 'Where listeners can support the show (Patreon, PayPal, etc.). Appears as podcast:funding.', 'url');
                    $this->row('podcast_location', 'Location', 'The place the show focuses on or is produced in, e.g. "Austin, TX".');
                    $this->row('podcast_hosts', 'Hosts', 'Comma-separated host names. Listed in the feed as podcast:person.');
                    $this->row('podcast_explicit_rating', 'Explicit Rating', 'Whether the show contains adult content. Apple and Spotify require this to be set.', 'select', PodcastingManagerOptions::explicit_ratings(), 'no');
                    $this->row('podcast_series', 'Show Type', 'Episodic shows are listened to newest-first; serial shows are meant to be heard in order, oldest-first.', 'select', PodcastingManagerOptions::series_types(), 'episodic');
                    $this->row('podcast_owner', 'Owner', 'Name of the person or organization that owns the show. Shown as the author.');
                    $this->row('podcast_owner_email', 'Owner Email', 'Contact email for directories to verify ownership. Not shown to listeners in most apps, but visible in the feed.', 'email');
                    $this->row('podcast_license', 'License', 'How others may reuse your content. Appears as the feed copyright and podcast:license.', 'select', PodcastingManagerOptions::licenses());
                    $this->row('podcast_trailer', 'Trailer URL', 'Direct URL to a short audio trailer for the show.', 'url');
                    $this->row('podcast_update_frequency', 'Update Frequency', 'How often you plan to publish. Appears as podcast:updateFrequency.', 'select', PodcastingManagerOptions::frequencies());
                    $this->row('podcast_language', 'Language', 'The main language of the show.', 'select', ['' => 'Site default'] + PodcastingManagerOptions::languages());
                    $this->row('podcast_keywords', 'Keywords', 'Comma-separated search terms that help people find the show.');
                    ?>
                    <tr>
                        <th scope="row">Categories<?php echo $this->tip('Up to three Apple Podcasts categories. Choose a subcategory when one fits; it is listed under its parent category.'); ?></th>
                        <td>
                            <?php for ($i = 0; $i < 3; $i++) : $current = $categories[$i] ?? ''; ?>
                                <select name="podcast_categories[]" title="Category <?php echo $i + 1; ?><?php echo $i ? ' (optional)' : ''; ?>" style="display:block;margin-bottom:6px">
                                    <option value="">— <?php echo $i ? 'Optional category' : 'Choose a category'; ?> —</option>
                                    <?php foreach (PodcastingManagerOptions::categories() as $parent => $children) : ?>
                                        <optgroup label="<?php echo esc_attr($parent); ?>">
                                            <option value="<?php echo esc_attr($parent); ?>" <?php selected($current, $parent); ?>><?php echo esc_html($parent); ?></option>
                                            <?php foreach ($children as $child) : $val = "$parent > $child"; ?>
                                                <option value="<?php echo esc_attr($val); ?>" <?php selected($current, $val); ?>><?php echo esc_html("$parent › $child"); ?></option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                            <?php endfor; ?>
                        </td>
                    </tr>
                    <?php $this->row('podcast_locked', 'Lock Feed', 'Asks other platforms not to import this feed (podcast:locked). Requires Owner Email.', 'checkbox'); ?>
                    <tr>
                        <th scope="row">Podcast GUID<?php echo $this->tip('A permanent unique ID for your show, generated automatically. It stays the same even if you move the feed.'); ?></th>
                        <td><code><?php echo esc_html(PodcastingManagerFeed::get_podcast_guid()); ?></code></td>
                    </tr>
                </table>

                <h2>Episode Defaults</h2>

                <table class="form-table">
                    <?php
                    $this->row('podcast_current_season', 'Current Season', 'Pre-fills the season number on new episodes. Leave blank if the show has no seasons.', 'number');
                    $this->row('podcast_default_episode_hosts', 'Default Episode Host(s)', 'Comma-separated. Pre-fills the Hosts field on new episodes.', 'text', [], get_option('podcast_hosts'));
                    $this->row('podcast_autonumber', 'Auto-number Episodes', 'Pre-fill new episodes with the next episode number.', 'checkbox', [], '1');
                    $this->row('podcast_default_transcript_type', 'Transcript Format', 'File format of the transcripts you link to on episodes.', 'select',
                        PodcastingManagerOptions::transcript_types(), 'text/html');
                    $this->row('podcast_feed_limit', 'Episodes in Feed', 'How many of your newest episodes the feed includes (1-300).', 'number', [], 20);
                    $this->row('podcast_archive_slug', 'Archive URL', 'Where the episode listing page lives, e.g. "podcast" gives /podcast/.', 'text', [], 'podcast');
                    $this->row('podcast_archive_player', 'Player on Archive', 'Show an audio player for each episode on the archive page.', 'checkbox', [], '1');
                    $this->row('podcast_breadcrumbs', 'Breadcrumbs', 'Show a Home > Podcast > Episode trail on the archive and episode pages (with search-engine structured data). Turn off if your theme or SEO plugin already adds breadcrumbs.', 'checkbox', [], '1');
                    ?>
                </table>

                <h2>Podcast Links</h2>

                <table class="form-table">
                    <?php
                    $this->row('podcast_apple_link', 'Apple Podcasts Link', 'Your show\'s page on Apple Podcasts.', 'url');
                    $this->row('podcast_podcasting_index_link', 'Podcasting Index Link', 'Your show\'s page on podcastindex.org.', 'url');
                    $this->row('podcast_spotify_link', 'Spotify Link', 'Your show\'s page on Spotify.', 'url');
                    $this->row('podcast_amazon_link', 'Amazon Link', 'Your show\'s page on Amazon Music.', 'url');
                    $this->row('podcast_iheart_link', 'iHeart Link', 'Your show\'s page on iHeartRadio.', 'url');
                    ?>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function register_post_type() {
        $this->add_episode_rewrite();
        register_post_type('podcast', [
            'public' => true,
            'has_archive' => $this->archive_conflicting_page() ? false : $this->archive_slug(),
            'label'  => 'Podcasts',
            'labels' => [
                'singular_name' => 'Podcast',
                'add_new_item' => 'Add New Podcast',
            ],
            'supports' => ['title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'],
            'rewrite' => ['slug' => $this->episode_base(), 'with_front' => false],
            'show_in_graphql' => true,
            'graphql_single_name' => 'podcast',
            'graphql_plural_name' => 'podcasts',
            'graphql_interfaces' => ['ContentNode', 'NodeWithTitle'],
        ]);
    }

    public function add_meta_boxes() {
        add_meta_box('podcast_meta', 'Podcast Details', [$this, 'render_meta_box'], 'podcast', 'normal', 'high');
    }

    public function render_meta_box($post) {
        wp_nonce_field('podcast_episode_meta', 'podcast_episode_meta_nonce');
        $fields = [
            'episode_number' => 'Episode Number',
            'mp3_file' => 'MP3 URL',
            'video_url' => 'Video URL (YouTube, etc.)',
            'episode_date' => 'Episode Date',
            'episode_length' => 'Length (seconds)',
            'file_size' => 'File Size (bytes)',
            'transcript' => 'Transcript',
            'season_number' => 'Season Number',
            'transcript_url' => 'Transcript URL (for feed)',
            'chapters_url' => 'Chapters URL (JSON)',
            'episode_hosts' => 'Hosts (comma-separated)',
            'episode_guests' => 'Guests (comma-separated)',
            'transcript_type' => 'Transcript URL format',
        ];
        $is_new = $post->post_status === 'auto-draft';
        $defaults = [];
        if ($is_new) {
            $defaults['episode_date'] = wp_date('Y-m-d');
            $defaults['season_number'] = get_option('podcast_current_season') ?: '';
            $defaults['episode_hosts'] = get_option('podcast_default_episode_hosts') ?: get_option('podcast_hosts');
            if (get_option('podcast_autonumber', '1') === '1') {
                global $wpdb;
                $max = (int) $wpdb->get_var("SELECT MAX(CAST(meta_value AS UNSIGNED)) FROM {$wpdb->postmeta} WHERE meta_key = '_episode_number'");
                $defaults['episode_number'] = $max + 1;
            }
        }
        foreach ($fields as $field => $label) {
            $value = get_post_meta($post->ID, "_$field", true);
            if ($value === '' && isset($defaults[$field])) $value = $defaults[$field];
            if ($field === 'transcript') {
                echo "<p><label for='transcript'><strong>" . esc_html($label) . "</strong> (HTML allowed; shown on the episode page)</label></p>";
                wp_editor($value, 'transcript', ['textarea_name' => 'transcript', 'textarea_rows' => 12, 'media_buttons' => false, 'teeny' => true,
                    'tinymce' => ['content_style' => 'body.mce-content-body{padding:10px!important;margin:0!important;max-width:none!important;min-height:0!important}']]);
                continue;
            }
            if ($field === 'transcript_type') {
                echo "<p><label for='transcript_type'>" . esc_html($label) . "</label><br><select name='transcript_type' id='transcript_type'>";
                echo "<option value=''>Use default (" . esc_html(PodcastingManagerOptions::transcript_types()[get_option('podcast_default_transcript_type', 'text/html')] ?? 'HTML') . ")</option>";
                foreach (PodcastingManagerOptions::transcript_types() as $mime => $label_t) {
                    printf("<option value='%s'%s>%s</option>", esc_attr($mime), selected($value, $mime, false), esc_html($label_t));
                }
                echo "</select></p>";
                continue;
            }
            if ($field === 'episode_date') {
                echo "<p><label for='episode_date'>" . esc_html($label) . "</label><br>
                <input type='date' class='widefat' name='episode_date' id='episode_date' value='" . esc_attr($value) . "'></p>";
                continue;
            }
            if ($field === 'video_url') {
                echo "<p><label for='video_url'>" . esc_html($label) . " (shown with the player on the episode page)</label><br>
                <input type='url' class='widefat' name='video_url' id='video_url' value='" . esc_attr($value) . "' placeholder='https://www.youtube.com/watch?v=...'></p>";
                continue;
            }
            if ($field === 'mp3_file') {
                echo "<p><label for='mp3_file'>" . esc_html($label) . "</label><br>
                <input type='url' class='widefat' name='mp3_file' id='mp3_file' value='" . esc_attr($value) . "' placeholder='Paste a URL or upload a file'>
                <input type='hidden' name='mp3_attachment_id' id='mp3_attachment_id' value='" . esc_attr(get_post_meta($post->ID, '_mp3_attachment_id', true)) . "'>
                <button type='button' class='button' id='podcast-upload-audio' style='margin-top:6px'>Upload / Choose from Media Library</button>
                <span class='description'> Either paste a URL or attach an audio file; size and length fill in automatically.</span></p>";
                if ($value) echo '<p>' . wp_audio_shortcode(['src' => $value, 'preload' => 'none']) . '</p>';
                continue;
            }
            echo "<p><label for='$field'>" . esc_html($label) . "</label><br>
            <input type='text' class='widefat' name='$field' id='$field' value='".esc_attr($value)."'></p>";
        }
    }

    public function save_meta_box_data($post_id) {
        $nonce = isset($_POST['podcast_episode_meta_nonce']) ? sanitize_text_field(wp_unslash($_POST['podcast_episode_meta_nonce'])) : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'podcast_episode_meta')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id) || get_post_type($post_id) !== 'podcast') return;
        if (!current_user_can('edit_post', $post_id)) return;

        $transcript_types = PodcastingManagerOptions::transcript_types();
        $fields = ['episode_number' => 'intval', 'mp3_file' => 'esc_url_raw',
                   'video_url' => 'esc_url_raw',
                   'episode_date' => 'sanitize_text_field', 'episode_length' => 'intval',
                   'file_size' => 'intval', 'transcript' => 'wp_kses_post',
                   'season_number' => 'intval', 'episode_hosts' => 'sanitize_text_field', 'transcript_url' => 'esc_url_raw',
                   'chapters_url' => 'esc_url_raw', 'episode_guests' => 'sanitize_text_field',
                   'transcript_type' => fn($v) => isset($transcript_types[$v]) ? $v : ''];

        foreach ($fields as $field => $cb) {
            if (!isset($_POST[$field]) || !is_string($_POST[$field])) continue;
            // $_POST is slashed: unslash before sanitizing, re-slash for update_post_meta (which unslashes).
            $clean = call_user_func($cb, wp_unslash($_POST[$field]));
            update_post_meta($post_id, "_$field", wp_slash($clean));
        }

        // Attached audio: remember the attachment and fill size/length if blank.
        $att_id = isset($_POST['mp3_attachment_id']) ? absint($_POST['mp3_attachment_id']) : 0;
        $mp3 = get_post_meta($post_id, '_mp3_file', true);
        if ($att_id && $mp3 && wp_get_attachment_url($att_id) === $mp3) {
            update_post_meta($post_id, '_mp3_attachment_id', $att_id);
            $file = get_attached_file($att_id);
            $meta = wp_get_attachment_metadata($att_id);
            if (!get_post_meta($post_id, '_file_size', true) && $file && file_exists($file)) {
                update_post_meta($post_id, '_file_size', filesize($file));
            }
            if (!get_post_meta($post_id, '_episode_length', true) && !empty($meta['length'])) {
                update_post_meta($post_id, '_episode_length', (int) $meta['length']);
            }
        } else {
            delete_post_meta($post_id, '_mp3_attachment_id');
        }

        // Remote audio with no known size: ask the server once so the feed enclosure has a length.
        // Stored on the episode, so this runs at most once per save until it succeeds.
        if ($mp3 && !get_post_meta($post_id, '_file_size', true) && !get_post_meta($post_id, '_mp3_attachment_id', true)
            && apply_filters('podcast_fetch_remote_file_size', true)
        ) {
            $head = wp_remote_head($mp3, ['timeout' => 5, 'redirection' => 3]);
            $len = is_wp_error($head) ? 0 : (int) wp_remote_retrieve_header($head, 'content-length');
            if ($len > 0) {
                update_post_meta($post_id, '_file_size', $len);
            }
        }
    }

    public function register_graphql_fields() {

        register_graphql_object_type('PodcastSettings', [
            'fields' => [
                'name' => ['type' => 'String'],
                'description' => ['type' => 'String'],
                'image' => ['type' => 'String'],
                'donationLink' => ['type' => 'String'],
                'location' => ['type' => 'String'],
                'hosts' => ['type' => 'String'],
                'explicitRating' => ['type' => 'String'],
                'series' => ['type' => 'String'],
                'owner' => ['type' => 'String'],
                'license' => ['type' => 'String'],
                'trailer' => ['type' => 'String'],
                'updateFrequency' => ['type' => 'String'],
                'categories' => ['type' => 'String'],
                'keywords' => ['type' => 'String'],
                'feedUrl' => ['type' => 'String'],
                // added link fields below
                'podcastAppleLink' => ['type' => 'String'],
                'podcastPodcastingIndexLink' => ['type' => 'String'],
                'podcastSpotifyLink' => ['type' => 'String'],
                'podcastAmazonLink' => ['type' => 'String'],
                'podcastIheartLink' => ['type' => 'String'],
            ]
        ]);

        register_graphql_field('RootQuery', 'podcastSettings', [
            'type' => 'PodcastSettings',
            'description' => 'Podcast settings data',
            'resolve' => function () {
                return [
                    'name' => get_option('podcast_name'),
                    'description' => get_option('podcast_description'),
                    'image' => get_option('podcast_image'),
                    'donationLink' => get_option('podcast_donation_link'),
                    'location' => get_option('podcast_location'),
                    'hosts' => get_option('podcast_hosts'),
                    'explicitRating' => get_option('podcast_explicit_rating'),
                    'series' => get_option('podcast_series'),
                    'owner' => get_option('podcast_owner'),
                    'license' => get_option('podcast_license'),
                    'trailer' => get_option('podcast_trailer'),
                    'updateFrequency' => get_option('podcast_update_frequency'),
                    'categories' => get_option('podcast_categories'),
                    'keywords' => get_option('podcast_keywords'),
                    'feedUrl' => PodcastingManagerFeed::get_feed_url(),
                    'podcastAppleLink' => get_option('podcast_apple_link'),
                    'podcastPodcastingIndexLink' => get_option('podcast_podcasting_index_link'),
                    'podcastSpotifyLink' => get_option('podcast_spotify_link'),
                    'podcastAmazonLink' => get_option('podcast_amazon_link'),
                    'podcastIheartLink' => get_option('podcast_iheart_link'),
                ];
            }
        ]);

        // Number/season/date stay public (the archive listing shows them for protected episodes too).
        // Everything content-carrying is gated so a post password hides it from the API as well as the page and feed.
        $open  = fn($key, $cast) => fn($post) => $cast(get_post_meta($post->ID, $key, true));
        $gated = fn($key, $cast) => fn($post) => post_password_required($post->ID) ? null : $cast(get_post_meta($post->ID, $key, true));
        $str = fn($v) => (string) $v;
        $int = fn($v) => (int) $v;

        register_graphql_fields('podcast', [
            'episodeNumber' => [
                'type' => 'Int',
                'resolve' => $open('_episode_number', $int),
            ],
            'mp3File' => [
                'type' => 'String',
                'resolve' => $gated('_mp3_file', $str),
            ],
            'videoUrl' => [
                'type' => 'String',
                'resolve' => $gated('_video_url', $str),
            ],
            'episodeDate' => [
                'type' => 'String',
                'resolve' => $open('_episode_date', $str),
            ],
            'episodeLength' => [
                'type' => 'Int',
                'resolve' => $gated('_episode_length', $int),
            ],
            'fileSize' => [
                'type' => 'Int',
                'resolve' => $gated('_file_size', $int),
            ],
            'transcript' => [
                'type' => 'String',
                'resolve' => $gated('_transcript', $str),
            ],
            'seasonNumber' => [
                'type' => 'Int',
                'resolve' => $open('_season_number', $int),
            ],
            'transcriptUrl' => [
                'type' => 'String',
                'resolve' => $gated('_transcript_url', $str),
            ],
            'chaptersUrl' => [
                'type' => 'String',
                'resolve' => $gated('_chapters_url', $str),
            ],
            'guests' => [
                'type' => 'String',
                'resolve' => $gated('_episode_guests', $str),
            ],
        ]);
    }

    public function enqueue_admin_scripts($hook) {
        if ($hook === 'settings_page_podcast-settings') {
            wp_enqueue_media();
            wp_add_inline_script('jquery', <<<'JS'
jQuery(function ($) {
    var frame;
    $('#podcast-upload-image').on('click', function (e) {
        e.preventDefault();
        if (!frame) {
            frame = wp.media({ title: 'Select podcast artwork', library: { type: 'image' }, button: { text: 'Use this image' }, multiple: false });
            frame.on('select', function () {
                var a = frame.state().get('selection').first().toJSON();
                $('#podcast_image').val(a.url).trigger('input');
                if (a.width && a.height && (a.width !== a.height || a.width < 1400 || a.width > 3000)) {
                    $('#podcast-image-warning').text('Apple Podcasts requires square artwork between 1400x1400 and 3000x3000 pixels. This image is ' + a.width + 'x' + a.height + '.').show();
                } else {
                    $('#podcast-image-warning').hide();
                }
            });
        }
        frame.open();
    });
    $('#podcast_image').on('input', function () {
        var url = $(this).val();
        $('#podcast-image-preview').attr('src', url).toggle(!!url);
    });
});
JS);
            return;
        }
        if (!in_array($hook, ['post.php', 'post-new.php'])) return;
        if (get_post_type() === 'podcast') {
            wp_enqueue_media();
            wp_add_inline_script('jquery', <<<'JS'
jQuery(function ($) {
    var frame;
    $('#podcast-upload-audio').on('click', function (e) {
        e.preventDefault();
        if (!frame) {
            frame = wp.media({ title: 'Select episode audio', library: { type: 'audio' }, button: { text: 'Use this audio' }, multiple: false });
            frame.on('select', function () {
                var a = frame.state().get('selection').first().toJSON();
                $('#mp3_file').val(a.url);
                $('#mp3_attachment_id').val(a.id);
                if (a.filesizeInBytes) $('#file_size').val(a.filesizeInBytes);
                if (a.meta && a.meta.length) $('#episode_length').val(a.meta.length);
            });
        }
        frame.open();
    });
    $('#mp3_file').on('input', function () { $('#mp3_attachment_id').val(''); });
});
JS);
        }
    }

    /**
     * Activation: register the feed and post type, then flush so their rules
     * exist immediately (a named method so tests can run it).
     */
    public static function activate() {
        (new PodcastingManagerFeed())->register_feed();
        (new PodcastingManager())->register_post_type();
        flush_rewrite_rules();
    }

}

new PodcastingManager();

register_activation_hook(__FILE__, ['PodcastingManager', 'activate']);
register_deactivation_hook(__FILE__, 'flush_rewrite_rules');

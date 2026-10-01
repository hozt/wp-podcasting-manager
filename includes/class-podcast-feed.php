<?php
if (!defined('ABSPATH')) exit;

/**
 * RSS feed for the podcast CPT with iTunes + Podcasting 2.0 tags.
 * Served at /feed/podcast/ (or ?feed=podcast).
 */
class PodcastingManagerFeed {

    const PODCAST_NAMESPACE_UUID = 'ead4c236-bf58-58c6-a2c6-a6b28d128cb6';

    public function __construct() {
        add_action('init', [$this, 'register_feed']);
        add_action('pre_get_posts', [$this, 'limit_feed_query']);
        add_action('wp_head', [$this, 'print_alternate_link']);
        add_filter('post_type_archive_feed_link', [$this, 'archive_feed_link'], 10, 2);
    }

    public function register_feed() {
        add_feed('podcast', [$this, 'render_feed']);
    }

    public static function get_feed_url() {
        return get_feed_link('podcast');
    }

    public function print_alternate_link() {
        printf(
            '<link rel="alternate" type="application/rss+xml" title="%s" href="%s" />' . "\n",
            esc_attr(get_option('podcast_name') ?: get_bloginfo('name')),
            esc_url(self::get_feed_url())
        );
    }

    public function archive_feed_link($link, $feed) {
        return is_post_type_archive('podcast') ? self::get_feed_url() : $link;
    }

    public function limit_feed_query($query) {
        if (!$query->is_main_query() || !$query->is_feed('podcast')) return;
        $query->set('post_type', 'podcast');
        $query->set('post_status', 'publish');
        $query->set('posts_per_page', max(1, (int) get_option('podcast_feed_limit', 20)));
        $query->set('meta_query', [[
            'key'     => '_mp3_file',
            'value'   => '',
            'compare' => '!=',
        ]]);
    }

    /** Stable podcast:guid — UUIDv5 of the feed URL (sans scheme), stored once. */
    public static function get_podcast_guid() {
        $guid = get_option('podcast_guid');
        if ($guid) return $guid;

        $name = preg_replace('#^https?://#', '', untrailingslashit(self::get_feed_url()));
        $ns   = hex2bin(str_replace('-', '', self::PODCAST_NAMESPACE_UUID));
        $h    = sha1($ns . $name);
        $guid = sprintf(
            '%s-%s-%x-%x-%s',
            substr($h, 0, 8),
            substr($h, 8, 4),
            (hexdec(substr($h, 12, 4)) & 0x0fff) | 0x5000,
            (hexdec(substr($h, 16, 4)) & 0x3fff) | 0x8000,
            substr($h, 20, 12)
        );
        update_option('podcast_guid', $guid);
        return $guid;
    }

    private static function split_list($value) {
        return array_filter(array_map('trim', explode(',', (string) $value)), 'strlen');
    }

    /** "Parent" / "Parent > Child" list -> [parent => [children]]. */
    private static function category_tree($value) {
        $tree = [];
        foreach (self::split_list($value) as $item) {
            $parts = array_map('trim', explode('>', $item));
            $tree[$parts[0]] = $tree[$parts[0]] ?? [];
            if (isset($parts[1])) $tree[$parts[0]][] = $parts[1];
        }
        return $tree;
    }

    private static function explicit($value) {
        return in_array(strtolower(trim((string) $value)), ['yes', 'true', 'explicit', '1'], true) ? 'true' : 'false';
    }

    private static function mime_type($url) {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
        $map = [
            'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'mp4' => 'video/mp4',
            'aac' => 'audio/aac', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg',
            'opus' => 'audio/opus', 'wav' => 'audio/wav', 'flac' => 'audio/flac',
            'm4v' => 'video/x-m4v',
        ];
        return $map[$ext] ?? 'audio/mpeg';
    }

    private static function cdata($text) {
        return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', (string) $text) . ']]>';
    }

    private static function rfc2822($timestamp) {
        return gmdate('D, d M Y H:i:s +0000', $timestamp);
    }

    private static function item_timestamp($post) {
        $date = get_post_meta($post->ID, '_episode_date', true);
        $ts   = $date ? strtotime($date) : false;
        return $ts ?: strtotime($post->post_date_gmt . ' UTC');
    }

    public function render_feed() {
        $opt      = fn($k) => (string) get_option($k);
        $title    = $opt('podcast_name') ?: get_bloginfo('name');
        $desc     = wp_strip_all_tags($opt('podcast_description')) ?: get_bloginfo('description');
        $owner    = $opt('podcast_owner');
        $email    = $opt('podcast_owner_email');
        $image    = $opt('podcast_image');
        $language = $opt('podcast_language') ?: str_replace('_', '-', strtolower(get_locale()));
        $feed_url = self::get_feed_url();
        $explicit = self::explicit($opt('podcast_explicit_rating'));

        global $wp_query;
        $latest = 0;
        foreach ($wp_query->posts as $p) $latest = max($latest, self::item_timestamp($p));

        header('Content-Type: application/rss+xml; charset=' . get_option('blog_charset'), true);
        echo '<?xml version="1.0" encoding="' . esc_attr(get_option('blog_charset')) . '"?>' . "\n";
        ?>
<rss version="2.0"
    xmlns:content="http://purl.org/rss/1.0/modules/content/"
    xmlns:atom="http://www.w3.org/2005/Atom"
    xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"
    xmlns:podcast="https://podcastindex.org/namespace/1.0">
<channel>
    <title><?php echo esc_html($title); ?></title>
    <link><?php echo esc_url(home_url('/')); ?></link>
    <atom:link href="<?php echo esc_url($feed_url); ?>" rel="self" type="application/rss+xml" />
    <description><?php echo self::cdata($desc); ?></description>
    <language><?php echo esc_html($language); ?></language>
    <lastBuildDate><?php echo self::rfc2822($latest ?: time()); ?></lastBuildDate>
    <generator>Podcasting Manager</generator>
<?php if ($opt('podcast_license')) : ?>
    <copyright><?php echo esc_html($opt('podcast_license')); ?></copyright>
    <podcast:license><?php echo esc_html($opt('podcast_license')); ?></podcast:license>
<?php endif; ?>
<?php if ($image) : ?>
    <image><url><?php echo esc_url($image); ?></url><title><?php echo esc_html($title); ?></title><link><?php echo esc_url(home_url('/')); ?></link></image>
    <itunes:image href="<?php echo esc_url($image); ?>" />
<?php endif; ?>
    <itunes:summary><?php echo self::cdata($desc); ?></itunes:summary>
<?php if ($owner) : ?>
    <itunes:author><?php echo esc_html($owner); ?></itunes:author>
<?php endif; ?>
<?php if ($owner || $email) : ?>
    <itunes:owner>
<?php if ($owner) : ?>        <itunes:name><?php echo esc_html($owner); ?></itunes:name>
<?php endif; ?>
<?php if ($email) : ?>        <itunes:email><?php echo esc_html($email); ?></itunes:email>
<?php endif; ?>
    </itunes:owner>
<?php endif; ?>
    <itunes:explicit><?php echo $explicit; ?></itunes:explicit>
    <itunes:type><?php echo strtolower($opt('podcast_series')) === 'serial' ? 'serial' : 'episodic'; ?></itunes:type>
<?php foreach (self::category_tree($opt('podcast_categories')) as $parent => $children) : ?>
<?php if ($children) : ?>
    <itunes:category text="<?php echo esc_attr($parent); ?>">
<?php foreach ($children as $child) : ?>
        <itunes:category text="<?php echo esc_attr($child); ?>" />
<?php endforeach; ?>
    </itunes:category>
<?php else : ?>
    <itunes:category text="<?php echo esc_attr($parent); ?>" />
<?php endif; ?>
<?php endforeach; ?>
<?php if ($opt('podcast_keywords')) : ?>
    <itunes:keywords><?php echo esc_html($opt('podcast_keywords')); ?></itunes:keywords>
<?php endif; ?>
    <podcast:guid><?php echo esc_html(self::get_podcast_guid()); ?></podcast:guid>
<?php if ($opt('podcast_locked') === '1' && $email) : ?>
    <podcast:locked owner="<?php echo esc_attr($email); ?>">yes</podcast:locked>
<?php endif; ?>
<?php if ($opt('podcast_donation_link')) : ?>
    <podcast:funding url="<?php echo esc_url($opt('podcast_donation_link')); ?>">Support the show</podcast:funding>
<?php endif; ?>
<?php if ($opt('podcast_location')) : ?>
    <podcast:location><?php echo esc_html($opt('podcast_location')); ?></podcast:location>
<?php endif; ?>
<?php foreach (self::split_list($opt('podcast_hosts')) as $host) : ?>
    <podcast:person role="host"><?php echo esc_html($host); ?></podcast:person>
<?php endforeach; ?>
<?php if ($opt('podcast_trailer')) : ?>
    <podcast:trailer url="<?php echo esc_url($opt('podcast_trailer')); ?>" pubdate="<?php echo self::rfc2822($latest ?: time()); ?>" type="<?php echo esc_attr(self::mime_type($opt('podcast_trailer'))); ?>"><?php echo esc_html($title); ?> trailer</podcast:trailer>
<?php endif; ?>
<?php $freq = PodcastingManagerOptions::frequencies()[$opt('podcast_update_frequency')] ?? null; ?>
<?php if ($freq && $freq[1]) : ?>
    <podcast:updateFrequency complete="false"<?php echo $freq[2] ? ' rrule="' . esc_attr($freq[2]) . '"' : ''; ?>><?php echo esc_html($freq[1]); ?></podcast:updateFrequency>
<?php endif; ?>
<?php
        while (have_posts()) {
            the_post();
            $this->render_item(get_post());
        }
        ?>
</channel>
</rss>
<?php
    }

    private function render_item($post) {
        $id      = $post->ID;
        $meta    = fn($k) => (string) get_post_meta($id, $k, true);
        $mp3     = $meta('_mp3_file');
        $size    = (int) $meta('_file_size');
        $length  = (int) $meta('_episode_length');
        $number  = (int) $meta('_episode_number');
        $season  = (int) $meta('_season_number');
        $content = apply_filters('the_content', $post->post_content);
        $excerpt = has_excerpt($post) ? wp_strip_all_tags(get_the_excerpt($post)) : wp_trim_words(wp_strip_all_tags($content), 55);
        $thumb   = get_the_post_thumbnail_url($post, 'full');
        ?>
    <item>
        <title><?php echo esc_html(get_the_title_rss()); ?></title>
        <link><?php echo esc_url(get_permalink($post)); ?></link>
        <guid isPermaLink="false"><?php echo esc_html(get_the_guid($post)); ?></guid>
        <pubDate><?php echo self::rfc2822(self::item_timestamp($post)); ?></pubDate>
        <description><?php echo self::cdata($excerpt); ?></description>
        <content:encoded><?php echo self::cdata($content); ?></content:encoded>
        <enclosure url="<?php echo esc_url($mp3); ?>" length="<?php echo $size; ?>" type="<?php echo esc_attr(self::mime_type($mp3)); ?>" />
        <itunes:summary><?php echo self::cdata($excerpt); ?></itunes:summary>
<?php if ($length) : ?>
        <itunes:duration><?php echo $length; ?></itunes:duration>
<?php endif; ?>
<?php if ($number) : ?>
        <itunes:episode><?php echo $number; ?></itunes:episode>
        <podcast:episode><?php echo $number; ?></podcast:episode>
<?php endif; ?>
<?php if ($season) : ?>
        <itunes:season><?php echo $season; ?></itunes:season>
        <podcast:season><?php echo $season; ?></podcast:season>
<?php endif; ?>
<?php if ($thumb) : ?>
        <itunes:image href="<?php echo esc_url($thumb); ?>" />
<?php endif; ?>
        <itunes:explicit><?php echo self::explicit(get_option('podcast_explicit_rating')); ?></itunes:explicit>
<?php if ($meta('_transcript_url')) : ?>
        <podcast:transcript url="<?php echo esc_url($meta('_transcript_url')); ?>" type="<?php echo esc_attr($meta('_transcript_type') ?: get_option('podcast_default_transcript_type', 'text/html')); ?>" />
<?php endif; ?>
<?php if ($meta('_chapters_url')) : ?>
        <podcast:chapters url="<?php echo esc_url($meta('_chapters_url')); ?>" type="application/json+chapters" />
<?php endif; ?>
<?php foreach (self::split_list($meta('_episode_hosts')) as $host) : ?>
        <podcast:person role="host"><?php echo esc_html($host); ?></podcast:person>
<?php endforeach; ?>
<?php foreach (self::split_list($meta('_episode_guests')) as $guest) : ?>
        <podcast:person role="guest"><?php echo esc_html($guest); ?></podcast:person>
<?php endforeach; ?>
    </item>
<?php
    }
}

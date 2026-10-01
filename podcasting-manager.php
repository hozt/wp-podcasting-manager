<?php
/**
 * Plugin Name: Podcasting Manager
 * Description: Manage podcasts with admin interface, GraphQL support and a Podcasting 2.0 RSS feed.
 * Version:     1.4.0
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
        add_action('graphql_register_types', [$this, 'register_graphql_fields']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_filter('query_vars', fn($v) => array_merge($v, ['episode_number']));
        add_action('pre_get_posts', [$this, 'query_by_episode_number']);
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
            $id     = get_the_ID();
            $number = (int) get_post_meta($id, '_episode_number', true);
            $season = (int) get_post_meta($id, '_season_number', true);
            $date   = get_post_meta($id, '_episode_date', true) ?: get_the_date('Y-m-d');
            $mp3    = get_post_meta($id, '_mp3_file', true);
            $label  = ($season ? 'S' . $season . ' ' : '') . 'Episode ' . $number;
            $out .= '<article class="podcast-episode">'
                . '<h3 class="podcast-episode-title"><a href="' . esc_url(get_permalink()) . '">' . esc_html(get_the_title()) . '</a></h3>'
                . '<p class="podcast-episode-meta">' . esc_html($label) . ' &middot; ' . esc_html(mysql2date(get_option('date_format'), $date)) . '</p>';
            if ($yes($a['excerpt'])) {
                $out .= '<div class="podcast-episode-excerpt">' . wp_kses_post(wpautop(get_the_excerpt())) . '</div>';
            }
            if ($yes($a['player']) && $mp3) {
                $out .= wp_audio_shortcode(['src' => $mp3, 'preload' => 'none']);
            }
            $out .= '</article>';
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

    /** Show an audio player at the top of a single episode page. */
    public function prepend_player($content) {
        if (!is_singular('podcast') || !in_the_loop() || !is_main_query()) return $content;
        $url = get_post_meta(get_the_ID(), '_mp3_file', true);
        $transcript = get_post_meta(get_the_ID(), '_transcript', true);
        $before = $url ? '<div class="podcast-player">' . wp_audio_shortcode(['src' => $url, 'preload' => 'none']) . '</div>' : '';
        $after = $transcript ? '<details class="podcast-transcript"><summary>Transcript</summary>' . wpautop(wp_kses_post($transcript)) . '</details>' : '';
        return $before . $content . $after;
    }

    /** Add /podcast/{episode number}/ rewrite rule (runs after post type registration). */
    public function add_episode_rewrite() {
        add_rewrite_rule('^podcast/([0-9]+)/?$', 'index.php?post_type=podcast&episode_number=$matches[1]', 'top');
    }

    public function query_by_episode_number($query) {
        if (!$query->is_main_query() || is_admin()) return;
        $number = (int) $query->get('episode_number');
        if (!$number) return;
        $query->set('post_type', 'podcast');
        $query->set('meta_query', [['key' => '_episode_number', 'value' => $number, 'type' => 'NUMERIC']]);
        $query->set('posts_per_page', 1);
        $query->is_singular = true;
        $query->is_single = true;
        $query->is_archive = false;
        $query->is_post_type_archive = false;
        $query->is_home = false;
    }

    public function episode_permalink($link, $post) {
        if ($post->post_type !== 'podcast' || get_option('permalink_structure') === '') return $link;
        $number = (int) get_post_meta($post->ID, '_episode_number', true);
        return $number ? home_url('/podcast/' . $number . '/') : $link;
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
            'podcast_default_transcript_type' => 'sanitize_text_field',
            'podcast_feed_limit' => fn($v) => max(1, min(300, (int) $v)),
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
                        ['text/html' => 'HTML', 'text/plain' => 'Plain text', 'application/srt' => 'SRT', 'text/vtt' => 'WebVTT', 'application/json' => 'JSON'], 'text/html');
                    $this->row('podcast_feed_limit', 'Episodes in Feed', 'How many of your newest episodes the feed includes (1-300).', 'number', [], 20);
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
            'label'  => 'Podcasts',
            'labels' => [
                'singular_name' => 'Podcast',
                'add_new_item' => 'Add New Podcast',
            ],
            'supports' => ['title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'],
            'rewrite' => ['slug' => 'podcast', 'with_front' => false],
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
            'episode_date' => 'Episode Date',
            'episode_length' => 'Length (seconds)',
            'file_size' => 'File Size (bytes)',
            'transcript' => 'Transcript',
            'season_number' => 'Season Number',
            'transcript_url' => 'Transcript URL (for feed)',
            'chapters_url' => 'Chapters URL (JSON)',
            'episode_hosts' => 'Hosts (comma-separated)',
            'episode_guests' => 'Guests (comma-separated)',
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
            $class = $field === 'episode_date' ? 'widefat podcast-datepicker' : 'widefat';
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
            <input type='text' class='$class' name='$field' id='$field' value='".esc_attr($value)."'></p>";
        }
    }

    public function save_meta_box_data($post_id) {
        if (!isset($_POST['podcast_episode_meta_nonce']) || !wp_verify_nonce($_POST['podcast_episode_meta_nonce'], 'podcast_episode_meta')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $fields = ['episode_number' => 'intval', 'mp3_file' => 'esc_url_raw',
                   'episode_date' => 'sanitize_text_field', 'episode_length' => 'intval',
                   'file_size' => 'intval', 'transcript' => 'wp_kses_post',
                   'season_number' => 'intval', 'episode_hosts' => 'sanitize_text_field', 'transcript_url' => 'esc_url_raw',
                   'chapters_url' => 'esc_url_raw', 'episode_guests' => 'sanitize_text_field'];

        foreach ($fields as $field => $cb) {
            if (isset($_POST[$field])) {
                update_post_meta($post_id, "_$field", call_user_func($fields[$field], $_POST[$field]));
            }
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

        register_graphql_fields('podcast', [
            'episodeNumber' => [
                'type' => 'Int',
                'resolve' => fn($post) => (int)get_post_meta($post->ID, '_episode_number', true),
            ],
            'mp3File' => [
                'type' => 'String',
                'resolve' => fn($post) => get_post_meta($post->ID, '_mp3_file', true),
            ],
            'episodeDate' => [
                'type' => 'String',
                'resolve' => fn($post) => get_post_meta($post->ID, '_episode_date', true),
            ],
            'episodeLength' => [
                'type' => 'Int',
                'resolve' => fn($post) => (int)get_post_meta($post->ID, '_episode_length', true),
            ],
            'fileSize' => [
                'type' => 'Int',
                'resolve' => fn($post) => (int)get_post_meta($post->ID, '_file_size', true),
            ],
            'transcript' => [
                'type' => 'String',
                'resolve' => fn($post) => get_post_meta($post->ID, '_transcript', true),
            ],
            'seasonNumber' => [
                'type' => 'Int',
                'resolve' => fn($post) => (int)get_post_meta($post->ID, '_season_number', true),
            ],
            'transcriptUrl' => [
                'type' => 'String',
                'resolve' => fn($post) => get_post_meta($post->ID, '_transcript_url', true),
            ],
            'chaptersUrl' => [
                'type' => 'String',
                'resolve' => fn($post) => get_post_meta($post->ID, '_chapters_url', true),
            ],
            'guests' => [
                'type' => 'String',
                'resolve' => fn($post) => get_post_meta($post->ID, '_episode_guests', true),
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
            wp_add_inline_script('jquery-ui-datepicker', <<<'JS'
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
        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_style('jquery-ui', '//code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');
        wp_add_inline_script('jquery-ui-datepicker', 'jQuery(".podcast-datepicker").datepicker({dateFormat: "yy-mm-dd"});');
    }

}

new PodcastingManager();

register_activation_hook(__FILE__, function () {
    (new PodcastingManagerFeed())->register_feed();
    (new PodcastingManager())->register_post_type();
    flush_rewrite_rules();
});
register_deactivation_hook(__FILE__, 'flush_rewrite_rules');

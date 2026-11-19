<?php
if (!defined('ABSPATH')) exit;

/** Choice lists for the settings dropdowns. */
class PodcastingManagerOptions {

    public static function licenses() {
        return [
            ''                => '— None —',
            'All rights reserved' => 'All rights reserved',
            'CC BY 4.0'       => 'Creative Commons Attribution (CC BY 4.0)',
            'CC BY-SA 4.0'    => 'Creative Commons Attribution-ShareAlike (CC BY-SA 4.0)',
            'CC BY-ND 4.0'    => 'Creative Commons Attribution-NoDerivs (CC BY-ND 4.0)',
            'CC BY-NC 4.0'    => 'Creative Commons Attribution-NonCommercial (CC BY-NC 4.0)',
            'CC BY-NC-SA 4.0' => 'Creative Commons Attribution-NonCommercial-ShareAlike (CC BY-NC-SA 4.0)',
            'CC BY-NC-ND 4.0' => 'Creative Commons Attribution-NonCommercial-NoDerivs (CC BY-NC-ND 4.0)',
            'CC0 1.0'         => 'Public Domain (CC0 1.0)',
        ];
    }

    /** key => [label, human text, rrule]. */
    public static function frequencies() {
        return [
            ''          => ['— Not set —', '', ''],
            'daily'     => ['Daily', 'Daily', 'FREQ=DAILY'],
            'weekdays'  => ['Weekdays', 'Every weekday', 'FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR'],
            'weekly'    => ['Weekly', 'Weekly', 'FREQ=WEEKLY'],
            'biweekly'  => ['Every two weeks', 'Every two weeks', 'FREQ=WEEKLY;INTERVAL=2'],
            'monthly'   => ['Monthly', 'Monthly', 'FREQ=MONTHLY'],
            'quarterly' => ['Quarterly', 'Quarterly', 'FREQ=MONTHLY;INTERVAL=3'],
            'irregular' => ['Irregular', 'Irregular', ''],
        ];
    }

    public static function languages() {
        return [
            'en-us' => 'English (US)', 'en-gb' => 'English (UK)', 'es' => 'Spanish', 'fr' => 'French',
            'de' => 'German', 'it' => 'Italian', 'pt-br' => 'Portuguese (Brazil)', 'pt-pt' => 'Portuguese (Portugal)',
            'nl' => 'Dutch', 'sv' => 'Swedish', 'da' => 'Danish', 'no' => 'Norwegian', 'fi' => 'Finnish',
            'pl' => 'Polish', 'ru' => 'Russian', 'uk' => 'Ukrainian', 'tr' => 'Turkish', 'ar' => 'Arabic',
            'he' => 'Hebrew', 'hi' => 'Hindi', 'zh-cn' => 'Chinese (Simplified)', 'zh-tw' => 'Chinese (Traditional)',
            'ja' => 'Japanese', 'ko' => 'Korean', 'id' => 'Indonesian',
        ];
    }

    /** MIME type => label for transcript files. */
    public static function transcript_types() {
        return ['text/html' => 'HTML', 'text/plain' => 'Plain text', 'application/srt' => 'SRT', 'text/vtt' => 'WebVTT', 'application/json' => 'JSON'];
    }

    /** Slugs the episode archive must not use (they would shadow core routes). */
    public static function reserved_slugs() {
        return ['wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'wp-login', 'feed', 'rss', 'rss2', 'rdf', 'atom', 'page', 'comments',
                'embed', 'search', 'author', 'category', 'tag', 'attachment', 'trackback', 'xmlrpc', 'admin', 'login'];
    }

    public static function explicit_ratings() {
        return [
            'no'  => 'Clean — suitable for all audiences',
            'yes' => 'Explicit — contains adult content',
        ];
    }

    public static function series_types() {
        return [
            'episodic' => 'Episodic — episodes can be heard in any order',
            'serial'   => 'Serial — episodes are meant to be heard in order',
        ];
    }

    /** Apple Podcasts categories: parent => [subcategories]. */
    public static function categories() {
        return [
            'Arts' => ['Books', 'Design', 'Fashion & Beauty', 'Food', 'Performing Arts', 'Visual Arts'],
            'Business' => ['Careers', 'Entrepreneurship', 'Investing', 'Management', 'Marketing', 'Non-Profit'],
            'Comedy' => ['Comedy Interviews', 'Improv', 'Stand-Up'],
            'Education' => ['Courses', 'How To', 'Language Learning', 'Self-Improvement'],
            'Fiction' => ['Comedy Fiction', 'Drama', 'Science Fiction'],
            'Government' => [],
            'History' => [],
            'Health & Fitness' => ['Alternative Health', 'Fitness', 'Medicine', 'Mental Health', 'Nutrition', 'Sexuality'],
            'Kids & Family' => ['Education for Kids', 'Parenting', 'Pets & Animals', 'Stories for Kids'],
            'Leisure' => ['Animation & Manga', 'Automotive', 'Aviation', 'Crafts', 'Games', 'Hobbies', 'Home & Garden', 'Video Games'],
            'Music' => ['Music Commentary', 'Music History', 'Music Interviews'],
            'News' => ['Business News', 'Daily News', 'Entertainment News', 'News Commentary', 'Politics', 'Sports News', 'Tech News'],
            'Religion & Spirituality' => ['Buddhism', 'Christianity', 'Hinduism', 'Islam', 'Judaism', 'Religion', 'Spirituality'],
            'Science' => ['Astronomy', 'Chemistry', 'Earth Sciences', 'Life Sciences', 'Mathematics', 'Natural Sciences', 'Nature', 'Physics', 'Social Sciences'],
            'Society & Culture' => ['Documentary', 'Personal Journals', 'Philosophy', 'Places & Travel', 'Relationships'],
            'Sports' => ['Baseball', 'Basketball', 'Cricket', 'Fantasy Sports', 'Football', 'Golf', 'Hockey', 'Rugby', 'Running', 'Soccer', 'Swimming', 'Tennis', 'Volleyball', 'Wilderness', 'Wrestling'],
            'Technology' => [],
            'True Crime' => [],
            'TV & Film' => ['After Shows', 'Film History', 'Film Interviews', 'Film Reviews', 'TV Reviews'],
        ];
    }

    /** Flat list of valid stored values: "Parent" or "Parent > Child". */
    public static function valid_categories() {
        $out = [];
        foreach (self::categories() as $parent => $children) {
            $out[] = $parent;
            foreach ($children as $child) $out[] = "$parent > $child";
        }
        return $out;
    }

    /** Sanitize callback: array (or comma string) -> up to 3 valid categories, comma-joined. */
    public static function sanitize_categories($value) {
        $items = is_array($value) ? $value : explode(',', (string) $value);
        $valid = self::valid_categories();
        $clean = [];
        foreach ($items as $item) {
            if (!is_string($item)) continue;
            $item = trim(wp_unslash($item));
            if ($item !== '' && in_array($item, $valid, true) && !in_array($item, $clean, true)) $clean[] = $item;
        }
        return implode(',', array_slice($clean, 0, 3));
    }

    public static function sanitize_choice($choices) {
        return fn($v) => is_string($v) && array_key_exists($v, $choices) ? $v : '';
    }
}

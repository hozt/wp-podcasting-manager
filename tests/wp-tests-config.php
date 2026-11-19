<?php
// WordPress test-suite config for this plugin. Values come from the environment
// with defaults for the LocalWP "multi" site; CI sets the PODCAST_TEST_DB_* vars.
// WARNING: the suite drops and recreates this database on every run.

$socket = getenv('PODCAST_TEST_DB_SOCKET');
if ($socket === false) {
    $socket = '/Users/jeff/Library/Application Support/Local/run/UjqvRzHmh/mysql/mysqld.sock';
}
$host = getenv('PODCAST_TEST_DB_HOST') ?: 'localhost';
if ($socket !== '' && strpos($host, ':') === false && strpos($host, '/') === false) {
    $host .= ':' . $socket; // WP treats a non-numeric :suffix as a MySQL socket
}

define('DB_NAME', getenv('PODCAST_TEST_DB_NAME') ?: 'podcasting_manager_tests');
define('DB_USER', getenv('PODCAST_TEST_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('PODCAST_TEST_DB_PASSWORD') ?: 'root');
define('DB_HOST', $host);
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

$table_prefix = 'wptests_';

// Pristine WordPress core the suite boots (tests/bin/install-wordpress.sh fetches it).
// realpath: WP refuses paths containing /../ in several places (e.g. wp_mkdir_p).
$wp_dir = getenv('PODCAST_TEST_WP_DIR') ?: realpath(__DIR__ . '/../vendor/wordpress');
define('ABSPATH', rtrim($wp_dir, '/\\') . '/');

define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'Podcasting Manager Tests');
define('WP_PHP_BINARY', PHP_BINARY);
define('WPLANG', '');

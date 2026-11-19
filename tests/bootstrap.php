<?php
// PHPUnit bootstrap: boots a disposable WordPress with this plugin active.
// Run: vendor/bin/phpunit   (DB configured in tests/wp-tests-config.php)

// Buffer the whole run: the WP suite bootstrap echoes, which would otherwise
// make every header() call under test warn about "headers already sent".
ob_start();

$_wp_tests = __DIR__ . '/../vendor/wp-phpunit/wp-phpunit';
if (!is_dir($_wp_tests)) {
    fwrite(STDERR, "WP test library missing. Run: composer install\n");
    exit(1);
}

if (!defined('WP_TESTS_CONFIG_FILE_PATH')) {
    define('WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php');
}
if (!defined('WP_TESTS_PHPUNIT_POLYFILLS_PATH')) {
    define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', __DIR__ . '/../vendor/yoast/phpunit-polyfills');
}

// Safety: the suite drops the whole database, so refuse anything that is not
// obviously a test database.
$db_name = getenv('PODCAST_TEST_DB_NAME') ?: 'podcasting_manager_tests';
if (stripos($db_name, 'test') === false) {
    fwrite(STDERR, "Refusing to run: PODCAST_TEST_DB_NAME '$db_name' does not look like a test database.\n");
    exit(1);
}
if (!defined('WP_TESTS_MULTISITE')) {
    define('WP_TESTS_MULTISITE', false);
}

$wp_dir = getenv('PODCAST_TEST_WP_DIR') ?: realpath(__DIR__ . '/../vendor/wordpress');
if (!$wp_dir || !is_file($wp_dir . '/wp-settings.php')) {
    fwrite(STDERR, "WordPress test core missing. Run: tests/bin/install-wordpress.sh\n");
    exit(1);
}

// The test case base class scans the uploads dir during teardown; pristine core ships without it.
if (!is_dir($wp_dir . '/wp-content/uploads')) {
    mkdir($wp_dir . '/wp-content/uploads', 0777, true);
}

require $_wp_tests . '/includes/functions.php';

tests_add_filter('muplugins_loaded', function () {
    require dirname(__DIR__) . '/podcasting-manager.php';
});

require $_wp_tests . '/includes/bootstrap.php';

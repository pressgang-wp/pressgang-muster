<?php

$coreDir = getenv('WP_CORE_DIR');
if (!is_string($coreDir) || $coreDir === '') {
    throw new RuntimeException('WP_CORE_DIR must point to a downloaded WordPress installation.');
}

define('ABSPATH', rtrim($coreDir, '/\\') . '/');
define('DB_NAME', getenv('WP_TEST_DB_NAME') ?: 'muster_test');
define('DB_USER', getenv('WP_TEST_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('WP_TEST_DB_PASSWORD') ?: '');
define('DB_HOST', getenv('WP_TEST_DB_HOST') ?: '127.0.0.1');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

define('WP_TESTS_DOMAIN', 'muster.test');
define('WP_TESTS_EMAIL', 'admin@muster.test');
define('WP_TESTS_TITLE', 'Muster integration tests');
define('WP_PHP_BINARY', getenv('WP_PHP_BINARY') ?: 'php');
define('WP_DEBUG', true);

$table_prefix = getenv('WP_TEST_TABLE_PREFIX') ?: 'muster_tests_';

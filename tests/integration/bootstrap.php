<?php

$integrationRoot = __DIR__;

putenv('WP_PHPUNIT__TESTS_CONFIG=' . $integrationRoot . '/wp-tests-config.php');

require_once $integrationRoot . '/vendor/autoload.php';

if (!defined('WP_TESTS_PHPUNIT_POLYFILLS_PATH')) {
    define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', $integrationRoot . '/vendor/yoast/phpunit-polyfills');
}

$testsDir = getenv('WP_PHPUNIT__DIR');
if (!is_string($testsDir) || $testsDir === '') {
    throw new RuntimeException('WP_PHPUNIT__DIR was not registered by wp-phpunit/wp-phpunit.');
}

require_once $testsDir . '/includes/functions.php';
require_once $testsDir . '/includes/bootstrap.php';

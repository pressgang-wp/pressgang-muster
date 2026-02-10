<?php


$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_readable($autoload)) {
    require_once $autoload;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'PressGang\\Muster\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    if ($relative === false) {
        return;
    }

    $base = str_starts_with($class, 'PressGang\\Muster\\Tests\\') ? __DIR__ : __DIR__ . '/../src';
    $relativePath = str_replace('\\', '/', str_replace('Tests\\', '', $relative)) . '.php';
    $path = $base . '/' . $relativePath;

    if (is_readable($path)) {
        require_once $path;
    }
});

if (!class_exists('Faker\\Factory')) {
    require_once __DIR__ . '/stubs/FakerStub.php';
}

if (!class_exists('PHPUnit\\Framework\\TestCase')) {
    require_once __DIR__ . '/stubs/TestCaseStub.php';
}

require_once __DIR__ . '/WordPressStubs.php';

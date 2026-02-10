<?php

declare(strict_types=1);

require_once __DIR__ . '/../tests/bootstrap.php';

$files = glob(__DIR__ . '/../tests/*Test.php');
if ($files === false) {
    fwrite(STDERR, "No tests found.\n");
    exit(1);
}

foreach ($files as $file) {
    require_once $file;
}

$allClasses = get_declared_classes();
$testClasses = array_values(array_filter($allClasses, static function (string $class): bool {
    return str_starts_with($class, 'PressGang\\Muster\\Tests\\')
        && is_subclass_of($class, 'PHPUnit\\Framework\\TestCase');
}));

sort($testClasses);

$total = 0;
$failures = 0;

foreach ($testClasses as $class) {
    $reflection = new ReflectionClass($class);
    $methods = array_filter(
        $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn (ReflectionMethod $m): bool => str_starts_with($m->getName(), 'test')
    );

    foreach ($methods as $method) {
        $total++;
        $instance = $reflection->newInstance();

        if ($reflection->hasMethod('setUp')) {
            $setUp = $reflection->getMethod('setUp');
            $setUp->setAccessible(true);
            $setUp->invoke($instance);
        }

        try {
            $method->invoke($instance);

            $expected = method_exists($instance, '__consumeExpectedException')
                ? $instance->__consumeExpectedException()
                : null;

            if ($expected !== null) {
                $failures++;
                echo "FAIL {$class}::{$method->getName()} expected exception {$expected} not thrown\n";
                continue;
            }

            echo "PASS {$class}::{$method->getName()}\n";
        } catch (Throwable $e) {
            $expected = method_exists($instance, '__consumeExpectedException')
                ? $instance->__consumeExpectedException()
                : null;

            if ($expected !== null && $e instanceof $expected) {
                echo "PASS {$class}::{$method->getName()}\n";
                continue;
            }

            $failures++;
            echo "FAIL {$class}::{$method->getName()} " . get_class($e) . ": {$e->getMessage()}\n";
        }
    }
}

echo "\nTests: {$total}, Failures: {$failures}\n";

exit($failures > 0 ? 1 : 0);

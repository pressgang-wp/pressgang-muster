<?php

declare(strict_types=1);

namespace PHPUnit\Framework;

use Exception;

class TestCase
{
    private ?string $expectedException = null;

    /**
     * @param string $exception
     * @return void
     */
    public function expectException(string $exception): void
    {
        $this->expectedException = $exception;
    }

    /**
     * @return string|null
     */
    public function __consumeExpectedException(): ?string
    {
        $value = $this->expectedException;
        $this->expectedException = null;

        return $value;
    }

    /**
     * @param mixed $expected
     * @param mixed $actual
     * @return void
     */
    public static function assertSame(mixed $expected, mixed $actual): void
    {
        if ($expected !== $actual) {
            throw new Exception('Assertion failed: values are not identical.');
        }
    }

    /**
     * @param int $expectedCount
     * @param iterable<mixed> $actual
     * @return void
     */
    public static function assertCount(int $expectedCount, iterable $actual): void
    {
        $count = is_array($actual) ? count($actual) : iterator_count((function () use ($actual) {
            foreach ($actual as $item) {
                yield $item;
            }
        })());

        if ($expectedCount !== $count) {
            throw new Exception('Assertion failed: count mismatch.');
        }
    }

    /**
     * @param mixed $actual
     * @return void
     */
    public static function assertIsString(mixed $actual): void
    {
        if (!is_string($actual)) {
            throw new Exception('Assertion failed: value is not a string.');
        }
    }

    /**
     * @param string $needle
     * @param string $haystack
     * @return void
     */
    public static function assertStringContainsString(string $needle, string $haystack): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new Exception('Assertion failed: string does not contain expected value.');
        }
    }

    /**
     * @param class-string $class
     * @param mixed $value
     * @return void
     */
    public static function assertInstanceOf(string $class, mixed $value): void
    {
        if (!$value instanceof $class) {
            throw new Exception('Assertion failed: value is not expected instance.');
        }
    }
}

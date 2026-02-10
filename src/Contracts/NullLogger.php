<?php

namespace PressGang\Muster\Contracts;

/**
 * No-op logger used when no output sink is registered.
 */
final class NullLogger implements LoggerInterface
{
    /**
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function info(string $message, array $context = []): void
    {
    }

    /**
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function debug(string $message, array $context = []): void
    {
    }

    /**
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function warning(string $message, array $context = []): void
    {
    }
}

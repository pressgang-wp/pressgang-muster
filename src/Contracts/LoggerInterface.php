<?php

namespace PressGang\Muster\Contracts;

/**
 * Minimal logger contract for CLI and dry-run output.
 *
 * Implementations should avoid throwing so seeding flow remains predictable.
 */
interface LoggerInterface
{
    /**
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function info(string $message, array $context = []): void;

    /**
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function debug(string $message, array $context = []): void;

    /**
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function warning(string $message, array $context = []): void;
}

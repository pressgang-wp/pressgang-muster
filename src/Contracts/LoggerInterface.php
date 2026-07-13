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
     * Report run-level information users should always see (unless quiet).
     *
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function info(string $message, array $context = []): void;

    /**
     * Report per-declaration diagnostics, surfaced only on verbose runs or a
     * debug-enabled channel.
     *
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function debug(string $message, array $context = []): void;

    /**
     * Report a non-fatal problem on the implementation's warning channel; the
     * run continues.
     *
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function warning(string $message, array $context = []): void;

    /**
     * Report deterministic progress for one Pattern run.
     *
     * @param string $pattern
     * @param int $current
     * @param int $total
     * @return void
     */
    public function progress(string $pattern, int $current, int $total): void;
}

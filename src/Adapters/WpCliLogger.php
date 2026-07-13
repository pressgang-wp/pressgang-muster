<?php

namespace PressGang\Muster\Adapters;

use PressGang\Muster\Contracts\LoggerInterface;

/**
 * Logger that sends Muster run information to the active WP-CLI process.
 *
 * Informational messages are always visible, which makes dry-run output useful.
 * Debug messages follow WP-CLI's normal `--debug` behaviour, while warnings use
 * WP-CLI's warning channel. Outside WP-CLI the logger safely performs no output.
 */
final class WpCliLogger implements LoggerInterface
{
    public function __construct(private bool $verbose = false, private bool $quiet = false)
    {
    }

    /**
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function info(string $message, array $context = []): void
    {
        unset($context);

        if ($this->silenced()) {
            return;
        }

        $this->emit('log', $message, $message);
    }

    /**
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function debug(string $message, array $context = []): void
    {
        unset($context);

        if ($this->silenced()) {
            return;
        }

        if ($this->verbose) {
            $this->info($message);

            return;
        }

        if (method_exists('\\WP_CLI', 'debug')) {
            \WP_CLI::debug($message, 'muster');
        }
    }

    /**
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function warning(string $message, array $context = []): void
    {
        unset($context);

        if ($this->silenced()) {
            return;
        }

        $this->emit('warning', $message, 'Warning: ' . $message);
    }

    public function progress(string $pattern, int $current, int $total): void
    {
        if ($this->quiet || $total < 1 || !$this->shouldReport($current, $total)) {
            return;
        }

        $this->info(sprintf('Pattern [%s] progress: %d/%d.', $pattern, $current, $total));
    }

    /**
     * Sampling cadence for pattern progress.
     *
     * Verbose runs report every iteration. Normal runs report roughly every
     * tenth iteration plus the final one, and suppress patterns under ten
     * iterations entirely — they finish before progress means anything.
     *
     * @param int $current One-based iteration index.
     * @param int $total Total declared iterations.
     * @return bool
     */
    private function shouldReport(int $current, int $total): bool
    {
        if ($this->verbose) {
            return true;
        }

        if ($total < 10) {
            return false;
        }

        $step = max(1, (int) ceil($total / 10));

        return $current === $total || $current % $step === 0;
    }

    /**
     * Central output gate: quiet mode and non-WP-CLI processes emit nothing.
     *
     * @return bool
     */
    private function silenced(): bool
    {
        return $this->quiet || !class_exists('\\WP_CLI');
    }

    /**
     * Send a message through a modern WP_CLI channel, or `line()` on older
     * WP-CLI versions that lack it.
     *
     * @param string $method WP_CLI channel, e.g. `log` or `warning`.
     * @param string $message Message for the modern channel.
     * @param string $fallback Message for the `line()` fallback, already prefixed.
     * @return void
     */
    private function emit(string $method, string $message, string $fallback): void
    {
        if (method_exists('\\WP_CLI', $method)) {
            \WP_CLI::{$method}($message);

            return;
        }

        \WP_CLI::line($fallback);
    }
}

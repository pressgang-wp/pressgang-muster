<?php

namespace PressGang\Muster\Contracts;

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

        if ($this->quiet || !class_exists('\\WP_CLI')) {
            return;
        }

        if (method_exists('\\WP_CLI', 'log')) {
            \WP_CLI::log($message);

            return;
        }

        \WP_CLI::line($message);
    }

    /**
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function debug(string $message, array $context = []): void
    {
        unset($context);

        if ($this->quiet || !class_exists('\\WP_CLI')) {
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

        if ($this->quiet || !class_exists('\\WP_CLI')) {
            return;
        }

        if (method_exists('\\WP_CLI', 'warning')) {
            \WP_CLI::warning($message);

            return;
        }

        \WP_CLI::line('Warning: ' . $message);
    }

    public function progress(string $pattern, int $current, int $total): void
    {
        if ($this->quiet || $total < 1) {
            return;
        }

        $step = max(1, (int) ceil($total / 10));
        if (!$this->verbose && $total < 10) {
            return;
        }
        if (!$this->verbose && $current !== $total && $current % $step !== 0) {
            return;
        }

        $this->info(sprintf('Pattern [%s] progress: %d/%d.', $pattern, $current, $total));
    }
}

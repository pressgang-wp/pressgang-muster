<?php

namespace PressGang\Muster\Cli;

/**
 * `wp capstan muster` — run a named Muster orchestrator.
 *
 * The low-level runner: you name the class, it runs. For the conventional
 * "seed this site for development" flow (default class, --fresh reset,
 * production guard) see {@see SeedCommand}.
 *
 * Usage:
 * `wp capstan muster <muster-class> [--seed=<int>] [--dry-run] [--only=<csv>]`
 *
 * Any thrown exception is surfaced as a single failure line so command output
 * remains deterministic and script-friendly.
 */
final class MusterCommand
{
    /**
     * Register the `wp capstan muster` command when WP-CLI is available.
     *
     * @return void
     */
    public static function register(): void
    {
        if (! defined('WP_CLI') || ! \WP_CLI) {
            return;
        }

        if (class_exists('\WP_CLI')) {
            \WP_CLI::add_command('capstan muster', [self::class, 'handle']);
        }
    }

    /**
     * Handle CLI invocation.
     *
     * @param array<int, string> $args
     * @param array<string, mixed> $assocArgs
     * @return void
     */
    public static function handle(array $args, array $assocArgs): void
    {
        $musterClass = $args[0] ?? '';

        if (! is_string($musterClass) || $musterClass === '') {
            Invoker::fail('Muster class argument is required.');
        }

        try {
            $muster = Invoker::makeMuster($musterClass, Invoker::contextFromFlags($assocArgs));
            $muster->run();

            Invoker::emit('Muster completed.');
        } catch (\Throwable $e) {
            Invoker::fail('Muster failed: ' . $e->getMessage());
        }
    }
}

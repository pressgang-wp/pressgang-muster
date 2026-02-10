<?php

namespace PressGang\Muster\Cli;

use PressGang\Muster\MusterContext;
use PressGang\Muster\Victuals\VictualsFactory;

/**
 * WP-CLI command registration and argument parsing for Muster runs.
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
        if (!defined('WP_CLI') || !\WP_CLI) {
            return;
        }

        if (class_exists('\WP_CLI')) {
            \WP_CLI::add_command('capstan muster', [self::class, 'handle']);
        }
    }

    /**
     * Handle CLI invocation.
     *
     * Supported flags:
     * - `--seed=<int>`
     * - `--dry-run`
     * - `--only=<csv>`
     *
     * @param array<int, string> $args
     * @param array<string, mixed> $assocArgs
     * @return void
     */
    public static function handle(array $args, array $assocArgs): void
    {
        $seed = isset($assocArgs['seed']) ? (int) $assocArgs['seed'] : null;
        $dryRun = isset($assocArgs['dry-run']);
        $only = isset($assocArgs['only']) ? explode(',', (string) $assocArgs['only']) : [];

        $context = new MusterContext(
            new VictualsFactory(),
            seed: $seed,
            dryRun: $dryRun,
        );

        unset($args, $only, $context);

        if (class_exists('\WP_CLI')) {
            \WP_CLI::line('Not implemented');

            return;
        }

        echo "Not implemented\n";
    }
}

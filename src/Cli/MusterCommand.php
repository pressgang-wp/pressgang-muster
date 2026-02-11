<?php

namespace PressGang\Muster\Cli;

use InvalidArgumentException;
use PressGang\Muster\Muster;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Victuals\VictualsFactory;

/**
 * WP-CLI entrypoint for running Muster orchestrators.
 *
 * This class owns command registration, argument parsing, and startup wiring.
 * It does not contain seeding behaviour itself.
 */
final class MusterCommand
{
    /**
     * Register the `wp capstan muster` command when WP-CLI is available.
     *
     * See: https://make.wordpress.org/cli/handbook/references/internal-api/wp-cli-add-command/
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
     * Usage:
     * `wp capstan muster <muster-class> [--seed=<int>] [--dry-run] [--only=<csv>]`
     *
     * Supported flags:
     * - `--seed=<int>`
     * - `--dry-run`
     * - `--only=<csv>`
     *
     * Any thrown exception is surfaced as a single failure line so command output remains
     * deterministic and script-friendly.
     *
     * @param array<int, string> $args
     * @param array<string, mixed> $assocArgs
     * @return void
     */
    public static function handle(array $args, array $assocArgs): void
    {
        $musterClass = $args[0] ?? '';
        if (!is_string($musterClass) || $musterClass === '') {
            self::fail('Muster class argument is required.');
        }

        $seed = isset($assocArgs['seed']) ? (int) $assocArgs['seed'] : null;
        $dryRun = isset($assocArgs['dry-run']);

        $onlyRaw = (string) ($assocArgs['only'] ?? '');
        $only = $onlyRaw === ''
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $onlyRaw)), static fn (string $v): bool => $v !== ''));

        try {
            $context = new MusterContext(
                new VictualsFactory(),
                seed: $seed,
                dryRun: $dryRun,
                onlyPatterns: $only,
            );

            $muster = self::makeMuster($musterClass, $context);
            $muster->run();

            self::emit('Muster completed.');
        } catch (\Throwable $e) {
            self::fail('Muster failed: ' . $e->getMessage());
        }
    }

    /**
     * Instantiate and validate a Muster class string.
     *
     * The class must exist and extend `PressGang\Muster\Muster`.
     *
     * @param string $musterClass
     * @param MusterContext $context
     * @return Muster
     * @throws InvalidArgumentException If the class is missing or not a Muster subtype.
     */
    private static function makeMuster(string $musterClass, MusterContext $context): Muster
    {
        if (!class_exists($musterClass)) {
            throw new InvalidArgumentException(sprintf('Muster class [%s] was not found.', $musterClass));
        }

        if (!is_subclass_of($musterClass, Muster::class)) {
            throw new InvalidArgumentException(sprintf('Class [%s] must extend %s.', $musterClass, Muster::class));
        }

        /** @var class-string<Muster> $musterClass */
        return new $musterClass($context);
    }

    /**
     * Emit a command-line message to WP-CLI or STDOUT fallback.
     *
     * See: https://make.wordpress.org/cli/handbook/references/internal-api/wp-cli-line/
     *
     * @param string $message
     * @return void
     */
    private static function emit(string $message): void
    {
        if (class_exists('\\WP_CLI')) {
            \WP_CLI::line($message);

            return;
        }

        echo $message . "\n";
    }

    /**
     * Emit a failure message and terminate with non-zero status.
     *
     * Uses `WP_CLI::error()` when available; otherwise writes to STDERR and exits.
     *
     * See: https://make.wordpress.org/cli/handbook/references/internal-api/wp-cli-error/
     *
     * @param string $message
     * @return never
     */
    private static function fail(string $message): never
    {
        if (class_exists('\\WP_CLI')) {
            \WP_CLI::error($message);
        }

        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

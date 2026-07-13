<?php

namespace PressGang\Muster\Cli;

use InvalidArgumentException;
use PressGang\Muster\Contracts\WpCliLogger;
use PressGang\Muster\Muster;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Victuals\VictualsFactory;

/**
 * Shared wiring for Muster's WP-CLI commands.
 *
 * Owns the three concerns every command repeats — flag parsing into a
 * context, Muster class validation, and CLI output — so `wp capstan muster`
 * and `wp capstan seed` stay thin and behave identically.
 */
final class Invoker
{
    /**
     * Build a MusterContext from the standard CLI flags
     * (`--seed=<int>`, `--dry-run`, `--only=<csv>`).
     *
     * ACF integration is auto-wired: when ACF is active the LiveAcfAdapter
     * applies `->acf()` payloads via update_field(); otherwise they no-op.
     *
     * @param array<string, mixed> $assocArgs
     * @return MusterContext
     */
    public static function contextFromFlags(array $assocArgs): MusterContext
    {
        $onlyRaw = (string) ($assocArgs['only'] ?? '');
        $only = $onlyRaw === ''
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $onlyRaw)), static fn (string $v): bool => $v !== ''));

        return new MusterContext(
            new VictualsFactory(),
            logger: new WpCliLogger(),
            acf: function_exists('update_field') ? new \PressGang\Muster\Adapters\LiveAcfAdapter() : null,
            seed: isset($assocArgs['seed']) ? (int) $assocArgs['seed'] : null,
            dryRun: isset($assocArgs['dry-run']),
            onlyPatterns: $only,
        );
    }

    /**
     * Instantiate and validate a Muster class string.
     *
     * @param string $musterClass
     * @param MusterContext $context
     * @return Muster
     * @throws InvalidArgumentException If the class is missing or not a Muster subtype.
     */
    public static function makeMuster(string $musterClass, MusterContext $context): Muster
    {
        if (! class_exists($musterClass)) {
            throw new InvalidArgumentException(sprintf('Muster class [%s] was not found.', $musterClass));
        }

        if (! is_subclass_of($musterClass, Muster::class)) {
            throw new InvalidArgumentException(sprintf('Class [%s] must extend %s.', $musterClass, Muster::class));
        }

        /** @var class-string<Muster> $musterClass */
        return new $musterClass($context);
    }

    /**
     * Emit a command-line message to WP-CLI or STDOUT fallback.
     *
     * @param string $message
     * @return void
     */
    public static function emit(string $message): void
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
     * @param string $message
     * @return never
     */
    public static function fail(string $message): never
    {
        if (class_exists('\\WP_CLI')) {
            \WP_CLI::error($message);
        }

        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

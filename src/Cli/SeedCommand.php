<?php

namespace PressGang\Muster\Cli;

/**
 * `wp capstan seed` — run the theme's development seed.
 *
 * The WordPress counterpart to Laravel's `php artisan db:seed`: a deliberate,
 * human-invoked fill of the environment you're pointing at. By convention the
 * theme owns a `{ChildNamespace}\Muster\SiteMuster` (scaffold one with
 * `wp capstan make muster`); name a different class as the first argument.
 *
 * Guard rail: refuses outright when `wp_get_environment_type()` reports
 * `production` — seeding is for development and disposable environments,
 * and that refusal is not flag-overridable by design.
 *
 * Usage:
 * `wp capstan seed [<muster-class>] [--fresh] [--seed=<int>] [--dry-run] [--only=<csv>]`
 *
 * - `--fresh` calls the Muster's `fresh()` method (clean-slate reset) before
 *   seeding; errors if the class doesn't define one.
 * - Remaining flags behave exactly as in `wp capstan muster`.
 */
final class SeedCommand
{
    /**
     * Register the `wp capstan seed` command when WP-CLI is available.
     *
     * @return void
     */
    public static function register(): void
    {
        if (! defined('WP_CLI') || ! \WP_CLI) {
            return;
        }

        if (class_exists('\WP_CLI')) {
            \WP_CLI::add_command('capstan seed', [self::class, 'handle']);
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
        if (function_exists('wp_get_environment_type') && wp_get_environment_type() === 'production') {
            Invoker::fail('Refusing to seed: this environment reports WP_ENVIRONMENT_TYPE=production.');
        }

        $musterClass = (string) ($args[0] ?? self::defaultMusterClass());

        // Validation failures exit directly; only RUNTIME failures below get
        // the "Seed failed:" wrapper — keeping fail() calls out of the try
        // avoids re-wrapping our own exit exception.
        try {
            $muster = Invoker::makeMuster($musterClass, Invoker::contextFromFlags($assocArgs));
        } catch (\InvalidArgumentException $e) {
            Invoker::fail($e->getMessage());
        }

        if (isset($assocArgs['fresh']) && ! method_exists($muster, 'fresh')) {
            Invoker::fail(sprintf(
                '--fresh requested but %s has no fresh() method — add one that truncates the content it seeds.',
                $musterClass
            ));
        }

        try {
            if (isset($assocArgs['fresh'])) {
                $muster->fresh();
                Invoker::emit('Fresh: previously seeded content cleared.');
            }

            $muster->run();

            Invoker::emit('Seed complete.');
        } catch (\Throwable $e) {
            Invoker::fail('Seed failed: ' . $e->getMessage());
        }
    }

    /**
     * The conventional theme seed class: `{ChildNamespace}\Muster\SiteMuster`.
     *
     * @return string
     */
    private static function defaultMusterClass(): string
    {
        $namespace = function_exists('get_child_theme_namespace') ? \get_child_theme_namespace() : null;

        if ($namespace === null) {
            Invoker::fail('No Muster class given and the child theme namespace is not resolvable — pass the class explicitly, e.g. wp capstan seed "App\\Muster\\SiteMuster".');
        }

        return "{$namespace}\\Muster\\SiteMuster";
    }
}

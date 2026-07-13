<?php

namespace PressGang\Muster\Cli;

/**
 * `wp capstan seed` — run the theme's development seed.
 *
 * A deliberate, human-invoked provisioning run for the WordPress environment
 * you're pointing at. By convention the theme owns a
 * `{ChildNamespace}\Muster\SiteMuster` (scaffold one with
 * `wp capstan make muster`); name a different class as the first argument.
 *
 * Guard rail: refuses outright when `wp_get_environment_type()` reports
 * `production` — seeding is for development and disposable environments,
 * and that refusal is not flag-overridable by design.
 *
 * Usage:
 * `wp capstan seed [<muster-class>] [--fresh] [--seed=<int>] [--epoch=<datetime>] [--dry-run] [--only=<csv>] [--format=json]`
 *
 * - `--fresh` deletes only resources recorded as owned by the selected Muster
 *   before seeding.
 * - Every invocation plans first; `--dry-run` skips the application pass.
 * - `--epoch` pins relative fixture dates independently of the random seed.
 * - `--only` selects named declaration groups.
 * - `--format=json` emits one machine-readable reconciliation payload.
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

        $result = Invoker::reconcile($musterClass, $assocArgs, isset($assocArgs['fresh']));
        Invoker::emitReconciliation($musterClass, $result, $assocArgs);

        if ($result['error'] !== null) {
            if (Invoker::isJson($assocArgs)) {
                Invoker::halt();
            }

            Invoker::fail('Seed failed: ' . $result['error']->getMessage());
        }

        if (!Invoker::isJson($assocArgs)) {
            Invoker::emit(isset($assocArgs['dry-run']) ? 'Seed plan complete.' : 'Seed applied.');
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

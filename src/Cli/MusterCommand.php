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
 * `wp capstan muster <muster-class> [--seed=<int>] [--epoch=<datetime>] [--dry-run] [--only=<csv>] [--format=json] [--verbose|--quiet]`
 *
 * The command always plans first. Unless `--dry-run` is present, it then runs
 * a revalidated application pass. `--only` selects named declaration groups,
 * `--format=json` emits one structured payload, while `--verbose` and `--quiet`
 * control human-readable diagnostics.
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
        Invoker::registerCommand('muster', [self::class, 'handle']);
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

        $result = Invoker::reconcile($musterClass, $assocArgs);
        Invoker::emitReconciliation($musterClass, $result, $assocArgs);
        Invoker::finish('Muster', $result, $assocArgs);
    }
}

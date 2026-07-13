<?php

namespace PressGang\Muster\Cli;

use InvalidArgumentException;
use PressGang\Muster\Contracts\NullLogger;
use PressGang\Muster\Contracts\WpCliLogger;
use PressGang\Muster\Muster;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Results\Operation;
use PressGang\Muster\Results\OperationAction;
use PressGang\Muster\Results\RunReport;
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
     * @param bool|null $dryRun Override the flag for an internal plan/apply pass.
     * @return MusterContext
     */
    public static function contextFromFlags(array $assocArgs, ?bool $dryRun = null): MusterContext
    {
        $onlyRaw = (string) ($assocArgs['only'] ?? '');
        $only = $onlyRaw === ''
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $onlyRaw)), static fn (string $v): bool => $v !== ''));

        return new MusterContext(
            new VictualsFactory(),
            logger: self::isJson($assocArgs) ? new NullLogger() : new WpCliLogger(),
            acf: function_exists('update_field') ? new \PressGang\Muster\Adapters\LiveAcfAdapter() : null,
            seed: isset($assocArgs['seed']) ? (int) $assocArgs['seed'] : null,
            dryRun: $dryRun ?? isset($assocArgs['dry-run']),
            onlyPatterns: $only,
        );
    }

    /**
     * Execute a read-only planning pass and, unless requested otherwise, a
     * second deterministic application pass.
     *
     * @param string $musterClass
     * @param array<string, mixed> $assocArgs
     * @param bool $fresh Reset owned resources before each pass.
     * @return array{plan: RunReport, apply: RunReport|null, error: \Throwable|null}
     */
    public static function reconcile(string $musterClass, array $assocArgs, bool $fresh = false): array
    {
        $planContext = self::contextFromFlags($assocArgs, true);
        $error = self::runPass($musterClass, $planContext, $fresh);
        $applyReport = null;

        if ($error === null && !isset($assocArgs['dry-run']) && !$planContext->report()->hasConflicts()) {
            $applyContext = self::contextFromFlags($assocArgs, false);
            $error = self::runPass($musterClass, $applyContext, $fresh);
            $applyReport = $applyContext->report();
        }

        return [
            'plan' => $planContext->report(),
            'apply' => $applyReport,
            'error' => $error,
        ];
    }

    /**
     * Emit text or JSON reconciliation output.
     *
     * @param string $musterClass
     * @param array{plan: RunReport, apply: RunReport|null, error: \Throwable|null} $result
     * @param array<string, mixed> $assocArgs
     * @return void
     */
    public static function emitReconciliation(string $musterClass, array $result, array $assocArgs): void
    {
        if (self::isJson($assocArgs)) {
            self::emit((string) json_encode([
                'muster' => $musterClass,
                'status' => $result['error'] !== null
                    ? 'conflict'
                    : ($result['apply'] === null ? 'planned' : 'applied'),
                'plan' => $result['plan']->toArray(),
                'apply' => $result['apply']?->toArray(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return;
        }

        self::emitReport('Plan', $result['plan']);
        if ($result['apply'] !== null) {
            self::emitReport('Apply', $result['apply']);
        }
    }

    /**
     * @param string $musterClass
     * @param MusterContext $context
     * @param bool $fresh
     * @return \Throwable|null
     */
    private static function runPass(string $musterClass, MusterContext $context, bool $fresh): ?\Throwable
    {
        try {
            $muster = self::makeMuster($musterClass, $context);
            if ($fresh) {
                $muster->resetOwned();
            }
            $muster->run();

            return null;
        } catch (\Throwable $error) {
            if (!$context->report()->hasConflicts()) {
                $context->report()->add(new Operation(
                    OperationAction::Conflict,
                    'muster',
                    $musterClass,
                    '',
                    '',
                    0,
                    $error->getMessage()
                ));
            }

            return $error;
        }
    }

    private static function emitReport(string $label, RunReport $report): void
    {
        self::emit($label . ':');

        foreach ($report->operations() as $operation) {
            $row = $operation->toArray();
            $identity = $row['key'] !== '' ? $row['key'] : $row['locator'];
            $message = sprintf(
                '  %-8s %-10s %s%s',
                strtoupper((string) $row['action']),
                (string) $row['resource'],
                (string) $identity,
                $row['locator'] !== '' && $row['locator'] !== $identity ? ' -> ' . $row['locator'] : ''
            );

            if (is_string($row['message']) && $row['message'] !== '') {
                $message .= ': ' . $row['message'];
            }

            self::emit($message);
        }

        $summary = $report->summary();
        self::emit(sprintf(
            '  Summary: create=%d update=%d keep=%d prune=%d conflict=%d',
            $summary['create'],
            $summary['update'],
            $summary['keep'],
            $summary['prune'],
            $summary['conflict']
        ));
    }

    /**
     * @param array<string, mixed> $assocArgs
     * @return bool
     */
    public static function isJson(array $assocArgs): bool
    {
        return strtolower((string) ($assocArgs['format'] ?? 'text')) === 'json';
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

    /**
     * Exit non-zero after a structured payload has already been emitted.
     *
     * @return never
     */
    public static function halt(): never
    {
        if (class_exists('\\WP_CLI') && method_exists('\\WP_CLI', 'halt')) {
            \WP_CLI::halt(1);
        }

        exit(1);
    }
}

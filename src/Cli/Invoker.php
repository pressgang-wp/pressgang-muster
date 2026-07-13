<?php

namespace PressGang\Muster\Cli;

use InvalidArgumentException;
use PressGang\Muster\Clock\FixtureClock;
use PressGang\Muster\Adapters\NullLogger;
use PressGang\Muster\Adapters\WpCliLogger;
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
     * (`--seed=<int>`, `--epoch=<datetime>`, `--dry-run`, `--only=<csv>`,
     * `--verbose`, and `--quiet`).
     *
     * ACF integration is auto-wired: when ACF is active the LiveAcfAdapter
     * applies `->acf()` payloads via update_field(); otherwise they no-op.
     *
     * @param array<string, mixed> $assocArgs
     * @param bool|null $dryRun Override the flag for an internal plan/apply pass.
     * @param FixtureClock|null $clock Shared clock for an internal plan/apply lifecycle.
     * @return MusterContext
     */
    public static function contextFromFlags(
        array $assocArgs,
        ?bool $dryRun = null,
        ?FixtureClock $clock = null,
    ): MusterContext
    {
        if (isset($assocArgs['verbose']) && isset($assocArgs['quiet'])) {
            throw new InvalidArgumentException('--verbose and --quiet cannot be used together.');
        }

        $onlyRaw = (string) ($assocArgs['only'] ?? '');
        $only = $onlyRaw === ''
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $onlyRaw)), static fn (string $v): bool => $v !== ''));

        return new MusterContext(
            new VictualsFactory(),
            logger: self::loggerFromFlags($assocArgs),
            acf: function_exists('update_field') ? new \PressGang\Muster\Adapters\LiveAcfAdapter() : null,
            seed: isset($assocArgs['seed']) ? (int) $assocArgs['seed'] : null,
            dryRun: $dryRun ?? isset($assocArgs['dry-run']),
            onlyGroups: $only,
            clock: $clock ?? self::clockFromFlags($assocArgs),
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
        try {
            $clock = self::clockFromFlags($assocArgs, $musterClass);
            $planContext = self::contextFromFlags($assocArgs, true, $clock);
        } catch (\Throwable $error) {
            return self::failedPlan($musterClass, $assocArgs, $error);
        }

        $error = self::runPass($musterClass, $planContext, $fresh);
        $applyReport = null;

        if ($error === null && !isset($assocArgs['dry-run']) && !$planContext->report()->hasConflicts()) {
            $applyContext = self::contextFromFlags($assocArgs, false, $clock);
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
     * Report a context-construction failure as a conflicted plan.
     *
     * Flag parsing can fail before any usable context exists (bad `--epoch`,
     * clashing `--verbose`/`--quiet`); the CLI still owes its caller a
     * structured report rather than a bare exception.
     *
     * @param string $musterClass
     * @param array<string, mixed> $assocArgs
     * @param \Throwable $error
     * @return array{plan: RunReport, apply: null, error: \Throwable}
     */
    private static function failedPlan(string $musterClass, array $assocArgs, \Throwable $error): array
    {
        $context = new MusterContext(
            new VictualsFactory(),
            logger: self::loggerFromFlags($assocArgs),
            dryRun: true,
        );
        $context->report()->add(new Operation(
            OperationAction::Conflict,
            'muster',
            $musterClass,
            '',
            '',
            0,
            $error->getMessage()
        ));

        return [
            'plan' => $context->report(),
            'apply' => null,
            'error' => $error,
        ];
    }

    /**
     * Register one `wp capstan <name>` subcommand when WP-CLI is available.
     *
     * @param string $name Subcommand name, e.g. `muster` or `seed`.
     * @param callable $handler Handler receiving (array $args, array $assocArgs).
     * @return void
     */
    public static function registerCommand(string $name, callable $handler): void
    {
        if (! defined('WP_CLI') || ! \WP_CLI || ! class_exists('\WP_CLI')) {
            return;
        }

        \WP_CLI::add_command('capstan ' . $name, $handler);
    }

    /**
     * Shared command tail: surface the reconciliation error, or confirm the
     * outcome for human-readable runs.
     *
     * @param string $label Command label used in messages, e.g. `Muster` or `Seed`.
     * @param array{plan: RunReport, apply: RunReport|null, error: \Throwable|null} $result
     * @param array<string, mixed> $assocArgs
     * @return void
     */
    public static function finish(string $label, array $result, array $assocArgs): void
    {
        if ($result['error'] !== null) {
            if (self::isJson($assocArgs)) {
                self::halt();
            }

            self::fail(sprintf('%s failed: %s', $label, $result['error']->getMessage()));
        }

        if (!self::isJson($assocArgs) && !self::isQuiet($assocArgs)) {
            self::emit(sprintf(isset($assocArgs['dry-run']) ? '%s plan complete.' : '%s applied.', $label));
        }
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

        if (self::isQuiet($assocArgs)) {
            return;
        }

        $verbose = isset($assocArgs['verbose']);
        self::emitReport('Plan', $result['plan'], $verbose);
        if ($result['apply'] !== null) {
            self::emitReport('Apply', $result['apply'], $verbose);
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
                // `--fresh --only` intentionally resets the complete ownership
                // scope before selected groups run. This lifecycle reset is not
                // equivalent to a resetOwned() declaration inside run().
                $context->ownership()->reset($musterClass);
            }
            $muster->run();
            $context->scope()->assertOnlyGroupsResolved();

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

    private static function emitReport(string $label, RunReport $report, bool $verbose = false): void
    {
        self::emit($label . ':');

        foreach ($report->operations() as $operation) {
            $row = $operation->toArray();
            $identity = $row['key'] !== '' ? $row['key'] : $row['locator'];
            $message = sprintf(
                '  %-8s %-10s %s%s%s',
                strtoupper((string) $row['action']),
                (string) $row['resource'],
                is_string($row['group']) && $row['group'] !== '' ? '[' . $row['group'] . '] ' : '',
                (string) $identity,
                $row['locator'] !== '' && $row['locator'] !== $identity ? ' -> ' . $row['locator'] : ''
            );

            if (is_string($row['message']) && $row['message'] !== '') {
                $message .= ': ' . $row['message'];
            }

            self::emit($message);

            if ($verbose) {
                self::emit(sprintf(
                    '    scope=%s key=%s locator=%s id=%d group=%s',
                    (string) $row['scope'],
                    (string) $row['key'],
                    (string) $row['locator'],
                    (int) $row['id'],
                    is_string($row['group']) && $row['group'] !== '' ? $row['group'] : '-'
                ));
            }
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
     * @param array<string, mixed> $assocArgs
     * @return bool
     */
    public static function isQuiet(array $assocArgs): bool
    {
        return isset($assocArgs['quiet']) && !self::isJson($assocArgs);
    }

    /**
     * @param array<string, mixed> $assocArgs
     * @return \PressGang\Muster\Contracts\LoggerInterface
     */
    private static function loggerFromFlags(array $assocArgs): \PressGang\Muster\Contracts\LoggerInterface
    {
        if (self::isJson($assocArgs)) {
            return new NullLogger();
        }

        return new WpCliLogger(
            verbose: isset($assocArgs['verbose']),
            quiet: self::isQuiet($assocArgs)
        );
    }

    /**
     * Parse an absolute fixture epoch or capture the process clock once.
     *
     * @param array<string, mixed> $assocArgs
     * @param string $musterClass Optional Muster class providing a default epoch.
     * @return FixtureClock
     */
    private static function clockFromFlags(array $assocArgs, string $musterClass = ''): FixtureClock
    {
        if (array_key_exists('epoch', $assocArgs)) {
            return new FixtureClock((string) $assocArgs['epoch']);
        }

        if ($musterClass !== ''
            && class_exists($musterClass)
            && is_subclass_of($musterClass, Muster::class)) {
            /** @var class-string<Muster> $musterClass */
            $epoch = $musterClass::defaultEpoch();
            if ($epoch !== null) {
                return new FixtureClock($epoch);
            }
        }

        return FixtureClock::system();
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

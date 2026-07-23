<?php

namespace PressGang\Muster;

use LogicException;
use PressGang\Muster\Acf\ThemeAcf;
use PressGang\Muster\Adapters\AcfAdapterInterface;
use PressGang\Muster\Adapters\NullAcfAdapter;
use PressGang\Muster\Clock\FixtureClock;
use PressGang\Muster\Contracts\LoggerInterface;
use PressGang\Muster\Adapters\NullLogger;
use PressGang\Muster\Ownership\OwnershipRegistry;
use PressGang\Muster\Results\RunReport;
use PressGang\Muster\Victuals\Victuals;
use PressGang\Muster\Victuals\VictualsFactory;

/**
 * Dependency bag shared by every component of a single Muster execution.
 *
 * The context exposes the run's collaborators — generated values, clock,
 * logger, ACF adapter, ownership registry, reconciliation report — plus two
 * focused state holders: {@see DeclarationScope} for named-group rules and
 * {@see MusterCallGraph} for chained `Muster::call()` dependencies.
 */
final class MusterContext
{
    private ?Victuals $victuals = null;

    private ?OwnershipRegistry $ownership = null;

    private RunReport $report;

    private FixtureClock $clock;

    private bool $clockConfigured;

    private DeclarationScope $scope;

    private MusterCallGraph $callGraph;

    /**
     * @param VictualsFactory $victualsFactory
     * @param LoggerInterface|null $logger
     * @param AcfAdapterInterface|null $acf
     * @param int|null $seed Global seed applied when no per-pattern override is set.
     * @param array<string, int> $seedOverrides Per-pattern seed overrides by name.
     * @param bool $dryRun
     * @param array<int, string> $onlyGroups Optional allowlist of declaration group names.
     * @param FixtureClock|null $clock Reference clock shared by all generated dates.
     */
    public function __construct(
        private VictualsFactory $victualsFactory,
        private ?LoggerInterface $logger = null,
        private ?AcfAdapterInterface $acf = null,
        private ?int $seed = null,
        private array $seedOverrides = [],
        private bool $dryRun = false,
        array $onlyGroups = [],
        ?FixtureClock $clock = null,
    ) {
        $this->logger ??= new NullLogger();
        $this->acf ??= new NullAcfAdapter();
        $this->report = new RunReport();
        $this->clockConfigured = $clock !== null;
        $this->clock = $clock ?? FixtureClock::system();
        $this->scope = new DeclarationScope($this->logger, $onlyGroups);
        $this->callGraph = new MusterCallGraph();
    }

    /**
     * Access the declaration-group state and `--only` partial-run rules.
     *
     * @return DeclarationScope
     */
    public function scope(): DeclarationScope
    {
        return $this->scope;
    }

    /**
     * Access the chained `Muster::call()` dependency graph.
     *
     * @return MusterCallGraph
     */
    public function callGraph(): MusterCallGraph
    {
        return $this->callGraph;
    }

    /**
     * @return Victuals
     *
     * Returns a cached Victuals instance for the context lifecycle so repeated calls
     * continue the same seeded pseudo-random sequence.
     */
    public function victuals(): Victuals
    {
        $this->victuals ??= $this->victualsFactory->make($this->seed, $this->clock);

        return $this->victuals;
    }

    /**
     * @param int|null $seed
     * @return Victuals
     *
     * Returns a fresh Victuals instance for explicit scope boundaries (for example, Pattern runs).
     */
    public function victualsForSeed(?int $seed = null): Victuals
    {
        return $this->victualsFactory->make($seed ?? $this->seed, $this->clock);
    }

    /**
     * @return VictualsFactory
     */
    public function victualsFactory(): VictualsFactory
    {
        return $this->victualsFactory;
    }

    /**
     * Access the immutable fixture clock for this execution pass.
     *
     * @return FixtureClock
     */
    public function clock(): FixtureClock
    {
        return $this->clock;
    }

    /**
     * Report whether an explicit or scenario-default clock is already fixed.
     *
     * @return bool
     */
    public function hasConfiguredClock(): bool
    {
        return $this->clockConfigured;
    }

    /**
     * Apply a Muster's default clock when the caller did not provide one.
     *
     * This construction-time hook refuses to replace a clock after Victuals
     * has been created, which would split one run across two time references.
     *
     * @param FixtureClock $clock
     * @return void
     */
    public function useDefaultClock(FixtureClock $clock): void
    {
        if ($this->clockConfigured) {
            return;
        }

        if ($this->victuals !== null) {
            throw new LogicException('Cannot apply a Muster default epoch after Victuals has been created.');
        }

        $this->clock = $clock;
        $this->clockConfigured = true;
    }

    /**
     * @return LoggerInterface
     */
    public function logger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Log declared field names for verbose diagnostics without exposing values.
     *
     * @param string $resource
     * @param array<int, string|int> $fields
     * @return void
     */
    public function debugDeclaration(string $resource, array $fields): void
    {
        $fields = array_values(array_unique(array_map('strval', $fields)));
        sort($fields);

        $this->logger->debug(sprintf(
            '%s declaration fields: %s.',
            $resource,
            $fields === [] ? '(none)' : implode(', ', $fields)
        ));
    }

    /**
     * @return AcfAdapterInterface
     */
    public function acf(): AcfAdapterInterface
    {
        return $this->acf;
    }

    /**
     * The top-level ACF field names the active theme's acf-json registers for a
     * target (a post type slug or template path).
     *
     * Read-only schema lookup, no writes. Builders use it to reject a raw
     * `meta()` key that names an ACF field (see {@see \PressGang\Muster\Builders\PostBuilder::save()}):
     * such a value belongs in `acf()`/`update_field()`, which also stores the
     * field-key reference `get_field()` needs. Returns an empty list when the
     * theme ships no acf-json or none of its groups target $target — so with no
     * ACF schema present the guard is simply inert.
     *
     * @param string $target A post type slug or page/post template path.
     * @return list<string>
     */
    public function acfFieldNames(string $target): array
    {
        return ThemeAcf::fieldNamesFor($target);
    }

    /**
     * Access the ownership registry for keyed Muster resources.
     *
     * The registry is scoped by concrete Muster class at the builder and
     * orchestration layers; this context owns only its WordPress persistence.
     *
     * @return OwnershipRegistry
     */
    public function ownership(): OwnershipRegistry
    {
        $this->ownership ??= new OwnershipRegistry($this);

        return $this->ownership;
    }

    /**
     * Access the ordered reconciliation report for this execution pass.
     *
     * @return RunReport
     */
    public function report(): RunReport
    {
        return $this->report;
    }

    /**
     * @return int|null
     */
    public function seed(): ?int
    {
        return $this->seed;
    }

    /**
     * @return array<string, int>
     */
    public function seedOverrides(): array
    {
        return $this->seedOverrides;
    }

    /**
     * @param string $name
     * @return int|null
     */
    public function seedForPattern(string $name): ?int
    {
        return $this->seedOverrides[$name] ?? $this->seed;
    }

    /**
     * @return bool
     */
    public function dryRun(): bool
    {
        return $this->dryRun;
    }
}

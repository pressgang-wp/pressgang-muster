<?php

namespace PressGang\Muster;

use LogicException;
use PressGang\Muster\Adapters\AcfAdapterInterface;
use PressGang\Muster\Adapters\NullAcfAdapter;
use PressGang\Muster\Clock\FixtureClock;
use PressGang\Muster\Contracts\LoggerInterface;
use PressGang\Muster\Contracts\NullLogger;
use PressGang\Muster\Ownership\OwnershipRegistry;
use PressGang\Muster\Ownership\OwnedResource;
use PressGang\Muster\Results\RunReport;
use PressGang\Muster\Victuals\Victuals;
use PressGang\Muster\Victuals\VictualsFactory;

/**
 * Shared runtime context for a single Muster execution.
 */
final class MusterContext
{
    private ?Victuals $victuals = null;

    private ?OwnershipRegistry $ownership = null;

    private RunReport $report;

    private FixtureClock $clock;

    private bool $clockConfigured;

    private ?string $activeGroup = null;

    /**
     * @var array<string, true>
     */
    private array $declaredGroups = [];

    /**
     * @var array<string, true>
     */
    private array $plannedDeletions = [];

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
        private array $onlyGroups = [],
        ?FixtureClock $clock = null,
    ) {
        $this->logger ??= new NullLogger();
        $this->acf ??= new NullAcfAdapter();
        $this->report = new RunReport();
        $this->clockConfigured = $clock !== null;
        $this->clock = $clock ?? FixtureClock::system();
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
     * @return AcfAdapterInterface
     */
    public function acf(): AcfAdapterInterface
    {
        return $this->acf;
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
     * Mark a resource as absent from the planning overlay after planned pruning.
     *
     * @param OwnedResource $resource
     * @return void
     */
    public function markPlannedDeletion(OwnedResource $resource): void
    {
        $this->plannedDeletions[$this->resourceToken(
            $resource->type(),
            $resource->id(),
            $resource->subtype(),
            $resource->locator()
        )] = true;
    }

    /**
     * Check whether a prior planned operation removes this resource.
     *
     * @param string $type
     * @param int $id
     * @param string $subtype
     * @param string $locator
     * @return bool
     */
    public function isPlannedDeleted(string $type, int $id, string $subtype, string $locator): bool
    {
        return isset($this->plannedDeletions[$this->resourceToken($type, $id, $subtype, $locator)]);
    }

    private function resourceToken(string $type, int $id, string $subtype, string $locator): string
    {
        $family = match ($type) {
            'attachment' => 'post',
            'menu' => 'term',
            default => $type,
        };

        return $id > 0
            ? sprintf('%s:id:%d', $family, $id)
            : sprintf('%s:%s:%s', $family, $subtype, $locator);
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

    /**
     * Return configured `--only` declaration group allowlist values.
     *
     * @return array<int, string>
     */
    public function onlyGroups(): array
    {
        return $this->onlyGroups;
    }

    /**
     * Enter a named declaration group when it is selected for this run.
     *
     * Group names must be unique within one Muster pass. A filtered-out group
     * is still registered so unknown `--only` values can be reported, but its
     * callback must not be invoked by the caller.
     *
     * @param string $name
     * @return bool Whether the group is selected and has been entered.
     */
    public function enterGroup(string $name): bool
    {
        $name = trim($name);

        if ($name === '') {
            throw new LogicException('Muster group names must not be empty.');
        }

        if ($this->activeGroup !== null) {
            throw new LogicException(sprintf(
                'Muster group [%s] cannot be nested inside active group [%s].',
                $name,
                $this->activeGroup
            ));
        }

        if (isset($this->declaredGroups[$name])) {
            throw new LogicException(sprintf('Muster group [%s] was declared more than once.', $name));
        }

        $this->declaredGroups[$name] = true;

        if ($this->onlyGroups !== [] && !in_array($name, $this->onlyGroups, true)) {
            $this->logger->info(sprintf('Skipping group [%s] due to --only filter.', $name));

            return false;
        }

        $this->activeGroup = $name;

        return true;
    }

    /**
     * Leave the active declaration group.
     *
     * @return void
     */
    public function leaveGroup(): void
    {
        if ($this->activeGroup === null) {
            throw new LogicException('Cannot leave a Muster group when no group is active.');
        }

        $this->activeGroup = null;
    }

    /**
     * Return the group currently evaluating declarations, if any.
     *
     * @return string|null
     */
    public function activeGroup(): ?string
    {
        return $this->activeGroup;
    }

    /**
     * Require partial runs to place every data declaration inside a group.
     *
     * This fails before a builder can perform WordPress reads or writes. Full
     * runs remain free to use ungrouped declarations when filtering is not needed.
     *
     * @param string $declaration Human-readable declaration type for the error.
     * @return void
     */
    public function assertDeclarationAllowed(string $declaration): void
    {
        if ($this->onlyGroups !== [] && $this->activeGroup === null) {
            throw new LogicException(sprintf(
                '%s was declared outside a named group during a partial --only run. Wrap it in $this->group(...).',
                $declaration
            ));
        }
    }

    /**
     * Reject whole-scope reconciliation during a partial group run.
     *
     * @param string $operation
     * @return void
     */
    public function assertCompleteRun(string $operation): void
    {
        if ($this->onlyGroups !== []) {
            throw new LogicException(sprintf(
                '%s requires a complete Muster run and cannot be used with --only.',
                $operation
            ));
        }
    }

    /**
     * Verify that every requested group was declared by the completed Muster.
     *
     * @return void
     */
    public function assertOnlyGroupsResolved(): void
    {
        $unknown = array_values(array_diff($this->onlyGroups, array_keys($this->declaredGroups)));

        if ($unknown === []) {
            return;
        }

        $available = array_keys($this->declaredGroups);
        $guidance = $available === []
            ? ' This Muster declared no groups.'
            : ' Available groups: ' . implode(', ', $available) . '.';

        throw new LogicException(sprintf(
            'Unknown Muster group%s requested by --only: %s.%s',
            count($unknown) === 1 ? '' : 's',
            implode(', ', $unknown),
            $guidance
        ));
    }
}

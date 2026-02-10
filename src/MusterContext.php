<?php

namespace PressGang\Muster;

use PressGang\Muster\Adapters\AcfAdapterInterface;
use PressGang\Muster\Adapters\NullAcfAdapter;
use PressGang\Muster\Contracts\LoggerInterface;
use PressGang\Muster\Contracts\NullLogger;
use PressGang\Muster\Victuals\Victuals;
use PressGang\Muster\Victuals\VictualsFactory;

/**
 * Shared runtime context for a single Muster execution.
 */
final class MusterContext
{
    private ?Victuals $victuals = null;

    /**
     * @param VictualsFactory $victualsFactory
     * @param LoggerInterface|null $logger
     * @param AcfAdapterInterface|null $acf
     * @param int|null $seed Global seed applied when no per-pattern override is set.
     * @param array<string, int> $seedOverrides Per-pattern seed overrides by name.
     * @param bool $dryRun
     * @param array<int, string> $onlyPatterns Optional allowlist of pattern names.
     */
    public function __construct(
        private VictualsFactory $victualsFactory,
        private ?LoggerInterface $logger = null,
        private ?AcfAdapterInterface $acf = null,
        private ?int $seed = null,
        private array $seedOverrides = [],
        private bool $dryRun = false,
        private array $onlyPatterns = [],
    ) {
        $this->logger ??= new NullLogger();
        $this->acf ??= new NullAcfAdapter();
    }

    /**
     * @return Victuals
     *
     * Returns a cached Victuals instance for the context lifecycle so repeated calls
     * continue the same seeded pseudo-random sequence.
     */
    public function victuals(): Victuals
    {
        $this->victuals ??= $this->victualsFactory->make($this->seed);

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
        return $this->victualsFactory->make($seed ?? $this->seed);
    }

    /**
     * @return VictualsFactory
     */
    public function victualsFactory(): VictualsFactory
    {
        return $this->victualsFactory;
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
     * Return configured `--only` pattern allowlist values.
     *
     * @return array<int, string>
     */
    public function onlyPatterns(): array
    {
        return $this->onlyPatterns;
    }

    /**
     * Check whether a named pattern should execute under current filter settings.
     *
     * When no allowlist is configured, all patterns are allowed.
     *
     * @param string $name
     * @return bool
     */
    public function shouldRunPattern(string $name): bool
    {
        if ($this->onlyPatterns === []) {
            return true;
        }

        return in_array($name, $this->onlyPatterns, true);
    }
}

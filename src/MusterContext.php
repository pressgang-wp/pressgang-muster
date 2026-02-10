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
     */
    public function __construct(
        private VictualsFactory $victualsFactory,
        private ?LoggerInterface $logger = null,
        private ?AcfAdapterInterface $acf = null,
        private ?int $seed = null,
        private array $seedOverrides = [],
        private bool $dryRun = false,
    ) {
        $this->logger ??= new NullLogger();
        $this->acf ??= new NullAcfAdapter();
    }

    /**
     * @return Victuals
     */
    public function victuals(): Victuals
    {
        $this->victuals ??= $this->victualsFactory->make($this->seed);

        return $this->victuals;
    }

    /**
     * @param int|null $seed
     * @return Victuals
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
}

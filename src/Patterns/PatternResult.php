<?php

namespace PressGang\Muster\Patterns;

/**
 * Value object describing a completed pattern execution.
 */
final class PatternResult
{
    /**
     * @param string $name
     * @param int $count
     * @param int|null $seed
     */
    public function __construct(
        private string $name,
        private int $count,
        private ?int $seed,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function count(): int
    {
        return $this->count;
    }

    /**
     * The effective seed the run used, or null for an unseeded run.
     *
     * @return int|null
     */
    public function seed(): ?int
    {
        return $this->seed;
    }
}

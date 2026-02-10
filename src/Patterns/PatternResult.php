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

    /**
     * @return string
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return int
     */
    public function count(): int
    {
        return $this->count;
    }

    /**
     * @return int|null
     */
    public function seed(): ?int
    {
        return $this->seed;
    }
}

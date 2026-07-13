<?php

namespace PressGang\Muster\Patterns;

use LogicException;

/**
 * Immutable deterministic cycle of explicit values for Pattern iterations.
 *
 * Sequence lookup is derived from the one-based iteration index, not mutable
 * internal state, so plan and apply resolve the same value independently.
 */
final class Sequence
{
    /**
     * @var array<int, mixed>
     */
    private array $values;

    public function __construct(mixed ...$values)
    {
        if ($values === []) {
            throw new LogicException('Sequence requires at least one value.');
        }

        $this->values = array_values($values);
    }

    /**
     * Resolve the value for a one-based Pattern iteration.
     *
     * @param int $iteration
     * @return mixed
     */
    public function at(int $iteration): mixed
    {
        if ($iteration < 1) {
            throw new LogicException('Sequence iteration must be at least 1.');
        }

        return $this->values[($iteration - 1) % count($this->values)];
    }

    public function length(): int
    {
        return count($this->values);
    }
}

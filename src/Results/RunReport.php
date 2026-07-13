<?php

namespace PressGang\Muster\Results;

/**
 * Ordered operation report for one planning or application pass.
 */
final class RunReport
{
    /**
     * @var array<int, Operation>
     */
    private array $operations = [];

    /**
     * Append an operation in declaration/execution order.
     *
     * @param Operation $operation
     * @return void
     */
    public function add(Operation $operation): void
    {
        $this->operations[] = $operation;
    }

    /**
     * @return array<int, Operation>
     */
    public function operations(): array
    {
        return $this->operations;
    }

    /**
     * @return array{create: int, update: int, keep: int, prune: int, conflict: int}
     */
    public function summary(): array
    {
        // Derive the tally keys from the enum so a new action case can never
        // silently go uncounted.
        $summary = array_fill_keys(array_column(OperationAction::cases(), 'value'), 0);

        foreach ($this->operations as $operation) {
            $summary[$operation->action()->value]++;
        }

        return $summary;
    }

    /**
     * Determine whether this pass encountered any blocking conflict.
     *
     * @return bool
     */
    public function hasConflicts(): bool
    {
        return $this->summary()[OperationAction::Conflict->value] > 0;
    }

    /**
     * @return array{operations: array<int, array<string, mixed>>, summary: array<string, int>}
     */
    public function toArray(): array
    {
        return [
            'operations' => array_map(
                static fn (Operation $operation): array => $operation->toArray(),
                $this->operations
            ),
            'summary' => $this->summary(),
        ];
    }
}

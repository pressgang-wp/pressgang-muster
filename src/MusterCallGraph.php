<?php

namespace PressGang\Muster;

use LogicException;

/**
 * Tracks the explicit dependency graph of chained `Muster::call()` runs.
 *
 * One execution pass may run each dependency at most once, and recursive
 * chains fail loudly with the full call path — silent re-runs would double
 * every fixture the dependency declares.
 */
final class MusterCallGraph
{
    /**
     * @var array<int, class-string<Muster>>
     */
    private array $stack = [];

    /**
     * @var array<class-string<Muster>, true>
     */
    private array $completed = [];

    /**
     * Enter one explicit dependency edge in a chained Muster graph.
     *
     * @param class-string<Muster> $caller
     * @param class-string<Muster> $target
     * @return void
     */
    public function enter(string $caller, string $target): void
    {
        if ($this->stack === []) {
            $this->stack[] = $caller;
        }

        $active = $this->stack[array_key_last($this->stack)];
        if ($active !== $caller) {
            throw new LogicException(sprintf(
                'Muster call stack expected caller [%s], received [%s].',
                $active,
                $caller
            ));
        }

        if (in_array($target, $this->stack, true)) {
            throw new LogicException(sprintf(
                'Recursive Muster call detected: %s.',
                implode(' -> ', [...$this->stack, $target])
            ));
        }

        if (isset($this->completed[$target])) {
            throw new LogicException(sprintf(
                'Muster [%s] was called more than once in the same execution pass.',
                $target
            ));
        }

        $this->stack[] = $target;
    }

    /**
     * Leave a chained Muster and record successful completion.
     *
     * @param class-string<Muster> $target
     * @param bool $completed
     * @return void
     */
    public function leave(string $target, bool $completed): void
    {
        $active = array_pop($this->stack);
        if ($active !== $target) {
            throw new LogicException(sprintf(
                'Cannot leave Muster [%s]; active call is [%s].',
                $target,
                (string) $active
            ));
        }

        if ($completed) {
            $this->completed[$target] = true;
        }
    }
}

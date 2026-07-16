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
     * The run's root scenario — the outermost caller once any `call()` edge has
     * been entered — or $fallback when nothing has been chained yet (a Muster
     * running standalone).
     *
     * Shared run-scoped resources (e.g. ACF support attachments and stubs that
     * several chained Musters would otherwise each try to own) attribute to this
     * so they are created once and reused, rather than colliding across scopes.
     *
     * @param class-string<Muster> $fallback
     * @return class-string<Muster>
     */
    public function rootOr(string $fallback): string
    {
        return $this->stack[0] ?? $fallback;
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

<?php

namespace PressGang\Muster\Patterns;

use LogicException;
use PressGang\Muster\Muster;
use PressGang\Muster\MusterContext;

/**
 * Factory-style wrapper for deterministic repeated builder execution.
 */
final class Pattern
{
    private int $count = 0;

    private bool $hasCount = false;

    private ?int $seed = null;

    public function __construct(
        private string $name,
        private MusterContext $context,
        private Muster $muster,
        private ?PatternRunner $runner = null,
    ) {
        $this->runner ??= new PatternRunner();
    }

    /**
     * @param int $count
     * @return self
     */
    public function count(int $count): self
    {
        if ($count < 1) {
            throw new LogicException('Pattern count must be at least 1.');
        }

        $this->count = $count;
        $this->hasCount = true;

        return $this;
    }

    /**
     * @param int $seed
     * @return self
     */
    public function seed(int $seed): self
    {
        $this->seed = $seed;

        return $this;
    }

    /**
     * Run the pattern callable.
     *
     * Builder signature:
     * `callable(int $i): \PressGang\Muster\Builders\PostBuilder`
     *
     * @param callable(int): \PressGang\Muster\Builders\PostBuilder $builder
     * @return PatternResult
     */
    public function build(callable $builder): PatternResult
    {
        if (!$this->hasCount) {
            throw new LogicException('Pattern count must be explicitly set before build().');
        }

        $this->runner->run($this, $builder, $this->muster);

        return new PatternResult($this->name, $this->count, $this->effectiveSeed());
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
    public function iterations(): int
    {
        return $this->count;
    }

    /**
     * @return int|null
     */
    public function effectiveSeed(): ?int
    {
        return $this->seed ?? $this->context->seedForPattern($this->name);
    }

    /**
     * @return MusterContext
     */
    public function context(): MusterContext
    {
        return $this->context;
    }
}

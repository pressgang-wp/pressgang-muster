<?php

namespace PressGang\Muster\Patterns;

use PressGang\Muster\Builders\OptionBuilder;
use PressGang\Muster\Builders\PostBuilder;
use PressGang\Muster\Builders\TermBuilder;
use PressGang\Muster\Builders\UserBuilder;
use PressGang\Muster\Muster;
use PressGang\Muster\MusterContext;

/**
 * Factory-style wrapper for deterministic repeated builder execution.
 */
final class Pattern
{
    private int $count = 1;

    private ?int $seed = null;

    public function __construct(
        private string $name,
        private MusterContext $context,
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
        $this->count = $count;

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
     * `callable(int $i, Muster $muster): PostBuilder|TermBuilder|UserBuilder|OptionBuilder`
     *
     * @param callable(int, Muster): PostBuilder|TermBuilder|UserBuilder|OptionBuilder $builder
     * @return PatternResult
     */
    public function build(callable $builder): PatternResult
    {
        $muster = new class($this->context) extends Muster {
            public function run(): void
            {
            }
        };

        $this->runner->run($this, $builder, $muster);

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

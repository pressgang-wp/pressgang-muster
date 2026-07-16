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

    /**
     * @var array<string, callable(object, int): mixed>
     */
    private array $afterHooks = [];

    public function __construct(
        private string $name,
        private MusterContext $context,
        private Muster $muster,
        private ?PatternRunner $runner = null,
    ) {
        $this->runner ??= new PatternRunner();
    }

    /**
     * Set the exact number of iterations `build()` will run.
     *
     * This mutates pattern state only; nothing executes until `build()`.
     *
     * @param int $count
     * @return self
     * @throws LogicException If the count is below 1.
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
     * Pin this pattern's Victuals seed, overriding the context-level seed and
     * any per-pattern override configured on the context.
     *
     * @param int $seed
     * @return self
     */
    public function seed(int $seed): self
    {
        $this->seed = $seed;

        return $this;
    }

    /**
     * Declare related resources after each primary declaration is saved.
     *
     * The hook runs in both plan and apply and must return a persistable
     * declaration, an iterable of declarations, or null. Returned builders are
     * saved by the Pattern runner and therefore produce inspectable operations.
     * The callback itself must not perform writes.
     *
     * @param string $name Stable diagnostic name, unique within this Pattern.
     * @param callable(object, int): mixed $hook Receives the primary ref and one-based index.
     * @return self
     */
    public function after(string $name, callable $hook): self
    {
        $name = trim($name);
        if ($name === '') {
            throw new LogicException('Pattern after-hook name must not be empty.');
        }
        if (array_key_exists($name, $this->afterHooks)) {
            throw new LogicException(sprintf('Pattern [%s] after-hook [%s] is already registered.', $this->name, $name));
        }

        $this->afterHooks[$name] = $hook;

        return $this;
    }

    /**
     * Give every row a deterministic placeholder featured image.
     *
     * Registers the standard `thumbnail` after-hook so a "post with a thumbnail"
     * needs one call rather than a hand-written attachment block. The attachment
     * self-keys through the Pattern runner.
     *
     * @param int $width
     * @param int $height
     * @return self
     */
    public function withThumbnail(int $width = 1200, int $height = 800): self
    {
        return $this->after(
            'thumbnail',
            fn (object $ref, int $i): \PressGang\Muster\Builders\AttachmentBuilder =>
                $this->muster->attachment(sprintf('%s-thumbnail-%d', $this->name, $i))
                    ->placeholder($width, $height)
                    ->featuredOn($ref)
        );
    }

    /**
     * Run the pattern callable.
     *
     * This executes the closure exactly `count()` times, in ascending index order.
     * Seed resolution uses pattern seed first, then context seed override/global seed.
     *
     * Declaration signature:
     * `callable(int $i): \PressGang\Muster\Contracts\PersistableDeclaration`
     *
     * @param callable(int): \PressGang\Muster\Contracts\PersistableDeclaration $builder
     * @return PatternResult
     *
     * @throws LogicException If `count()` was not set before `build()`.
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
     * Run this Pattern from a reusable explicit Definition.
     *
     * @param Definition $definition
     * @return PatternResult
     */
    public function using(Definition $definition): PatternResult
    {
        return $this->build(fn (int $iteration) => $definition->make($iteration));
    }

    public function name(): string
    {
        return $this->name;
    }

    public function iterations(): int
    {
        return $this->count;
    }

    /**
     * Resolve the seed this run will use: the pattern's own seed first, then
     * the context's per-pattern override or global seed.
     *
     * @return int|null
     */
    public function effectiveSeed(): ?int
    {
        return $this->seed ?? $this->context->seedForPattern($this->name);
    }

    public function context(): MusterContext
    {
        return $this->context;
    }

    /**
     * @return array<string, callable(object, int): mixed>
     */
    public function afterHooks(): array
    {
        return $this->afterHooks;
    }
}

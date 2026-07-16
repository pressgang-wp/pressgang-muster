<?php

namespace PressGang\Muster\Patterns;

use PressGang\Muster\Builders\PostBuilder;
use PressGang\Muster\Contracts\PersistableDeclaration;
use PressGang\Muster\Muster;
use PressGang\Muster\Victuals\Victuals;
use ReflectionClass;

/**
 * A reusable recipe for one WordPress resource shape.
 *
 * Extend it in your theme's `muster/Recipes/` directory: implement {@see define()}
 * with the resource's default shape, and add named variations as methods that
 * return `$this->state(...)`. A Recipe uses Victuals to produce a resource
 * declaration — no Model and no ORM (see ADR 0007/0008).
 *
 * A Recipe is reusable across a seed and a test: seed a batch with
 * `->count(n)->create()`, or feed it to a Pattern with `Pattern::using($recipe)`.
 * Variants compose immutably, so `->featured()->count(3)->create()` is safe.
 */
abstract class Recipe
{
    /**
     * @var array<int, callable(PersistableDeclaration, int): PersistableDeclaration>
     */
    private array $states = [];

    private int $count = 1;

    private bool $thumbnail = false;

    /**
     * @param Muster $muster The seeding context whose builders the recipe uses.
     */
    public function __construct(protected Muster $muster)
    {
    }

    /**
     * The default shape for one row. Return a builder — usually
     * `$this->content($type)` with the row's own slug, terms, and overrides.
     *
     * @param int $iteration One-based row index.
     * @return PersistableDeclaration
     */
    abstract public function define(int $iteration): PersistableDeclaration;

    /**
     * The pattern and group name — auto-keys become `<name>:<i>`. Defaults to the
     * class short name without the `Recipe` suffix, lowercased (`HitRecipe` →
     * `hit`); override for a different name.
     *
     * @return string
     */
    protected function name(): string
    {
        $short = (new ReflectionClass($this))->getShortName();

        return strtolower((string) preg_replace('/Recipe$/', '', $short)) ?: strtolower($short);
    }

    /**
     * How many rows `create()` seeds.
     *
     * @param int $count
     * @return static
     */
    public function count(int $count): static
    {
        $variant = clone $this;
        $variant->count = max(1, $count);

        return $variant;
    }

    /**
     * Give every seeded row a deterministic placeholder featured image.
     *
     * @return static
     */
    public function withThumbnail(): static
    {
        $variant = clone $this;
        $variant->thumbnail = true;

        return $variant;
    }

    /**
     * Return a variant with one more transformation applied. States compose in
     * order; this is what a subclass's named-variation methods call.
     *
     * @param callable(PersistableDeclaration, int): PersistableDeclaration $transform
     * @return static
     */
    final protected function state(callable $transform): static
    {
        $variant = clone $this;
        $variant->states[] = $transform;

        return $variant;
    }

    /**
     * Build one declaration (the shape plus any active states) without saving.
     *
     * @param int $iteration
     * @return PersistableDeclaration
     */
    final public function make(int $iteration): PersistableDeclaration
    {
        $declaration = $this->define($iteration);

        foreach ($this->states as $state) {
            $declaration = $state($declaration, $iteration);
        }

        return $declaration;
    }

    /**
     * Seed `count()` rows through a self-keyed Pattern, in a group named for the
     * recipe (so `--only=<name>` selects it).
     *
     * @return void
     */
    public function create(): void
    {
        $this->muster->group($this->name(), function (): void {
            $pattern = $this->muster->pattern($this->name())->count($this->count);

            if ($this->thumbnail) {
                $pattern->withThumbnail();
            }

            $pattern->using($this);
        });
    }

    /**
     * A post pre-filled with generated content for $type (see {@see Muster::content()}).
     *
     * @param string $type
     * @return PostBuilder
     */
    final protected function content(string $type = 'post'): PostBuilder
    {
        return $this->muster->content($type);
    }

    /**
     * A bare post builder for $type.
     *
     * @param string $type
     * @return PostBuilder
     */
    final protected function post(string $type = 'post'): PostBuilder
    {
        return $this->muster->post($type);
    }

    /**
     * The active seeded value source.
     *
     * @return Victuals
     */
    final protected function victuals(): Victuals
    {
        return $this->muster->victuals();
    }

    /**
     * ACF values derived for a target (see {@see Muster::acfFor()}).
     *
     * @param string $target
     * @return array<string, mixed>
     */
    final protected function acfFor(string $target): array
    {
        return $this->muster->acfFor($target);
    }
}

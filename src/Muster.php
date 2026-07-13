<?php

namespace PressGang\Muster;

use BadMethodCallException;
use PressGang\Muster\Acf\AcfValueGenerator;
use PressGang\Muster\Acf\ContextProviders;
use PressGang\Muster\Acf\ThemeAcf;
use PressGang\Muster\Builders\AttachmentBuilder;
use PressGang\Muster\Builders\MenuBuilder;
use PressGang\Muster\Builders\OptionBuilder;
use PressGang\Muster\Builders\PostBuilder;
use PressGang\Muster\Builders\TermBuilder;
use PressGang\Muster\Builders\TruncateBuilder;
use PressGang\Muster\Builders\UserBuilder;
use PressGang\Muster\Patterns\Pattern;
use PressGang\Muster\Victuals\Victuals;

/**
 * Base orchestration class for deterministic WordPress content provisioning.
 *
 * Subclasses implement `run()` and compose builders, patterns, and Victuals calls.
 */
abstract class Muster
{
    private ?Victuals $patternVictuals = null;

    public function __construct(protected MusterContext $context)
    {
    }

    /**
     * Execute the seed orchestration.
     *
     * @return void
     */
    abstract public function run(): void;

    /**
     * Start a post builder for a given post type.
     *
     * The builder must receive `key()` before `save()`.
     *
     * @param string $postType
     * @return PostBuilder
     */
    public function post(string $postType = 'post'): PostBuilder
    {
        return new PostBuilder($this->context, $postType, ownershipScope: static::class);
    }

    /**
     * Start a page post builder, which requires `key()` before `save()`.
     *
     * @return PostBuilder
     */
    public function page(): PostBuilder
    {
        return $this->post('page');
    }

    /**
     * Start a taxonomy term builder, which requires `key()` before `save()`.
     *
     * @param string $taxonomy
     * @param string|null $name
     * @return TermBuilder
     */
    public function term(string $taxonomy, ?string $name = null): TermBuilder
    {
        return new TermBuilder($this->context, $taxonomy, $name, static::class);
    }

    /**
     * Start a user builder, which requires `key()` before `save()`.
     *
     * @param string|null $login
     * @return UserBuilder
     */
    public function user(?string $login = null): UserBuilder
    {
        return new UserBuilder($this->context, $login, static::class);
    }

    /**
     * Start an option builder, which requires `key()` before `save()`.
     *
     * @param string $key
     * @return OptionBuilder
     */
    public function option(string $key): OptionBuilder
    {
        return new OptionBuilder($this->context, $key, static::class);
    }

    /**
     * Start a nav-menu builder, which requires `key()` before `save()`.
     *
     * @param string $name
     * @return MenuBuilder
     */
    public function menu(string $name): MenuBuilder
    {
        return new MenuBuilder($this->context, $name, static::class);
    }

    /**
     * Start an attachment builder, which requires `key()` before `save()`.
     *
     * @param string $slug
     * @return AttachmentBuilder
     */
    public function attachment(string $slug): AttachmentBuilder
    {
        return new AttachmentBuilder($this->context, $slug, static::class);
    }

    /**
     * Start a truncate (clean-slate reset) builder.
     *
     * @return TruncateBuilder
     */
    public function truncate(): TruncateBuilder
    {
        return new TruncateBuilder($this->context);
    }

    /**
     * Permanently delete every resource owned by this concrete Muster.
     *
     * Unlike `truncate()`, this never selects resources merely because they
     * share a post type or taxonomy.
     *
     * @return int Number of owned resources selected for deletion.
     */
    public function resetOwned(): int
    {
        return $this->context->ownership()->reset(static::class);
    }

    /**
     * Delete owned resources not touched by the current successful run.
     *
     * Explicit keep keys may preserve conditional resources not declared in
     * this run. Use after a complete run, not a partial `--only` run. Deletion
     * is immediate until the future plan/apply lifecycle makes the diff visible.
     *
     * @param array<int, string> $keepKeys Additional logical keys to retain.
     * @return int Number of stale resources selected for deletion.
     */
    public function pruneOwned(array $keepKeys = []): int
    {
        return $this->context->ownership()->prune(static::class, $keepKeys);
    }

    /**
     * Generated ACF values for every field group targeting $target, derived
     * from the active theme's acf-json — media and relational fields are
     * backed by real fixture objects (placeholder attachments, stub posts
     * and terms) created through this Muster's context.
     *
     * Feed the result straight to a builder:
     *
     *     $this->post('event')->key('event:example')->title(…)->acf($this->acfFor('event'))->save();
     *
     * Values draw from the seeded Victuals stream, so successive calls vary
     * naturally while the run as a whole stays deterministic.
     *
     * @param string $target A post type slug or page template path.
     * @param string $variant `populated` (every field) or `minimal` (required only).
     * @return array<string, mixed>
     */
    public function acfFor(string $target, string $variant = 'populated'): array
    {
        $generator = new AcfValueGenerator(
            $this->victuals(),
            ContextProviders::wire($this->context, static::class)
        );

        return ThemeAcf::valuesFor($target, $generator, $variant);
    }

    /**
     * Access the Victuals helper for deterministic generated values.
     *
     * During a pattern run this returns the per-pattern scoped instance.
     * Outside a pattern run, it returns the context-level seeded instance.
     *
     * @return Victuals
     */
    public function victuals(): Victuals
    {
        if ($this->patternVictuals !== null) {
            return $this->patternVictuals;
        }

        return $this->context->victuals();
    }

    /**
     * Start a named pattern run.
     *
     * @param string $name
     * @return Pattern
     */
    public function pattern(string $name): Pattern
    {
        return new Pattern($name, $this->context, $this);
    }

    /**
     * Scope Victuals for a single pattern execution.
     *
     * Internal lifecycle hook used by `PatternRunner` to ensure all Victuals calls in one
     * pattern run share the same seeded generator instance.
     *
     * @param Victuals $victuals
     * @return void
     */
    public function beginPatternVictualsScope(Victuals $victuals): void
    {
        $this->patternVictuals = $victuals;
    }

    /**
     * End the active pattern Victuals scope.
     *
     * Internal lifecycle hook paired with `beginPatternVictualsScope()`.
     *
     * @return void
     */
    public function endPatternVictualsScope(): void
    {
        $this->patternVictuals = null;
    }

    /**
     * Resolve post-type shorthand calls to `post($postType)`.
     *
     * Method resolution is guarded by `post_type_exists()`.
     * If the first argument is provided, it is applied as the builder title.
     *
     * See: https://developer.wordpress.org/reference/functions/post_type_exists/
     *
     * @param string $method
     * @param array<int, mixed> $args
     * @return mixed
     */
    public function __call(string $method, array $args): mixed
    {
        if (function_exists('post_type_exists') && post_type_exists($method)) {
            /** @var string|null $title */
            $title = $args[0] ?? null;

            $builder = $this->post($method);

            if ($title !== null) {
                $builder->title($title);
            }

            return $builder;
        }

        throw new BadMethodCallException(
            sprintf('%s method [%s] does not exist.', static::class, $method)
        );
    }
}

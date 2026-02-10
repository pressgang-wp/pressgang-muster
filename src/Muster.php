<?php

namespace PressGang\Muster;

use BadMethodCallException;
use PressGang\Muster\Builders\OptionBuilder;
use PressGang\Muster\Builders\PostBuilder;
use PressGang\Muster\Builders\TermBuilder;
use PressGang\Muster\Builders\UserBuilder;
use PressGang\Muster\Patterns\Pattern;
use PressGang\Muster\Victuals\Victuals;

/**
 * Base orchestration class for deterministic content setup runs.
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
     * @param string $postType
     * @return PostBuilder
     */
    public function post(string $postType = 'post'): PostBuilder
    {
        return new PostBuilder($this->context, $postType);
    }

    /**
     * Start a page post builder.
     *
     * @return PostBuilder
     */
    public function page(): PostBuilder
    {
        return $this->post('page');
    }

    /**
     * Start a taxonomy term builder.
     *
     * @param string $taxonomy
     * @param string|null $name
     * @return TermBuilder
     */
    public function term(string $taxonomy, ?string $name = null): TermBuilder
    {
        return new TermBuilder($this->context, $taxonomy, $name);
    }

    /**
     * Start a user builder.
     *
     * @param string|null $login
     * @return UserBuilder
     */
    public function user(?string $login = null): UserBuilder
    {
        return new UserBuilder($this->context, $login);
    }

    /**
     * Start an option builder.
     *
     * @param string $key
     * @return OptionBuilder
     */
    public function option(string $key): OptionBuilder
    {
        return new OptionBuilder($this->context, $key);
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

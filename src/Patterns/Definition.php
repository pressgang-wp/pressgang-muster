<?php

namespace PressGang\Muster\Patterns;

use LogicException;
use PressGang\Muster\Contracts\PersistableDeclaration;

/**
 * Reusable explicit factory definition for one WordPress resource shape.
 *
 * A Definition stores a builder factory and optional named transformations.
 * It never persists data itself and does not describe model attributes; the
 * returned declaration remains the sole persistence boundary.
 */
final class Definition
{
    use AssertsDeclarations;

    /**
     * @var array<string, callable(PersistableDeclaration, int): PersistableDeclaration>
     */
    private array $states = [];

    /**
     * @var array<int, string>
     */
    private array $activeStates = [];

    /**
     * @param string $name Human-readable definition name used in diagnostics.
     * @param callable(int): PersistableDeclaration $factory
     */
    public function __construct(private string $name, private $factory)
    {
        $this->name = trim($name);
        if ($this->name === '') {
            throw new LogicException('Definition name must not be empty.');
        }
    }

    /**
     * Register a named, reusable declaration transformation.
     *
     * The callable receives the declaration and one-based iteration index and
     * must return a persistable declaration. Registration performs no writes.
     *
     * @param string $name
     * @param callable(PersistableDeclaration, int): PersistableDeclaration $transform
     * @return self
     */
    public function state(string $name, callable $transform): self
    {
        $name = trim($name);
        if ($name === '') {
            throw new LogicException('Definition state name must not be empty.');
        }
        if (array_key_exists($name, $this->states)) {
            throw new LogicException(sprintf('Definition [%s] state [%s] is already registered.', $this->name, $name));
        }

        $this->states[$name] = $transform;

        return $this;
    }

    /**
     * Return an isolated variant with the named states applied in order.
     *
     * Each call replaces the variant's state list rather than accumulating:
     * chaining `with('a')->with('b')` applies only `b`. Combine states in a
     * single call — `with('a', 'b')` — to apply both.
     *
     * @param string ...$names
     * @return self
     */
    public function with(string ...$names): self
    {
        $variant = clone $this;
        $variant->activeStates = [];

        foreach ($names as $name) {
            if (!array_key_exists($name, $this->states)) {
                throw new LogicException(sprintf('Definition [%s] has no state [%s].', $this->name, $name));
            }

            $variant->activeStates[] = $name;
        }

        return $variant;
    }

    /**
     * Build one declaration without persisting it.
     *
     * @param int $iteration One-based pattern iteration index.
     * @return PersistableDeclaration
     */
    public function make(int $iteration): PersistableDeclaration
    {
        $declaration = $this->assertDeclaration(
            ($this->factory)($iteration),
            sprintf('Definition [%s] factory', $this->name)
        );

        foreach ($this->activeStates as $state) {
            $declaration = $this->assertDeclaration(
                ($this->states[$state])($declaration, $iteration),
                sprintf('Definition [%s] state [%s]', $this->name, $state)
            );
        }

        return $declaration;
    }

    public function name(): string
    {
        return $this->name;
    }
}

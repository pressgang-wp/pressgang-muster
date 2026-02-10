<?php

namespace PressGang\Muster\Builders;

use PressGang\Muster\MusterContext;
use PressGang\Muster\Refs\TermRef;

/**
 * Fluent term builder with idempotent-upsert intent.
 */
final class TermBuilder
{
    /**
     * @var array<string, mixed>
     */
    private array $payload = [];

    /**
     * @param MusterContext $context
     * @param string $taxonomy
     * @param string|null $name
     */
    public function __construct(
        private MusterContext $context,
        private string $taxonomy,
        ?string $name = null,
    ) {
        if ($name !== null) {
            $this->payload['name'] = $name;
        }
    }

    /**
     * @param string $name
     * @return self
     */
    public function name(string $name): self
    {
        $this->payload['name'] = $name;

        return $this;
    }

    /**
     * @param string $slug
     * @return self
     */
    public function slug(string $slug): self
    {
        $this->payload['slug'] = $slug;

        return $this;
    }

    /**
     * @param string $description
     * @return self
     */
    public function description(string $description): self
    {
        $this->payload['description'] = $description;

        return $this;
    }

    /**
     * @param string|int|TermRef $parent
     * @return self
     */
    public function parent(string|int|TermRef $parent): self
    {
        $this->payload['parent'] = $parent;

        return $this;
    }

    /**
     * @param array<string, mixed> $meta
     * @return self
     */
    public function meta(array $meta): self
    {
        $this->payload['meta'] = $meta;

        return $this;
    }

    /**
     * @param array<string, mixed> $fields
     * @return self
     */
    public function acf(array $fields): self
    {
        $this->payload['acf'] = $fields;

        return $this;
    }

    /**
     * @return TermRef
     */
    public function save(): TermRef
    {
        return new TermRef(0, $this->taxonomy, (string) ($this->payload['slug'] ?? ''));
    }
}

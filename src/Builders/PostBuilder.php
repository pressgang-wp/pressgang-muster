<?php

namespace PressGang\Muster\Builders;

use PressGang\Muster\MusterContext;
use PressGang\Muster\Refs\PostRef;

/**
 * Fluent post builder with idempotent-upsert intent.
 */
final class PostBuilder
{
    /**
     * @var array<string, mixed>
     */
    private array $payload = [];

    /**
     * @var array<string, array<int, string|int>>
     */
    private array $taxonomyTerms = [];

    /**
     * @param MusterContext $context
     * @param string $postType
     * @param string|null $title
     */
    public function __construct(
        private MusterContext $context,
        private string $postType = 'post',
        ?string $title = null,
    ) {
        $this->payload['post_type'] = $postType;

        if ($title !== null) {
            $this->payload['post_title'] = $title;
        }
    }

    /**
     * @param string $title
     * @return self
     */
    public function title(string $title): self
    {
        $this->payload['post_title'] = $title;

        return $this;
    }

    /**
     * @param string $slug
     * @return self
     */
    public function slug(string $slug): self
    {
        $this->payload['post_name'] = $slug;

        return $this;
    }

    /**
     * @param string $status
     * @return self
     */
    public function status(string $status): self
    {
        $this->payload['post_status'] = $status;

        return $this;
    }

    /**
     * @param string $content
     * @return self
     */
    public function content(string $content): self
    {
        $this->payload['post_content'] = $content;

        return $this;
    }

    /**
     * @param string $excerpt
     * @return self
     */
    public function excerpt(string $excerpt): self
    {
        $this->payload['post_excerpt'] = $excerpt;

        return $this;
    }

    /**
     * @param string|int $user
     * @return self
     */
    public function author(string|int $user): self
    {
        $this->payload['post_author'] = $user;

        return $this;
    }

    /**
     * @param string $template
     * @return self
     */
    public function template(string $template): self
    {
        $this->payload['page_template'] = $template;

        return $this;
    }

    /**
     * @param string|int|PostRef $parent
     * @return self
     */
    public function parent(string|int|PostRef $parent): self
    {
        $this->payload['post_parent'] = $parent;

        return $this;
    }

    /**
     * @param string $taxonomy
     * @param array<int, string|int> $terms
     * @return self
     */
    public function terms(string $taxonomy, array $terms): self
    {
        $this->taxonomyTerms[$taxonomy] = array_values($terms);

        return $this;
    }

    /**
     * @param array<string, mixed> $meta
     * @return self
     */
    public function meta(array $meta): self
    {
        $this->payload['meta_input'] = $meta;

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
     * @return PostRef
     */
    public function save(): PostRef
    {
        return new PostRef(0, $this->postType, (string) ($this->payload['post_name'] ?? ''));
    }
}

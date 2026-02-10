<?php

namespace PressGang\Muster\Builders;

use LogicException;
use RuntimeException;
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
    private array $taxInput = [];

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
        $this->taxInput[$taxonomy] = array_values($terms);

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
     *
     * Identity rule: `post_type + post_name` (slug).
     * Lookup is performed with `get_posts()` using `name`, `post_type`, and `post_status=any`.
     * Existing records are updated via `wp_update_post()`; missing records are inserted via
     * `wp_insert_post()`. Meta payload is applied with `update_post_meta()`.
     *
     * See: https://developer.wordpress.org/reference/functions/get_posts/
     * See: https://developer.wordpress.org/reference/functions/wp_update_post/
     * See: https://developer.wordpress.org/reference/functions/wp_insert_post/
     * See: https://developer.wordpress.org/reference/functions/update_post_meta/
     *
     * @throws LogicException If neither slug nor title is set.
     * @throws RuntimeException If WordPress runtime functions are unavailable or save fails.
     */
    public function save(): PostRef
    {
        $slug = $this->resolveSlug();
        $status = (string) ($this->payload['post_status'] ?? 'draft');

        if ($this->context->dryRun()) {
            $this->context->logger()->info(
                sprintf('Dry run post upsert [%s:%s].', $this->postType, $slug)
            );

            return new PostRef(0, $this->postType, $slug);
        }

        if (!function_exists('get_posts') || !function_exists('wp_insert_post') || !function_exists('wp_update_post')) {
            throw new RuntimeException('WordPress runtime functions are required to save posts.');
        }

        $existing = get_posts([
            'name' => $slug,
            'post_type' => $this->postType,
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'suppress_filters' => true,
            'no_found_rows' => true,
        ]);

        $attributes = [
            'post_type' => $this->postType,
            'post_name' => $slug,
            'post_title' => (string) ($this->payload['post_title'] ?? ''),
            'post_content' => (string) ($this->payload['post_content'] ?? ''),
            'post_excerpt' => (string) ($this->payload['post_excerpt'] ?? ''),
            'post_status' => $status,
            'post_parent' => $this->resolveParentId($this->payload['post_parent'] ?? null),
        ];

        $author = $this->resolveAuthorId($this->payload['post_author'] ?? null);
        if ($author !== null) {
            $attributes['post_author'] = $author;
        }

        /** @var int|\WP_Error $saveResult */
        $saveResult = 0;
        $action = 'created';

        if (!empty($existing)) {
            $attributes['ID'] = (int) $existing[0];
            $saveResult = wp_update_post($attributes, true);
            $action = 'updated';
        } else {
            $saveResult = wp_insert_post($attributes, true);
        }

        if ((function_exists('is_wp_error') && is_wp_error($saveResult)) || !is_int($saveResult) || $saveResult <= 0) {
            throw new RuntimeException('Failed to save post.');
        }

        $postId = $saveResult;

        $meta = $this->payload['meta_input'] ?? [];
        if (function_exists('update_post_meta') && is_array($meta)) {
            foreach ($meta as $key => $value) {
                update_post_meta($postId, (string) $key, $value);
            }
        }

        if (isset($this->payload['page_template']) && function_exists('update_post_meta')) {
            update_post_meta($postId, '_wp_page_template', (string) $this->payload['page_template']);
        }

        if ($this->taxInput !== [] && function_exists('wp_set_object_terms')) {
            foreach ($this->taxInput as $taxonomy => $terms) {
                wp_set_object_terms($postId, $terms, $taxonomy, false);
            }
        }

        $acf = $this->payload['acf'] ?? [];
        if (is_array($acf) && $acf !== []) {
            $this->context->acf()->updateFields($acf, 'post', $postId);
        }

        $this->context->logger()->debug(
            sprintf('Post %s [%s:%s] as ID %d.', $action, $this->postType, $slug, $postId)
        );

        return new PostRef($postId, $this->postType, $slug);
    }

    /**
     * @return string
     *
     * Resolves slug from explicit `slug()` first, then derived `sanitize_title(title)`.
     *
     * See: https://developer.wordpress.org/reference/functions/sanitize_title/
     *
     * @throws LogicException If neither slug nor title is set.
     */
    private function resolveSlug(): string
    {
        $slug = (string) ($this->payload['post_name'] ?? '');

        if ($slug !== '') {
            return $slug;
        }

        $title = (string) ($this->payload['post_title'] ?? '');
        if ($title !== '') {
            if (function_exists('sanitize_title')) {
                return (string) sanitize_title($title);
            }

            return strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $title), '-'));
        }

        throw new LogicException('Post slug is required when title is not set.');
    }

    /**
     * @param mixed $author
     * @return int|null
     */
    private function resolveAuthorId(mixed $author): ?int
    {
        if (is_int($author)) {
            return $author;
        }

        if (is_string($author) && $author !== '' && function_exists('get_user_by')) {
            $user = get_user_by('login', $author);

            if ($user !== false && isset($user->ID)) {
                return (int) $user->ID;
            }
        }

        return null;
    }

    /**
     * @param mixed $parent
     * @return int
     */
    private function resolveParentId(mixed $parent): int
    {
        if ($parent instanceof PostRef) {
            return $parent->id();
        }

        if (is_int($parent)) {
            return $parent;
        }

        if (is_string($parent) && $parent !== '' && function_exists('get_posts')) {
            $match = get_posts([
                'name' => $parent,
                'post_type' => $this->postType,
                'post_status' => 'any',
                'posts_per_page' => 1,
                'fields' => 'ids',
                'suppress_filters' => true,
                'no_found_rows' => true,
            ]);

            if (!empty($match)) {
                return (int) $match[0];
            }
        }

        return 0;
    }
}

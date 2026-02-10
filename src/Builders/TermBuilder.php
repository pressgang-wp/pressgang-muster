<?php

namespace PressGang\Muster\Builders;

use LogicException;
use RuntimeException;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Refs\TermRef;

/**
 * Fluent term builder with idempotent-upsert intent.
 *
 * This builder targets taxonomy terms and persists with deterministic identity:
 * `taxonomy + slug`.
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
     * Set term display name.
     *
     * This mutates builder state only and does not write to WordPress.
     *
     * @param string $name
     * @return self
     */
    public function name(string $name): self
    {
        $this->payload['name'] = $name;

        return $this;
    }

    /**
     * Set explicit term slug.
     *
     * This mutates builder state only and does not write to WordPress.
     *
     * @param string $slug
     * @return self
     */
    public function slug(string $slug): self
    {
        $this->payload['slug'] = $slug;

        return $this;
    }

    /**
     * Set term description.
     *
     * This mutates builder state only and does not write to WordPress.
     *
     * @param string $description
     * @return self
     */
    public function description(string $description): self
    {
        $this->payload['description'] = $description;

        return $this;
    }

    /**
     * Set parent term reference.
     *
     * This mutates builder state only and does not write to WordPress.
     *
     * @param string|int|TermRef $parent
     * @return self
     */
    public function parent(string|int|TermRef $parent): self
    {
        $this->payload['parent'] = $parent;

        return $this;
    }

    /**
     * Set term meta payload to be applied during `save()`.
     *
     * This mutates builder state only and does not write to WordPress.
     *
     * @param array<string, mixed> $meta
     * @return self
     */
    public function meta(array $meta): self
    {
        $this->payload['meta'] = $meta;

        return $this;
    }

    /**
     * Set ACF field payload to be applied during `save()`.
     *
     * This mutates builder state only and does not write to WordPress.
     *
     * @param array<string, mixed> $fields
     * @return self
     */
    public function acf(array $fields): self
    {
        $this->payload['acf'] = $fields;

        return $this;
    }

    /**
     * Persist the term to WordPress via idempotent upsert.
     *
     * Identity rule: `taxonomy + slug`.
     * Existing terms are updated using `wp_update_term()`, missing terms are inserted
     * via `wp_insert_term()`. Term meta is applied with `update_term_meta()`.
     *
     * See: https://developer.wordpress.org/reference/functions/get_term_by/
     * See: https://developer.wordpress.org/reference/functions/wp_update_term/
     * See: https://developer.wordpress.org/reference/functions/wp_insert_term/
     * See: https://developer.wordpress.org/reference/functions/update_term_meta/
     *
     * @return TermRef
     * @throws LogicException If neither slug nor name is set.
     * @throws RuntimeException If WordPress runtime functions are unavailable or save fails.
     */
    public function save(): TermRef
    {
        $slug = $this->resolveSlug();
        $name = (string) ($this->payload['name'] ?? $slug);

        if ($this->context->dryRun()) {
            $this->context->logger()->info(
                sprintf('Dry run term upsert [%s:%s].', $this->taxonomy, $slug)
            );

            return new TermRef(0, $this->taxonomy, $slug);
        }

        if (!function_exists('get_term_by') || !function_exists('wp_insert_term') || !function_exists('wp_update_term')) {
            throw new RuntimeException('WordPress term runtime functions are required to save terms.');
        }

        $existing = get_term_by('slug', $slug, $this->taxonomy);

        $attributes = [
            'slug' => $slug,
            'description' => (string) ($this->payload['description'] ?? ''),
            'parent' => $this->resolveParentId($this->payload['parent'] ?? null),
        ];

        /** @var array<string, mixed>|\WP_Error $result */
        $result = [];
        $action = 'created';

        if ($existing !== false && isset($existing->term_id)) {
            $result = wp_update_term((int) $existing->term_id, $this->taxonomy, $attributes);
            $action = 'updated';
        } else {
            $result = wp_insert_term($name, $this->taxonomy, $attributes);
        }

        if ((function_exists('is_wp_error') && is_wp_error($result)) || !is_array($result)) {
            throw new RuntimeException('Failed to save term.');
        }

        $termId = isset($result['term_id']) ? (int) $result['term_id'] : 0;
        if ($termId <= 0) {
            throw new RuntimeException('Failed to resolve saved term ID.');
        }

        $meta = $this->payload['meta'] ?? [];
        if (is_array($meta) && function_exists('update_term_meta')) {
            foreach ($meta as $key => $value) {
                update_term_meta($termId, (string) $key, $value);
            }
        }

        $acf = $this->payload['acf'] ?? [];
        if (is_array($acf) && $acf !== []) {
            $this->context->acf()->updateFields($acf, 'term', $termId);
        }

        $this->context->logger()->debug(
            sprintf('Term %s [%s:%s] as ID %d.', $action, $this->taxonomy, $slug, $termId)
        );

        return new TermRef($termId, $this->taxonomy, $slug);
    }

    /**
     * Resolve effective slug for term identity.
     *
     * Prefers explicit `slug()` and falls back to sanitized `name()`.
     *
     * See: https://developer.wordpress.org/reference/functions/sanitize_title/
     *
     * @return string
     * @throws LogicException If neither slug nor name is set.
     */
    private function resolveSlug(): string
    {
        $slug = (string) ($this->payload['slug'] ?? '');
        if ($slug !== '') {
            return $slug;
        }

        $name = (string) ($this->payload['name'] ?? '');
        if ($name === '') {
            throw new LogicException('Term slug is required when name is not set.');
        }

        if (function_exists('sanitize_title')) {
            return (string) sanitize_title($name);
        }

        return strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
    }

    /**
     * Resolve parent term ID from supported parent inputs.
     *
     * String parents are treated as slugs and resolved in the current taxonomy.
     *
     * See: https://developer.wordpress.org/reference/functions/get_term_by/
     *
     * @param mixed $parent
     * @return int
     */
    private function resolveParentId(mixed $parent): int
    {
        if ($parent instanceof TermRef) {
            return $parent->termId();
        }

        if (is_int($parent)) {
            return $parent;
        }

        if (is_string($parent) && $parent !== '' && function_exists('get_term_by')) {
            $term = get_term_by('slug', $parent, $this->taxonomy);
            if ($term !== false && isset($term->term_id)) {
                return (int) $term->term_id;
            }
        }

        return 0;
    }
}

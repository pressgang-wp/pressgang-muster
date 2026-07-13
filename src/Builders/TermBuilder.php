<?php

namespace PressGang\Muster\Builders;

use PressGang\Muster\Contracts\PersistableDeclaration;
use LogicException;
use RuntimeException;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Ownership\HasOwnership;
use PressGang\Muster\Ownership\OwnedResource;
use PressGang\Muster\Refs\TermRef;
use PressGang\Muster\Refs\LazyRef;
use PressGang\Muster\Results\OperationAction;

/**
 * Fluent term builder with idempotent merge-upsert behaviour.
 *
 * Muster-scoped builders use an explicit logical key; `taxonomy + slug` is the
 * WordPress locator and may change for an already owned term. Existing terms
 * retain values for fields not set on this builder; an empty value clears it.
 */
final class TermBuilder implements PersistableDeclaration
{
    use HasOwnership;

    /**
     * @var array<string, mixed>
     */
    private array $payload = [];

    /**
     * @param MusterContext $context
     * @param string $taxonomy
     * @param string|null $name
     * @param string|null $ownershipScope
     */
    public function __construct(
        private MusterContext $context,
        private string $taxonomy,
        ?string $name = null,
        ?string $ownershipScope = null,
    ) {
        $this->initializeOwnership($ownershipScope);
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
     * @param string|int|TermRef|LazyRef $parent
     * @return self
     */
    public function parent(string|int|TermRef|LazyRef $parent): self
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
     * Managed identity is `Muster class + logical key`; the WordPress locator is
     * `taxonomy + slug`. Unowned locator matches require `adopt()`.
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
        $intent = $this->ownershipIntent();

        if (!function_exists('get_term_by')) {
            throw new RuntimeException('get_term_by() is required to plan or save terms.');
        }

        $natural = get_term_by('slug', $slug, $this->taxonomy);
        if ($natural !== false && isset($natural->term_id)
            && $this->context->isPlannedDeleted('term', (int) $natural->term_id, $this->taxonomy, $slug)) {
            $natural = false;
        }

        $existing = $natural;
        $owned = null;

        if ($intent !== null) {
            $owned = $this->currentOwnership($this->context, $intent, 'term', $this->taxonomy);

            $ownedTerm = $owned === null ? null : $this->resolveOwnedTerm($owned);
            if ($ownedTerm !== null && isset($ownedTerm->term_id)
                && $this->context->isPlannedDeleted(
                    'term',
                    (int) $ownedTerm->term_id,
                    $this->taxonomy,
                    $owned->locator()
                )) {
                $ownedTerm = null;
            }
            if ($ownedTerm !== null && $natural !== false
                && isset($natural->term_id)
                && (int) $ownedTerm->term_id !== (int) $natural->term_id) {
                $this->throwOwnershipConflict($this->context, $intent, 'term', (int) $natural->term_id, $slug, sprintf(
                    'Cannot move owned term [%s:%s] to slug [%s]; that slug belongs to term ID %d.',
                    $intent['scope'],
                    $intent['key'],
                    $slug,
                    (int) $natural->term_id
                ));
            }

            $existing = $ownedTerm ?? $natural;
            if ($existing !== false && $existing !== null && isset($existing->term_id)) {
                $this->claimExistingOwnership(
                    $this->context,
                    $intent,
                    'term',
                    (int) $existing->term_id,
                    $this->taxonomy,
                    $slug
                );
            }
        }

        $attributes = [
            'slug' => $slug,
        ];

        if (array_key_exists('description', $this->payload)) {
            $attributes['description'] = (string) $this->payload['description'];
        }

        if (array_key_exists('parent', $this->payload)) {
            $attributes['parent'] = $this->resolveParentId($this->payload['parent']);
        }

        if ($existing !== false && $existing !== null && array_key_exists('name', $this->payload)) {
            $attributes['name'] = (string) $this->payload['name'];
        }

        $this->context->debugDeclaration('Term', [
            ...array_keys($attributes),
            ...array_map(static fn (string $key): string => 'meta.' . $key, array_keys((array) ($this->payload['meta'] ?? []))),
            ...array_map(static fn (string $key): string => 'acf.' . $key, array_keys((array) ($this->payload['acf'] ?? []))),
        ]);

        $existingId = $existing !== false && $existing !== null && isset($existing->term_id)
            ? (int) $existing->term_id
            : null;
        $operation = $this->termOperation($existing, $attributes, $owned, $slug);
        $plannedId = $existingId ?? 0;

        if ($this->context->dryRun()) {
            if ($intent !== null) {
                $this->reportOwnership($this->context, $intent, $operation, 'term', $plannedId, $slug);
                $this->recordOwnership($this->context, $intent, 'term', $plannedId, $this->taxonomy, $slug);
            }

            return new TermRef($plannedId, $this->taxonomy, $slug);
        }

        if ($operation === OperationAction::Keep && $existingId !== null) {
            if ($intent !== null) {
                $this->recordOwnership($this->context, $intent, 'term', $existingId, $this->taxonomy, $slug);
                $this->reportOwnership($this->context, $intent, $operation, 'term', $existingId, $slug);
            }

            return new TermRef($existingId, $this->taxonomy, $slug);
        }

        if (!function_exists('wp_insert_term') || !function_exists('wp_update_term')) {
            throw new RuntimeException('WordPress write functions are required to save terms.');
        }

        /** @var array<string, mixed>|\WP_Error $result */
        $result = [];

        if ($existing !== false && isset($existing->term_id)) {
            $result = wp_update_term((int) $existing->term_id, $this->taxonomy, $attributes);
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

        if ($intent !== null) {
            $this->recordOwnership($this->context, $intent, 'term', $termId, $this->taxonomy, $slug);
            $this->reportOwnership($this->context, $intent, $operation, 'term', $termId, $slug);
        }

        $this->context->logger()->debug(
            sprintf('Term %s [%s:%s] as ID %d.', $operation->value, $this->taxonomy, $slug, $termId)
        );

        return new TermRef($termId, $this->taxonomy, $slug);
    }

    /**
     * @param object|false|null $existing
     * @param array<string, mixed> $attributes
     * @param OwnedResource|null $owned
     * @param string $slug
     * @return OperationAction
     */
    private function termOperation(
        object|false|null $existing,
        array $attributes,
        ?OwnedResource $owned,
        string $slug,
    ): OperationAction {
        if ($existing === false || $existing === null) {
            if ($owned !== null && $this->context->ownership()->isPlannedClaim($owned->scope(), $owned->key())) {
                return OperationAction::Keep;
            }

            return OperationAction::Create;
        }

        if ($owned === null || $owned->locator() !== $slug
            || !empty($this->payload['meta'])
            || !empty($this->payload['acf'])) {
            return OperationAction::Update;
        }

        foreach ($attributes as $field => $value) {
            if (!property_exists($existing, $field) || (string) $existing->{$field} !== (string) $value) {
                return OperationAction::Update;
            }
        }

        return OperationAction::Keep;
    }

    private function resolveOwnedTerm(OwnedResource $owned): ?object
    {
        if (!function_exists('get_term')) {
            throw new RuntimeException('get_term() is required to resolve owned terms.');
        }

        $term = get_term($owned->id(), $this->taxonomy);

        return is_object($term) && isset($term->term_id) ? $term : null;
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
        if ($parent instanceof LazyRef) {
            return $parent->resolve('term', $this->taxonomy)->id();
        }

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

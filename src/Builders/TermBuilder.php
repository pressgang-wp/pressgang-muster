<?php

namespace PressGang\Muster\Builders;

use PressGang\Muster\Contracts\PersistableDeclaration;
use LogicException;
use RuntimeException;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Ownership\HasOwnership;
use PressGang\Muster\Ownership\OwnedResource;
use PressGang\Muster\Ownership\ResolvesIdentity;
use PressGang\Muster\Refs\TermRef;
use PressGang\Muster\Refs\LazyRef;
use PressGang\Muster\Results\OperationAction;
use PressGang\Muster\Support\Slug;
use PressGang\Muster\Support\WpMeta;

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
    use ResolvesIdentity;
    use GuardsAcfMeta;

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
     * via `wp_insert_term()`. Term meta is applied with `update_term_meta()`; ACF
     * payload with `update_field()` via the context adapter.
     *
     * A `meta()` key that the theme's acf-json registers as an ACF field for this
     * taxonomy is rejected before any write (plan and apply alike): it must go
     * through `acf()` so `update_field()` stores the field-key reference
     * `get_field()` needs — a raw meta write to that key reads back empty.
     *
     * See: https://developer.wordpress.org/reference/functions/get_term_by/
     * See: https://developer.wordpress.org/reference/functions/wp_update_term/
     * See: https://developer.wordpress.org/reference/functions/wp_insert_term/
     * See: https://developer.wordpress.org/reference/functions/update_term_meta/
     *
     * @return TermRef
     * @throws LogicException If neither slug nor name is set, or a `meta()` key
     *     names an ACF field for this taxonomy.
     * @throws RuntimeException If WordPress runtime functions are unavailable or save fails.
     */
    public function save(): TermRef
    {
        $slug = $this->resolveSlug();
        $this->assertMetaKeysNotAcfFields(
            $this->context,
            (array) ($this->payload['meta'] ?? []),
            'term',
            $this->taxonomy,
            $this->taxonomy . ':' . $slug,
        );
        $name = (string) ($this->payload['name'] ?? $slug);
        $intent = $this->ownershipIntent();

        if (!function_exists('get_term_by')) {
            throw new RuntimeException('get_term_by() is required to plan or save terms.');
        }

        ['existing' => $existing, 'owned' => $owned] = $this->resolveIdentity(
            $this->context,
            $intent,
            'term',
            $this->taxonomy,
            $slug,
            findNatural: function () use ($slug): ?object {
                $term = get_term_by('slug', $slug, $this->taxonomy);

                return is_object($term) && isset($term->term_id) ? $term : null;
            },
            resolveOwned: fn (OwnedResource $owned): ?object => $this->resolveOwnedTerm($owned),
            idOf: static fn (object $term): int => (int) $term->term_id,
            conflictMessage: fn (int $naturalId): string => sprintf(
                'Cannot move owned term [%s:%s] to slug [%s]; that slug belongs to term ID %d.',
                $intent['scope'],
                $intent['key'],
                $slug,
                $naturalId
            ),
        );

        $attributes = $this->buildAttributes($slug, $existing !== null);

        $this->context->debugDeclaration('Term', [
            ...array_keys($attributes),
            ...array_map(static fn (string $key): string => 'meta.' . $key, array_keys((array) ($this->payload['meta'] ?? []))),
            ...array_map(static fn (string $key): string => 'acf.' . $key, array_keys((array) ($this->payload['acf'] ?? []))),
        ]);

        $existingId = $existing === null ? null : (int) $existing->term_id;
        $operation = $this->termOperation($existing, $attributes, $owned, $slug);

        if ($this->context->dryRun()) {
            $plannedId = $existingId ?? 0;
            $this->finalizeUpsert($this->context, $intent, $operation, 'term', $plannedId, $this->taxonomy, $slug);

            return new TermRef($plannedId, $this->taxonomy, $slug);
        }

        if ($operation === OperationAction::Keep && $existingId !== null) {
            $this->finalizeUpsert($this->context, $intent, $operation, 'term', $existingId, $this->taxonomy, $slug);

            return new TermRef($existingId, $this->taxonomy, $slug);
        }

        $termId = $this->writeTerm($existingId, $name, $attributes);
        $this->applySideEffects($termId);
        $this->finalizeUpsert($this->context, $intent, $operation, 'term', $termId, $this->taxonomy, $slug);

        $this->context->logger()->debug(
            sprintf('Term %s [%s:%s] as ID %d.', $operation->value, $this->taxonomy, $slug, $termId)
        );

        return new TermRef($termId, $this->taxonomy, $slug);
    }

    /**
     * Assemble the WordPress write attributes from declared builder state.
     *
     * The display name is only written on updates; inserts pass it to
     * `wp_insert_term()` directly.
     *
     * @param string $slug
     * @param bool $exists Whether an existing term is being updated.
     * @return array<string, mixed>
     */
    private function buildAttributes(string $slug, bool $exists): array
    {
        $attributes = [
            'slug' => $slug,
        ];

        if (array_key_exists('description', $this->payload)) {
            $attributes['description'] = (string) $this->payload['description'];
        }

        if (array_key_exists('parent', $this->payload)) {
            $attributes['parent'] = $this->resolveParentId($this->payload['parent']);
        }

        if ($exists && array_key_exists('name', $this->payload)) {
            $attributes['name'] = (string) $this->payload['name'];
        }

        return $attributes;
    }

    /**
     * Insert or update the core term record and return its ID.
     *
     * @param int|null $existingId
     * @param string $name Display name used when inserting.
     * @param array<string, mixed> $attributes
     * @return int
     * @throws RuntimeException If write functions are unavailable or the save fails.
     */
    private function writeTerm(?int $existingId, string $name, array $attributes): int
    {
        if (!function_exists('wp_insert_term') || !function_exists('wp_update_term')) {
            throw new RuntimeException('WordPress write functions are required to save terms.');
        }

        /** @var array<string, mixed>|\WP_Error $result */
        $result = $existingId !== null
            ? wp_update_term($existingId, $this->taxonomy, $attributes)
            : wp_insert_term($name, $this->taxonomy, $attributes);

        if ((function_exists('is_wp_error') && is_wp_error($result)) || !is_array($result)) {
            throw new RuntimeException('Failed to save term.');
        }

        $termId = isset($result['term_id']) ? (int) $result['term_id'] : 0;
        if ($termId <= 0) {
            throw new RuntimeException('Failed to resolve saved term ID.');
        }

        return $termId;
    }

    /**
     * Apply declared meta and ACF payloads after the core term is written.
     *
     * @param int $termId
     * @return void
     */
    private function applySideEffects(int $termId): void
    {
        WpMeta::write('update_term_meta', $termId, $this->payload['meta'] ?? []);

        $acf = $this->payload['acf'] ?? [];
        if (is_array($acf) && $acf !== []) {
            $this->context->acf()->updateFields($acf, 'term', $termId);
        }
    }

    /**
     * @param object|null $existing
     * @param array<string, mixed> $attributes
     * @param OwnedResource|null $owned
     * @param string $slug
     * @return OperationAction
     */
    private function termOperation(
        ?object $existing,
        array $attributes,
        ?OwnedResource $owned,
        string $slug,
    ): OperationAction {
        if ($existing === null) {
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

        return Slug::sanitize($name);
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

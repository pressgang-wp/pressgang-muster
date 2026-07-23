<?php

namespace PressGang\Muster\Builders;

use PressGang\Muster\Contracts\PersistableDeclaration;
use LogicException;
use RuntimeException;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Ownership\HasOwnership;
use PressGang\Muster\Ownership\OwnedResource;
use PressGang\Muster\Ownership\ResolvesIdentity;
use PressGang\Muster\Refs\PostRef;
use PressGang\Muster\Refs\LazyRef;
use PressGang\Muster\Refs\TermRef;
use PressGang\Muster\Refs\UserRef;
use PressGang\Muster\Results\OperationAction;
use PressGang\Muster\Support\Slug;
use PressGang\Muster\Support\WpMeta;
use PressGang\Muster\Support\WpResult;

/**
 * Fluent post builder with idempotent merge-upsert behaviour.
 *
 * Muster-scoped builders use an explicit logical key; `post_type + post_name`
 * is the WordPress locator and may change for an already owned post. Only
 * fields set on this builder are updated; omitted fields retain their current
 * WordPress values. Calling a setter with an empty value explicitly clears it.
 */
final class PostBuilder implements PersistableDeclaration
{
    use HasOwnership;
    use ResolvesIdentity;
    use GuardsAcfMeta;

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
     * @param string|null $ownershipScope
     */
    public function __construct(
        private MusterContext $context,
        private string $postType = 'post',
        ?string $title = null,
        ?string $ownershipScope = null,
    ) {
        $this->initializeOwnership($ownershipScope);
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
     * @param string|int|UserRef|LazyRef $user
     * @return self
     */
    public function author(string|int|UserRef|LazyRef $user): self
    {
        $this->payload['post_author'] = $user;

        return $this;
    }

    /**
     * Pin the publish date — fixture dates must be deterministic or every
     * rendered date (and visual snapshot) drifts with the seeding run.
     *
     * @param string $date MySQL datetime, e.g. '2026-01-01 09:00:00'.
     * @return self
     */
    public function date(string $date): self
    {
        $this->payload['post_date'] = $date;

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
     * @param string|int|PostRef|LazyRef $parent
     * @return self
     */
    public function parent(string|int|PostRef|LazyRef $parent): self
    {
        $this->payload['post_parent'] = $parent;

        return $this;
    }

    /**
     * @param string $taxonomy
     * @param array<int, string|int|TermRef|LazyRef> $terms
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
     * Declare a post from a nested array instead of chained setters.
     *
     * The vocabulary is WordPress's own — the same keys `wp_insert_post()`
     * accepts (`post_title`, `post_name`, `post_status`, `post_content`,
     * `post_excerpt`, `post_date`, `post_parent`, `post_author`, `page_template`,
     * `meta_input`, `tax_input`) — so there is no second vocabulary to learn. The
     * only additions are the two things WordPress has no key for: `acf` (an
     * `update_field()`-shaped map) and Muster's logical `key`/`adopt` identity.
     *
     * Each key dispatches to the matching fluent setter, so this is pure sugar:
     * everything they do — ref resolution, the raw-meta-vs-ACF guard on save,
     * merge-upsert semantics — applies unchanged. `fill()` merges with setters
     * called before or after it (last write wins), and an unrecognised key
     * throws rather than being silently dropped.
     *
     * Values are the same shape the setters take: `tax_input` is
     * `['taxonomy' => [slug|id|ref, …]]`, `meta_input`/`acf` are flat maps
     * (nest arrays for ACF repeaters/groups), and `post_parent`/`post_author`
     * accept an id or a {@see \PressGang\Muster\Refs\PostRef}/{@see \PressGang\Muster\Refs\UserRef}/{@see \PressGang\Muster\Refs\LazyRef}.
     *
     * This only sets builder state; nothing is written until {@see save()}.
     *
     * @param array<string, mixed> $attributes WordPress `wp_insert_post()` keys
     *     plus `acf`, `key`, and `adopt`.
     * @return self
     * @throws LogicException If an attribute key is not a recognised field.
     */
    public function fill(array $attributes): self
    {
        foreach ($attributes as $field => $value) {
            match ($field) {
                'post_title' => $this->title((string) $value),
                'post_name' => $this->slug((string) $value),
                'post_status' => $this->status((string) $value),
                'post_content' => $this->content((string) $value),
                'post_excerpt' => $this->excerpt((string) $value),
                'post_date' => $this->date((string) $value),
                'post_parent' => $this->parent($value),
                'post_author' => $this->author($value),
                'page_template' => $this->template((string) $value),
                'meta_input' => $this->meta((array) $value),
                'tax_input' => $this->fillTaxonomies((array) $value),
                'acf' => $this->acf((array) $value),
                'key' => $this->key((string) $value),
                'adopt' => $this->adopt((bool) $value),
                default => throw new LogicException(sprintf(
                    'fill(): unrecognised key [%s]. Accepts wp_insert_post() fields '
                    . '(post_title, post_name, post_status, post_content, post_excerpt, post_date, '
                    . 'post_parent, post_author, page_template, meta_input, tax_input) plus acf, key, adopt.',
                    $field,
                )),
            };
        }

        return $this;
    }

    /**
     * Apply a `tax_input`-shaped map through the per-taxonomy {@see terms()} setter.
     *
     * @param array<string, array<int, string|int|TermRef|LazyRef>> $taxInput
     * @return void
     */
    private function fillTaxonomies(array $taxInput): void
    {
        foreach ($taxInput as $taxonomy => $terms) {
            $this->terms((string) $taxonomy, (array) $terms);
        }
    }

    /**
     * @return PostRef
     *
     * Managed identity is `Muster class + logical key`; the WordPress locator is
     * `post_type + post_name` (slug). Unowned locator matches require `adopt()`.
     * Lookup is performed with `get_posts()` using `name`, `post_type`, and `post_status=any`.
     * Existing records are updated via `wp_update_post()`; missing records are inserted via
     * `wp_insert_post()`. Meta payload is applied with `update_post_meta()`; ACF payload
     * with `update_field()` via the context adapter.
     *
     * A `meta()` key that the theme's acf-json registers as an ACF field for this
     * post type is rejected before any write (plan and apply alike): it must go
     * through `acf()` so `update_field()` stores the field-key reference
     * `get_field()` needs — a raw meta write to that key reads back empty.
     *
     * See: https://developer.wordpress.org/reference/functions/get_posts/
     * See: https://developer.wordpress.org/reference/functions/wp_update_post/
     * See: https://developer.wordpress.org/reference/functions/wp_insert_post/
     * See: https://developer.wordpress.org/reference/functions/update_post_meta/
     *
     * @throws LogicException If neither slug nor title is set, or a `meta()` key
     *     names an ACF field for this post type.
     * @throws RuntimeException If WordPress runtime functions are unavailable or save fails.
     */
    public function save(): PostRef
    {
        $slug = $this->resolveSlug();
        $this->assertMetaKeysNotAcfFields(
            $this->context,
            (array) ($this->payload['meta_input'] ?? []),
            'post',
            $this->postType,
            $this->postType . ':' . $slug,
        );
        $intent = $this->ownershipIntent();

        if (!function_exists('get_posts')) {
            throw new RuntimeException('get_posts() is required to plan or save posts.');
        }

        ['existing' => $existingId, 'owned' => $owned] = $this->resolveIdentity(
            $this->context,
            $intent,
            'post',
            $this->postType,
            $slug,
            findNatural: fn (): ?int => $this->findBySlug($slug),
            resolveOwned: fn (OwnedResource $owned): ?int => $this->resolveOwnedPostId($owned),
            idOf: static fn (int $id): int => $id,
            conflictMessage: fn (int $naturalId): string => sprintf(
                'Cannot move owned post [%s:%s] to slug [%s]; that slug belongs to post ID %d.',
                $intent['scope'],
                $intent['key'],
                $slug,
                $naturalId
            ),
        );

        $attributes = $this->buildAttributes($slug);
        $taxInput = $this->resolveTaxInput();

        $this->context->debugDeclaration('Post', [
            ...array_keys($attributes),
            ...array_map(static fn (string $key): string => 'meta.' . $key, array_keys((array) ($this->payload['meta_input'] ?? []))),
            ...array_map(static fn (string $key): string => 'terms.' . $key, array_keys($taxInput)),
            ...array_map(static fn (string $key): string => 'acf.' . $key, array_keys((array) ($this->payload['acf'] ?? []))),
        ]);

        $operation = $this->postOperation($existingId, $attributes, $owned, $slug);

        if ($this->context->dryRun()) {
            $plannedId = $existingId ?? 0;
            $this->finalizeUpsert($this->context, $intent, $operation, 'post', $plannedId, $this->postType, $slug);

            return new PostRef($plannedId, $this->postType, $slug);
        }

        if ($operation === OperationAction::Keep && $existingId !== null) {
            $this->finalizeUpsert($this->context, $intent, $operation, 'post', $existingId, $this->postType, $slug);

            return new PostRef($existingId, $this->postType, $slug);
        }

        $postId = $this->writePost($existingId, $attributes, $slug);
        $this->applySideEffects($postId, $taxInput);
        $this->finalizeUpsert($this->context, $intent, $operation, 'post', $postId, $this->postType, $slug);

        $this->context->logger()->debug(
            sprintf('Post %s [%s:%s] as ID %d.', $operation->value, $this->postType, $slug, $postId)
        );

        return new PostRef($postId, $this->postType, $slug);
    }

    /**
     * Assemble the WordPress write attributes from declared builder state.
     *
     * Only declared fields are included, preserving merge-upsert semantics
     * for everything the declaration omits.
     *
     * @param string $slug
     * @return array<string, mixed>
     */
    private function buildAttributes(string $slug): array
    {
        $attributes = [
            'post_type' => $this->postType,
            'post_name' => $slug,
        ];

        foreach (['post_title', 'post_content', 'post_excerpt', 'post_status'] as $field) {
            if (array_key_exists($field, $this->payload)) {
                $attributes[$field] = (string) $this->payload[$field];
            }
        }

        if (array_key_exists('post_parent', $this->payload)) {
            $attributes['post_parent'] = $this->resolveParentId($this->payload['post_parent']);
        }

        $author = $this->resolveAuthorId($this->payload['post_author'] ?? null);
        if ($author !== null) {
            $attributes['post_author'] = $author;
        }

        if (isset($this->payload['post_date'])) {
            $attributes['post_date'] = (string) $this->payload['post_date'];
            // Without edit_date, wp_update_post ignores post_date changes on
            // existing posts — upserted fixtures must re-pin their date too.
            $attributes['edit_date'] = true;
        }

        return $attributes;
    }

    /**
     * Resolve declared term refs per taxonomy into IDs and slugs.
     *
     * @return array<string, array<int, string|int>>
     */
    private function resolveTaxInput(): array
    {
        $resolved = [];
        foreach ($this->taxInput as $taxonomy => $terms) {
            $resolved[$taxonomy] = $this->resolveTerms($terms, $taxonomy);
        }

        return $resolved;
    }

    /**
     * Insert or update the core post record and return its ID.
     *
     * @param int|null $existingId
     * @param array<string, mixed> $attributes
     * @param string $slug
     * @return int
     * @throws RuntimeException If write functions are unavailable or the save fails.
     */
    private function writePost(?int $existingId, array $attributes, string $slug): int
    {
        if (!function_exists('wp_insert_post') || !function_exists('wp_update_post')) {
            throw new RuntimeException('WordPress write functions are required to save posts.');
        }

        if ($existingId !== null) {
            $attributes['ID'] = $existingId;
            $saveResult = wp_update_post($attributes, true);
        } else {
            // Fixture defaults, applied to fresh inserts ONLY — never to the
            // update path, so merge-upsert still preserves fields the caller
            // omits on a re-run. A post fixture almost always wants to be
            // published, and its date must be deterministic (the fixture
            // epoch, ADR 0004), so neither should need writing out by hand.
            $attributes += [
                'post_status' => 'publish',
                'post_date' => $this->context->clock()->epoch()->format('Y-m-d H:i:s'),
            ];
            $attributes['edit_date'] = true;

            $saveResult = wp_insert_post($attributes, true);
        }

        if (!WpResult::isId($saveResult)) {
            // Surface WordPress's own reason — a bare "failed" hides exactly
            // the detail (invalid date, bad author, DB error) a fixture
            // author needs to fix their Muster.
            $reason = (function_exists('is_wp_error') && is_wp_error($saveResult))
                ? $saveResult->get_error_message()
                : var_export($saveResult, true);

            throw new RuntimeException(sprintf('Failed to save post [%s:%s]: %s', $this->postType, $slug, $reason));
        }

        return (int) $saveResult;
    }

    /**
     * Apply declared meta, template, taxonomy, and ACF payloads after the
     * core post record has been written.
     *
     * @param int $postId
     * @param array<string, array<int, string|int>> $taxInput
     * @return void
     */
    private function applySideEffects(int $postId, array $taxInput): void
    {
        WpMeta::write('update_post_meta', $postId, $this->payload['meta_input'] ?? []);

        if (isset($this->payload['page_template']) && function_exists('update_post_meta')) {
            update_post_meta($postId, '_wp_page_template', (string) $this->payload['page_template']);
        }

        if ($taxInput !== [] && function_exists('wp_set_object_terms')) {
            foreach ($taxInput as $taxonomy => $terms) {
                wp_set_object_terms($postId, $terms, $taxonomy, false);
            }
        }

        $acf = $this->payload['acf'] ?? [];
        if (is_array($acf) && $acf !== []) {
            $this->context->acf()->updateFields($acf, 'post', $postId);
        }
    }

    /**
     * Determine whether the declaration creates, updates, or keeps the post.
     *
     * Meta, taxonomy, template, and ACF payloads are conservatively reported
     * as updates until those adapters expose comparable read contracts.
     *
     * @param int|null $existingId
     * @param array<string, mixed> $attributes
     * @param OwnedResource|null $owned
     * @param string $slug
     * @return OperationAction
     */
    private function postOperation(
        ?int $existingId,
        array $attributes,
        ?OwnedResource $owned,
        string $slug,
    ): OperationAction {
        if ($existingId === null) {
            if ($owned !== null && $this->context->ownership()->isPlannedClaim($owned->scope(), $owned->key())) {
                return OperationAction::Keep;
            }

            return OperationAction::Create;
        }

        if ($owned === null || $owned->locator() !== $slug
            || !empty($this->payload['meta_input'])
            || isset($this->payload['page_template'])
            || $this->taxInput !== []
            || !empty($this->payload['acf'])) {
            return OperationAction::Update;
        }

        $post = function_exists('get_post') ? get_post($existingId) : null;
        if (!is_object($post)) {
            return OperationAction::Update;
        }

        foreach ($attributes as $field => $value) {
            if ($field === 'edit_date') {
                continue;
            }

            if (!property_exists($post, $field) || (string) $post->{$field} !== (string) $value) {
                return OperationAction::Update;
            }
        }

        return OperationAction::Keep;
    }

    private function findBySlug(string $slug): ?int
    {
        $existing = get_posts([
            'name' => $slug,
            'post_type' => $this->postType,
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'suppress_filters' => true,
            'no_found_rows' => true,
        ]);

        return empty($existing) ? null : (int) $existing[0];
    }

    private function resolveOwnedPostId(OwnedResource $owned): ?int
    {
        if (!function_exists('get_post')) {
            throw new RuntimeException('get_post() is required to resolve owned posts.');
        }

        $post = get_post($owned->id());

        return is_object($post)
            && isset($post->ID, $post->post_type)
            && (string) $post->post_type === $this->postType
                ? (int) $post->ID
                : null;
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
            return Slug::sanitize($title);
        }

        throw new LogicException('Post slug is required when title is not set.');
    }

    /**
     * @param mixed $author
     * @return int|null
     */
    private function resolveAuthorId(mixed $author): ?int
    {
        if ($author instanceof LazyRef) {
            return $author->resolve('user')->id();
        }

        if ($author instanceof UserRef) {
            return $author->userId();
        }

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
        if ($parent instanceof LazyRef) {
            return $parent->resolve('post', $this->postType)->id();
        }

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

    /**
     * @param array<int, string|int|TermRef|LazyRef> $terms
     * @return array<int, string|int>
     */
    private function resolveTerms(array $terms, string $taxonomy): array
    {
        return array_map(
            static fn (mixed $term): string|int => match (true) {
                $term instanceof LazyRef => $term->resolve('term', $taxonomy)->id(),
                $term instanceof TermRef => $term->termId(),
                default => $term,
            },
            $terms
        );
    }
}

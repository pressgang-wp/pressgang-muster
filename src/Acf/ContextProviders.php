<?php

namespace PressGang\Muster\Acf;

use PressGang\Muster\Builders\AttachmentBuilder;
use PressGang\Muster\Builders\PostBuilder;
use PressGang\Muster\Builders\TermBuilder;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Support\Slug;

/**
 * Wires AcfValueGenerator's providers to live Muster builders.
 *
 * The generator is deliberately pure — media and relational fields come from
 * injected callables. This is the one canonical wiring of those callables to
 * real WordPress objects (placeholder attachments, stub posts and terms), so
 * every consumer — Muster::acfFor(), Shakedown's sandbox seeding — creates
 * identical fixtures instead of each rolling its own.
 */
final class ContextProviders
{
    /**
     * Build the provider set for a context.
     *
     * All created objects use stable logical keys owned by $ownershipScope and
     * WordPress locators prefixed with $prefix. Repeated generation therefore
     * reuses the same stubs rather than multiplying them.
     *
     * @param MusterContext $context
     * @param string $ownershipScope Concrete Muster class that owns created support objects.
     * @param string $prefix Slug prefix for created stub objects.
     * @return array{
     *     attachment: callable(string): int,
     *     post: callable(array<int, string>): int,
     *     term: callable(string): int,
     *     user: callable(): int
     * }
     */
    public static function wire(MusterContext $context, string $ownershipScope, string $prefix = 'seed'): array
    {
        return [
            'attachment' => static fn (string $name): int => self::attachment($context, $ownershipScope, $prefix, $name),
            'post' => static fn (array $postTypes): int => self::post($context, $ownershipScope, $prefix, $postTypes),
            'term' => static fn (string $taxonomy): int => self::term($context, $ownershipScope, $prefix, $taxonomy),
            'user' => static fn (): int => 1,
        ];
    }

    /**
     * Upsert the deterministic placeholder attachment for one media field.
     *
     * @param MusterContext $context
     * @param string $scope Owning Muster class.
     * @param string $prefix Slug prefix for created stubs.
     * @param string $name Field-derived name for the stub.
     * @return int Attachment ID.
     */
    private static function attachment(MusterContext $context, string $scope, string $prefix, string $name): int
    {
        $slug = "{$prefix}-" . Slug::sanitize($name);

        // A deterministic placeholder shared by every field of this name. If one
        // already exists — created by an earlier run, even under a different
        // ownership scope (a site seed, then a test setup against the same DB) —
        // reuse it rather than re-claim it, which would be an ownership conflict.
        $existing = self::existingPostId('attachment', $slug);
        if ($existing !== null) {
            return $existing;
        }

        return (new AttachmentBuilder($context, $slug, $scope))
            ->key('acf:attachment:' . Slug::sanitize($name))
            ->placeholder(1200, 800)
            ->save()
            ->id();
    }

    /**
     * Upsert the stub post used by relational fields.
     *
     * @param MusterContext $context
     * @param string $scope Owning Muster class.
     * @param string $prefix Slug prefix for created stubs.
     * @param array<int, string> $postTypes Allowed post types; the first wins.
     * @return int Post ID.
     */
    private static function post(MusterContext $context, string $scope, string $prefix, array $postTypes): int
    {
        $type = $postTypes[0] ?? 'post';
        $slug = "{$prefix}-related-" . Slug::sanitize($type);

        $existing = self::existingPostId($type, $slug);
        if ($existing !== null) {
            return $existing;
        }

        $ref = (new PostBuilder($context, $type, ownershipScope: $scope))
            ->key('acf:post:' . Slug::sanitize($type))
            ->title(ucfirst($type) . ' fixture')
            ->slug($slug)
            ->status('publish')
            // Pin well before the fixture epoch so a relationship stub never
            // outranks real seeded content in date-ordered feeds (latest posts,
            // archives). Deterministic: derived from the shared clock.
            ->date($context->clock()->epoch()->modify('-1 year')->format('Y-m-d H:i:s'))
            ->save();

        // A placeholder featured image, so a stub that does surface (a
        // relationship card, an otherwise-empty feed) shows a thumbnail rather
        // than the theme's empty-image fallback.
        (new AttachmentBuilder($context, "{$slug}-thumb", $scope))
            ->key('acf:post-thumb:' . Slug::sanitize($type))
            ->placeholder(1200, 800)
            ->featuredOn($ref)
            ->save();

        return $ref->id();
    }

    /**
     * Upsert the stub term used by taxonomy fields.
     *
     * @param MusterContext $context
     * @param string $scope Owning Muster class.
     * @param string $prefix Slug prefix for created stubs.
     * @param string $taxonomy Target taxonomy.
     * @return int Term ID.
     */
    private static function term(MusterContext $context, string $scope, string $prefix, string $taxonomy): int
    {
        $slug = "{$prefix}-term";

        $existing = self::existingTermId($taxonomy, $slug);
        if ($existing !== null) {
            return $existing;
        }

        return (new TermBuilder($context, $taxonomy, ownershipScope: $scope))
            ->key('acf:term:' . Slug::sanitize($taxonomy))
            ->name('Fixture term')
            ->slug($slug)
            ->save()
            ->termId();
    }

    /**
     * The ID of an existing post/attachment with this slug, or null.
     *
     * @param string $postType
     * @param string $slug
     * @return int|null
     */
    private static function existingPostId(string $postType, string $slug): ?int
    {
        if (!function_exists('get_posts')) {
            return null;
        }

        $ids = get_posts([
            'name' => $slug,
            'post_type' => $postType,
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'suppress_filters' => true,
            'no_found_rows' => true,
        ]);

        return $ids ? (int) $ids[0] : null;
    }

    /**
     * The ID of an existing term with this slug in $taxonomy, or null.
     *
     * @param string $taxonomy
     * @param string $slug
     * @return int|null
     */
    private static function existingTermId(string $taxonomy, string $slug): ?int
    {
        if (!function_exists('get_term_by')) {
            return null;
        }

        $term = get_term_by('slug', $slug, $taxonomy);

        return is_object($term) && isset($term->term_id) ? (int) $term->term_id : null;
    }
}

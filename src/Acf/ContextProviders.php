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
        return (new AttachmentBuilder($context, "{$prefix}-" . Slug::sanitize($name), $scope))
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

        return (new PostBuilder($context, $type, ownershipScope: $scope))
            ->key('acf:post:' . Slug::sanitize($type))
            ->title(ucfirst($type) . ' fixture')
            ->slug("{$prefix}-related-" . Slug::sanitize($type))
            ->status('publish')
            ->save()
            ->id();
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
        return (new TermBuilder($context, $taxonomy, ownershipScope: $scope))
            ->key('acf:term:' . Slug::sanitize($taxonomy))
            ->name('Fixture term')
            ->slug("{$prefix}-term")
            ->save()
            ->termId();
    }
}

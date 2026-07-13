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
            'attachment' => fn (string $name): int => (new AttachmentBuilder(
                $context,
                "{$prefix}-" . Slug::sanitize($name),
                $ownershipScope
            ))
                ->key('acf:attachment:' . Slug::sanitize($name))
                ->placeholder(1200, 800)
                ->save()
                ->id(),

            'post' => function (array $postTypes) use ($context, $ownershipScope, $prefix): int {
                $type = $postTypes[0] ?? 'post';

                return (new PostBuilder($context, $type, ownershipScope: $ownershipScope))
                    ->key('acf:post:' . Slug::sanitize($type))
                    ->title(ucfirst($type) . ' fixture')
                    ->slug("{$prefix}-related-" . Slug::sanitize($type))
                    ->status('publish')
                    ->save()
                    ->id();
            },

            'term' => fn (string $taxonomy): int => (new TermBuilder($context, $taxonomy, ownershipScope: $ownershipScope))
                ->key('acf:term:' . Slug::sanitize($taxonomy))
                ->name('Fixture term')
                ->slug("{$prefix}-term")
                ->save()
                ->termId(),

            'user' => fn (): int => 1,
        ];
    }
}

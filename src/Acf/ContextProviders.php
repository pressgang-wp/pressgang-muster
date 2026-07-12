<?php

namespace PressGang\Muster\Acf;

use PressGang\Muster\Builders\AttachmentBuilder;
use PressGang\Muster\Builders\PostBuilder;
use PressGang\Muster\Builders\TermBuilder;
use PressGang\Muster\MusterContext;

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
     * All created objects use idempotent natural keys prefixed with $prefix,
     * so repeated generation reuses the same stubs rather than multiplying
     * them — and callers with committed visual baselines can keep their
     * historical prefix to avoid churning screenshot URLs.
     *
     * @param MusterContext $context
     * @param string $prefix Slug prefix for created stub objects.
     * @return array{
     *     attachment: callable(string): int,
     *     post: callable(array<int, string>): int,
     *     term: callable(string): int,
     *     user: callable(): int
     * }
     */
    public static function wire(MusterContext $context, string $prefix = 'seed'): array
    {
        return [
            'attachment' => fn (string $name): int => (new AttachmentBuilder($context, "{$prefix}-" . self::slug($name)))
                ->placeholder(1200, 800)
                ->save()
                ->id(),

            'post' => function (array $postTypes) use ($context, $prefix): int {
                $type = $postTypes[0] ?? 'post';

                return (new PostBuilder($context, $type))
                    ->title(ucfirst($type) . ' fixture')
                    ->slug("{$prefix}-related-" . self::slug($type))
                    ->status('publish')
                    ->save()
                    ->id();
            },

            'term' => fn (string $taxonomy): int => (new TermBuilder($context, $taxonomy))
                ->name('Fixture term')
                ->slug("{$prefix}-term")
                ->save()
                ->termId(),

            'user' => fn (): int => 1,
        ];
    }

    /**
     * Slugify via WordPress when available, with a pure fallback so the
     * wiring stays constructible in unit tests.
     *
     * @param string $value
     * @return string
     */
    private static function slug(string $value): string
    {
        if (function_exists('sanitize_title')) {
            return (string) sanitize_title($value);
        }

        return strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $value), '-'));
    }
}

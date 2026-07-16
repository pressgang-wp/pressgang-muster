<?php

/**
 * API index manifest for pressgang-wp/muster.
 *
 * Consumed by `wp capstan make api-index`, which reflects the classes and
 * methods named here into the standard docs/api-index.json schema. Bump
 * `version` to match the release when tagging.
 */

use PressGang\Muster\Muster;
use PressGang\Muster\Patterns\Pattern;
use PressGang\Muster\Patterns\Recipe;

return [
    'package' => 'pressgang-wp/muster',
    'version' => '0.2.0',
    'entrypoint' => Muster::class,
    'principles' => [
        'WordPress-native: builders write through wp_insert_* — no ORM, no models',
        'Deterministic: a seed fixes generated values, an epoch fixes "now"',
        'Idempotent: logical keys make seeding a merge-upsert, not a duplicate',
        'Own only what you seed: ownership registry scopes teardown',
    ],
    'groups' => [
        'Orchestration' => [Muster::class, ['run', 'assemble', 'group', 'call']],
        'Content builders' => [Muster::class, ['content', 'post', 'page', 'term', 'user', 'option', 'menu', 'attachment', 'comment']],
        'References' => [Muster::class, ['ref']],
        'Patterns & recipes' => [Muster::class, ['pattern', 'recipe', 'sequence']],
        'ACF' => [Muster::class, ['acfFor']],
        'Determinism' => [Muster::class, ['epoch', 'at', 'victuals', 'defaultEpoch']],
        'Reset' => [Muster::class, ['truncate', 'resetOwned', 'pruneOwned']],
        'Recipe' => [Recipe::class, ['define', 'named', 'count', 'withThumbnail', 'make', 'create']],
        'Pattern' => [Pattern::class, ['count', 'seed', 'after', 'withThumbnail', 'build', 'using']],
    ],
];

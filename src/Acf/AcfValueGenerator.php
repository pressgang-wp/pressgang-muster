<?php

namespace PressGang\Muster\Acf;

use PressGang\Muster\Victuals\Victuals;

/**
 * Generates deterministic ACF field values from field definitions.
 *
 * The output feeds `PostBuilder::acf()`: top-level values are keyed by field
 * key (reliable for update_field even before a value exists); sub-values
 * inside repeaters, groups, and flexible content are keyed by sub-field name,
 * as ACF's update APIs expect.
 *
 * Two variants drive optional-field state testing:
 *  - `populated()` — every generatable field gets a value.
 *  - `minimal()`   — only required fields do, simulating the sparsest
 *    content an editor can legally publish. Templates must survive both;
 *    the difference between the two states is where "empty link" and
 *    "missing image" defects live.
 *
 * Determinism: values come from the seeded Victuals instance, so the same
 * seed produces identical fixtures across runs and machines.
 *
 * Relational and media fields need live WordPress objects, so they are
 * produced by injected providers and silently omitted when no provider is
 * given — the generator itself stays pure and unit-testable.
 */
final class AcfValueGenerator
{
    /**
     * @param Victuals $victuals Seeded value source.
     * @param array{
     *     attachment?: callable(string): int,
     *     post?: callable(array<int, string>): int,
     *     term?: callable(string): int,
     *     user?: callable(): int
     * } $providers Suppliers of live object IDs for media/relational fields.
     */
    public function __construct(
        private Victuals $victuals,
        private array $providers = [],
    ) {
    }

    /**
     * Values for every generatable field, keyed by field key.
     *
     * @param array<int, array<string, mixed>> $fields A group's `fields` array.
     * @return array<string, mixed>
     */
    public function populated(array $fields): array
    {
        return $this->generate($fields, false);
    }

    /**
     * Values for required fields only — the sparsest publishable state.
     *
     * @param array<int, array<string, mixed>> $fields A group's `fields` array.
     * @return array<string, mixed>
     */
    public function minimal(array $fields): array
    {
        return $this->generate($fields, true);
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @param bool $requiredOnly
     * @return array<string, mixed>
     */
    private function generate(array $fields, bool $requiredOnly): array
    {
        $values = [];

        foreach ($fields as $field) {
            if ($requiredOnly && empty($field['required'])) {
                continue;
            }

            $value = $this->value($field);

            if ($value !== null) {
                $values[(string) ($field['key'] ?? $field['name'])] = $value;
            }
        }

        return $values;
    }

    /**
     * A representative value for one field definition, or null when the type
     * is non-content (tabs, messages) or needs an absent provider.
     *
     * @param array<string, mixed> $field
     * @return mixed
     */
    private function value(array $field): mixed
    {
        return match ((string) ($field['type'] ?? 'text')) {
            'text' => $this->victuals->headline(),
            'textarea' => $this->victuals->sentence(12),
            'wysiwyg' => $this->victuals->content(2),
            'email' => $this->victuals->email(),
            'url' => $this->victuals->url(),
            'number', 'range' => $this->numberWithin($field),
            'true_false' => 1,
            'select', 'radio', 'button_group' => $this->choice($field),
            'checkbox' => array_slice(array_keys((array) ($field['choices'] ?? [])), 0, 1),
            'link' => ['title' => $this->victuals->sentence(3), 'url' => '/', 'target' => ''],
            'date_picker' => $this->victuals->date('Ymd'),
            'date_time_picker' => $this->victuals->datetime(),
            'time_picker' => '10:30:00',
            'color_picker' => '#0e7c86',
            'oembed' => 'https://www.youtube.com/watch?v=jNQXAC9IVRw',
            'image', 'file' => $this->fromProvider('attachment', (string) ($field['name'] ?? 'media')),
            'gallery' => $this->gallery($field),
            'post_object', 'page_link' => $this->relatedPost($field),
            'relationship' => $this->relatedPosts($field),
            'taxonomy' => $this->relatedTerm($field),
            'user' => $this->fromProvider('user'),
            'repeater' => $this->repeaterRows($field),
            'group' => $this->subValues((array) ($field['sub_fields'] ?? [])),
            'flexible_content' => $this->flexibleRows($field),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $field
     */
    private function numberWithin(array $field): int
    {
        $min = is_numeric($field['min'] ?? null) ? (int) $field['min'] : 1;
        $max = is_numeric($field['max'] ?? null) ? (int) $field['max'] : max(10, $min);

        return (int) floor(($min + $max) / 2);
    }

    /**
     * First declared choice — honouring `multiple` by wrapping in an array.
     *
     * @param array<string, mixed> $field
     */
    private function choice(array $field): mixed
    {
        $first = array_key_first((array) ($field['choices'] ?? []));

        if ($first === null) {
            return null;
        }

        return empty($field['multiple']) ? $first : [$first];
    }

    /**
     * @return mixed Provider result, or null when the provider is absent.
     */
    private function fromProvider(string $name, mixed ...$args): mixed
    {
        return isset($this->providers[$name]) ? ($this->providers[$name])(...$args) : null;
    }

    /**
     * @param array<string, mixed> $field
     * @return array<int, int>|null
     */
    private function gallery(array $field): ?array
    {
        $name = (string) ($field['name'] ?? 'gallery');
        $first = $this->fromProvider('attachment', "{$name}-1");
        $second = $this->fromProvider('attachment', "{$name}-2");

        return $first === null ? null : array_values(array_filter([$first, $second]));
    }

    /**
     * @param array<string, mixed> $field
     */
    private function relatedPost(array $field): mixed
    {
        $id = $this->fromProvider('post', (array) ($field['post_type'] ?? []));

        if ($id === null) {
            return null;
        }

        return empty($field['multiple']) ? $id : [$id];
    }

    /**
     * @param array<string, mixed> $field
     * @return array<int, int>|null Relationship values are always arrays.
     */
    private function relatedPosts(array $field): ?array
    {
        $id = $this->fromProvider('post', (array) ($field['post_type'] ?? []));

        return $id === null ? null : [$id];
    }

    /**
     * @param array<string, mixed> $field
     */
    private function relatedTerm(array $field): mixed
    {
        $id = $this->fromProvider('term', (string) ($field['taxonomy'] ?? 'category'));

        if ($id === null) {
            return null;
        }

        $multi = in_array($field['field_type'] ?? 'checkbox', ['checkbox', 'multi_select'], true);

        return $multi ? [$id] : $id;
    }

    /**
     * Rows for a repeater: at least two (or the declared minimum), each a
     * name-keyed map of sub-field values.
     *
     * @param array<string, mixed> $field
     * @return array<int, array<string, mixed>>
     */
    private function repeaterRows(array $field): array
    {
        $count = max(2, (int) ($field['min'] ?? 0));
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = $this->subValues((array) ($field['sub_fields'] ?? []));
        }

        return $rows;
    }

    /**
     * One row per declared layout, so every layout template renders at
     * least once.
     *
     * @param array<string, mixed> $field
     * @return array<int, array<string, mixed>>
     */
    private function flexibleRows(array $field): array
    {
        $rows = [];

        foreach ((array) ($field['layouts'] ?? []) as $layout) {
            $rows[] = ['acf_fc_layout' => (string) ($layout['name'] ?? '')]
                + $this->subValues((array) ($layout['sub_fields'] ?? []));
        }

        return $rows;
    }

    /**
     * Sub-field values keyed by NAME (ACF's update APIs expect names inside
     * repeater/group/layout rows, unlike top-level selectors).
     *
     * @param array<int, array<string, mixed>> $subFields
     * @return array<string, mixed>
     */
    private function subValues(array $subFields): array
    {
        $values = [];

        foreach ($subFields as $subField) {
            $value = $this->value($subField);

            if ($value !== null) {
                $values[(string) ($subField['name'] ?? '')] = $value;
            }
        }

        return $values;
    }
}

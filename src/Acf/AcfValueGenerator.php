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
     * The required-only filter applies at every depth: a required repeater or
     * group still recurses, but its rows carry only their own required
     * sub-fields. This is what makes `minimal()` a faithful "sparsest" state —
     * the optional-field gaps that break templates (empty link, missing image)
     * are reproduced inside nested content, not just at the top level.
     *
     * @param array<int, array<string, mixed>> $fields A group's `fields` array.
     * @return array<string, mixed>
     */
    public function minimal(array $fields): array
    {
        return $this->generate($fields, true);
    }

    /**
     * Top-level values, keyed by field KEY (what update_field wants for a
     * post's own fields). Thin wrapper over collect() with the key strategy.
     *
     * @param array<int, array<string, mixed>> $fields
     * @return array<string, mixed>
     */
    private function generate(array $fields, bool $requiredOnly): array
    {
        return $this->collect(
            $fields,
            $requiredOnly,
            static fn (array $field): string => (string) ($field['key'] ?? $field['name'] ?? ''),
        );
    }

    /**
     * The one loop behind both top-level and nested value building: skip
     * optional fields when required-only, generate a value, drop nulls, and
     * key each survivor via $keyOf (field key at the top level, sub-field name
     * inside rows). Centralising it keeps the two key strategies as the only
     * difference between the two callers.
     *
     * @param array<int, array<string, mixed>> $fields
     * @param callable(array<string, mixed>): string $keyOf
     * @return array<string, mixed>
     */
    private function collect(array $fields, bool $requiredOnly, callable $keyOf): array
    {
        $values = [];

        foreach ($fields as $field) {
            if ($requiredOnly && empty($field['required'])) {
                continue;
            }

            $value = $this->value($field, $requiredOnly);

            if ($value !== null) {
                $values[$keyOf($field)] = $value;
            }
        }

        return $values;
    }

    /**
     * A representative value for one field definition, or null when the type
     * is non-content (tabs, messages) or needs an absent provider.
     *
     * $requiredOnly is carried through so that container fields (repeater,
     * group, flexible content) recurse with the same required-only policy.
     *
     * @param array<string, mixed> $field
     * @return mixed
     */
    private function value(array $field, bool $requiredOnly): mixed
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
            'checkbox' => $this->choice($field, alwaysArray: true),
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
            'repeater' => $this->repeaterRows($field, $requiredOnly),
            'group' => $this->subValues((array) ($field['sub_fields'] ?? []), $requiredOnly),
            'flexible_content' => $this->flexibleRows($field, $requiredOnly),
            default => null,
        };
    }

    /**
     * Midpoint of the field's declared `min`/`max` (defaulting to 1..max(10))
     * — a value that sits safely inside any validated range. Bounds are
     * coarsened to integers, so decimal `min`/`max`/`step` on a number/range
     * field are not honoured; that is acceptable for fixture data.
     *
     * @param array<string, mixed> $field
     */
    private function numberWithin(array $field): int
    {
        $min = is_numeric($field['min'] ?? null) ? (int) $field['min'] : 1;
        $max = is_numeric($field['max'] ?? null) ? (int) $field['max'] : max(10, $min);

        return (int) floor(($min + $max) / 2);
    }

    /**
     * First declared choice, or null when the field declares none. Wrapped in
     * an array when the field is `multiple` or when $alwaysArray is set —
     * checkbox values are always arrays, so it passes the latter to stay
     * consistent with the null-on-empty behaviour of select/radio.
     *
     * @param array<string, mixed> $field
     */
    private function choice(array $field, bool $alwaysArray = false): mixed
    {
        $first = array_key_first((array) ($field['choices'] ?? []));

        if ($first === null) {
            return null;
        }

        return ($alwaysArray || ! empty($field['multiple'])) ? [$first] : $first;
    }

    /**
     * @return mixed Provider result, or null when the provider is absent.
     */
    private function fromProvider(string $name, mixed ...$args): mixed
    {
        return isset($this->providers[$name]) ? ($this->providers[$name])(...$args) : null;
    }

    /**
     * Up to two attachment IDs from the provider — enough to prove a gallery
     * renders multiple items. Null (field skipped) when no provider supplies
     * even the first.
     *
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
     * A single related post ID from the provider, wrapped in an array when the
     * field is `multiple` (post_object) — null when no provider is available.
     *
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
     * A single term ID from the provider, wrapped in an array for the
     * multi-value `field_type`s (checkbox, multi_select) and returned bare
     * otherwise — null when no provider is available.
     *
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
    private function repeaterRows(array $field, bool $requiredOnly): array
    {
        $count = max(2, (int) ($field['min'] ?? 0));
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = $this->subValues((array) ($field['sub_fields'] ?? []), $requiredOnly);
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
    private function flexibleRows(array $field, bool $requiredOnly): array
    {
        $rows = [];

        foreach ((array) ($field['layouts'] ?? []) as $layout) {
            $rows[] = ['acf_fc_layout' => (string) ($layout['name'] ?? '')]
                + $this->subValues((array) ($layout['sub_fields'] ?? []), $requiredOnly);
        }

        return $rows;
    }

    /**
     * Sub-field values keyed by NAME (ACF's update APIs expect names inside
     * repeater/group/layout rows, unlike the top-level KEY selector). Same
     * collect() loop as generate(), differing only in that key strategy.
     *
     * @param array<int, array<string, mixed>> $subFields
     * @return array<string, mixed>
     */
    private function subValues(array $subFields, bool $requiredOnly): array
    {
        return $this->collect(
            $subFields,
            $requiredOnly,
            static fn (array $subField): string => (string) ($subField['name'] ?? ''),
        );
    }
}

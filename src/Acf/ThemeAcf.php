<?php

namespace PressGang\Muster\Acf;

/**
 * Bridges the active theme's acf-json exports to generated seed values.
 *
 * Why: a Muster shouldn't hand-maintain field lists that acf-json already
 * declares. Given a seed target (a post type slug or a page template path),
 * this finds every field group located on that target and merges the
 * generated values — so `->acf($this->acfFor('event'))` stays correct as
 * field groups evolve.
 */
final class ThemeAcf
{
    /**
     * Generated values for every field group targeting $target.
     *
     * @param string $target A seedable location value matched against each
     *     group's rules via {@see AcfJson::targets()}: a post type slug
     *     (`event`), a page/post template path (`page-templates/contact.php`),
     *     an options-page slug (`site-options`), a page_type (`front_page`), or
     *     a nav-menu-item location value (`location/primary`).
     * @param AcfValueGenerator $generator
     * @param string $variant `populated` or `minimal`.
     * @param string|null $acfJsonDir Override for tests; defaults to the
     *     active theme's acf-json directory.
     * @return array<string, mixed> update_field-ready values, keyed by field key.
     */
    public static function valuesFor(
        string $target,
        AcfValueGenerator $generator,
        string $variant = 'populated',
        ?string $acfJsonDir = null,
    ): array {
        $dir = $acfJsonDir ?? self::themeAcfJsonDir();

        if ($dir === null || ! is_dir($dir)) {
            return [];
        }

        $values = [];

        foreach (AcfJson::groups($dir) as $group) {
            if (! self::groupTargets($group, $target)) {
                continue;
            }

            $fields = (array) $group['fields'];

            // Field keys are globally unique, so merging groups can't collide;
            // += keeps the first value if a duplicate export ever slips in.
            $values += $variant === 'minimal'
                ? $generator->minimal($fields)
                : $generator->populated($fields);
        }

        return $values;
    }

    /**
     * The top-level field names every acf-json group targeting $target declares.
     *
     * Mirrors {@see valuesFor()}'s target matching but returns field *names* —
     * the meta keys ACF writes each value under — instead of generated values.
     * A caller uses this to detect a raw `meta()` key that collides with an ACF
     * field, so it can steer the write to `acf()`/`update_field()` instead. Only
     * top-level names are returned; sub-field names live inside serialized
     * repeater/group rows and are never addressable as a post's own meta key.
     *
     * @param string $target A seedable location value (post type slug, template
     *     path, options-page slug, …), matched via {@see AcfJson::targets()}.
     * @param string|null $acfJsonDir Override for tests; defaults to the active
     *     theme's acf-json directory.
     * @return list<string> Unique field names; empty when no acf-json or no match.
     */
    public static function fieldNamesFor(string $target, ?string $acfJsonDir = null): array
    {
        $dir = $acfJsonDir ?? self::themeAcfJsonDir();

        if ($dir === null || ! is_dir($dir)) {
            return [];
        }

        $names = [];

        foreach (AcfJson::groups($dir) as $group) {
            if (! self::groupTargets($group, $target)) {
                continue;
            }

            foreach ((array) $group['fields'] as $field) {
                $name = (string) ($field['name'] ?? '');

                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Whether any of a group's seedable location rules point at $target.
     *
     * Delegates the "what is seedable" question to {@see AcfJson::targets()},
     * which already filters to {@see AcfJson::SEEDABLE_PARAMS} — so a target may
     * be a post type, a page/post template path, an options-page slug, a
     * page_type (e.g. `front_page`), or a nav-menu-item location value. Kept as
     * a value match with no second allowlist here, so the two can never drift.
     *
     * @param array<string, mixed> $group
     * @param string $target
     * @return bool
     */
    private static function groupTargets(array $group, string $target): bool
    {
        foreach (AcfJson::targets($group) as $rule) {
            if ($rule['value'] === $target) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string|null
     */
    private static function themeAcfJsonDir(): ?string
    {
        if (! function_exists('get_stylesheet_directory')) {
            return null;
        }

        return get_stylesheet_directory() . '/acf-json';
    }
}

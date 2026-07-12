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
     * @param string $target A post type slug (e.g. `event`) or page template
     *     path (e.g. `page-templates/contact.php`) — matched against each
     *     group's location rules via {@see AcfJson::targets()}.
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
     * @param array<string, mixed> $group
     * @param string $target
     * @return bool
     */
    private static function groupTargets(array $group, string $target): bool
    {
        foreach (AcfJson::targets($group) as $rule) {
            if ($rule['value'] === $target && in_array($rule['param'], ['post_type', 'page_template'], true)) {
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

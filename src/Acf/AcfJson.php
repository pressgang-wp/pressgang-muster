<?php

namespace PressGang\Muster\Acf;

/**
 * Reads ACF field-group definitions from a theme's `acf-json/` directory.
 *
 * Why: acf-json is the machine-readable contract of a site's editorial
 * surface — every field, its type, its required flag, and where it applies.
 * Muster derives seed values from it (see AcfValueGenerator) instead of
 * hand-writing fixtures per site.
 */
final class AcfJson
{
    /**
     * ACF location params a seeder can act on, and the single source of truth
     * for "seedable" across the toolkit — {@see targets()} and
     * {@see ThemeAcf::groupTargets()} both defer to this list so they can never
     * disagree about what is reachable.
     *
     * All six are plain WordPress/ACF location params; nothing here assumes a
     * particular theme framework (see docs/adr/0005-seedable-location-params.md).
     *
     * @var list<string>
     */
    public const SEEDABLE_PARAMS = [
        'post_type',
        'page_template',
        'post_template',
        'options_page',
        'page_type',
        'nav_menu_item',
    ];

    /**
     * Load all field groups from a directory of ACF JSON exports.
     *
     * Files without a `key` and `fields` array (e.g. ACF's index stubs)
     * are skipped.
     *
     * @param string $dir Path to a theme's acf-json directory.
     * @return array<int, array<string, mixed>> Decoded field groups.
     */
    public static function groups(string $dir): array
    {
        $files = glob(rtrim($dir, '/') . '/*.json') ?: [];
        $groups = [];

        foreach ($files as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);

            if (is_array($decoded) && isset($decoded['key']) && is_array($decoded['fields'] ?? null)) {
                $groups[] = $decoded;
            }
        }

        return $groups;
    }

    /**
     * The seedable targets a group's location rules point at.
     *
     * ACF locations are an OR-list of AND-rule groups. Equality rules for any
     * of {@see SEEDABLE_PARAMS} are returned — every editorial surface a seeder
     * can act on: create a post of that type, a page/post with that template,
     * write a group's option values, seed the front page, or attach values to
     * a location's nav-menu items. Non-equality operators and unseedable params
     * (taxonomy terms, user roles) are skipped.
     *
     * @param array<string, mixed> $group A decoded field group.
     * @return array<int, array{param: string, value: string}>
     */
    public static function targets(array $group): array
    {
        $targets = [];

        foreach ((array) ($group['location'] ?? []) as $andGroup) {
            foreach ((array) $andGroup as $rule) {
                $param = $rule['param'] ?? '';

                if (($rule['operator'] ?? '') === '==' && in_array($param, self::SEEDABLE_PARAMS, true)) {
                    $targets[] = ['param' => $param, 'value' => (string) $rule['value']];
                }
            }
        }

        return array_values(array_unique($targets, SORT_REGULAR));
    }
}

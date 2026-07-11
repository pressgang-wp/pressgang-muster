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
     * ACF locations are an OR-list of AND-rule groups. Only equality rules
     * for `post_type`, `page_template`, and `options_page` are returned —
     * the params a seeder can act on (create a post of that type / a page
     * with that template / write the group's option values). Other params
     * (taxonomy terms, user roles) are out of scope for v1.
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

                if (($rule['operator'] ?? '') === '==' && in_array($param, ['post_type', 'page_template', 'options_page'], true)) {
                    $targets[] = ['param' => $param, 'value' => (string) $rule['value']];
                }
            }
        }

        return array_values(array_unique($targets, SORT_REGULAR));
    }
}

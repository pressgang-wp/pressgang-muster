<?php

namespace PressGang\Muster\Adapters;

/**
 * ACF adapter backed by the real `update_field()` API.
 *
 * Values are passed straight through, so anything `update_field()` accepts works:
 * scalars, post/term/user IDs for relational fields, arrays of row arrays for
 * repeaters, and keyed arrays for groups.
 *
 * See: https://www.advancedcustomfields.com/resources/update_field/
 */
final class LiveAcfAdapter implements AcfAdapterInterface
{
    /**
     * Persist ACF fields for a saved WordPress object.
     *
     * Object targets are translated to ACF's post-id shape:
     * `post` => int, `term` => "term_{id}", `user` => "user_{id}", `option` => "option".
     *
     * @param array<string, mixed> $fields
     * @param string $objectType
     * @param int $objectId
     * @return void
     */
    public function updateFields(array $fields, string $objectType, int $objectId): void
    {
        if (!function_exists('update_field')) {
            return;
        }

        $target = $this->resolveTarget($objectType, $objectId);

        foreach ($fields as $selector => $value) {
            update_field((string) $selector, $value, $target);
        }
    }

    /**
     * @param string $objectType
     * @param int $objectId
     * @return int|string
     */
    private function resolveTarget(string $objectType, int $objectId): int|string
    {
        return match ($objectType) {
            'term' => 'term_' . $objectId,
            'user' => 'user_' . $objectId,
            'option' => 'option',
            default => $objectId,
        };
    }
}

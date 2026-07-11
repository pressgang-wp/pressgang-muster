<?php

namespace PressGang\Muster\Support;

/**
 * Interprets return values from WordPress write APIs.
 *
 * Why: WordPress insert/update functions signal failure as either a `WP_Error`
 * or a non-positive int. Every builder needs the same "did that save produce a
 * usable ID?" check, so the rule lives here once.
 */
final class WpResult
{
    /**
     * Check whether a WordPress insert/update result is a usable object ID.
     *
     * @param mixed $result
     * @return bool True when the result is a positive integer ID.
     */
    public static function isId(mixed $result): bool
    {
        if (function_exists('is_wp_error') && is_wp_error($result)) {
            return false;
        }

        return is_int($result) && $result > 0;
    }
}

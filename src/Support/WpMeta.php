<?php

namespace PressGang\Muster\Support;

/**
 * Applies a meta payload through one WordPress meta updater.
 *
 * Why: every builder that persists meta repeats the same "guard the updater,
 * loop the payload, stringify keys" shape for its own object type. The rule
 * lives here once; builders pass the updater that matches their resource.
 */
final class WpMeta
{
    /**
     * Write each key/value pair via the named updater, e.g. `update_post_meta`.
     *
     * A non-array payload or missing updater function (WordPress not loaded)
     * is a silent no-op, preserving the builders' behaviour of writing only
     * what the runtime can accept.
     *
     * See: https://developer.wordpress.org/reference/functions/update_post_meta/
     *
     * @param string $updater WordPress meta updater function name.
     * @param int $id Object ID the meta belongs to.
     * @param mixed $meta Meta payload; ignored unless it is an array.
     * @return void
     */
    public static function write(string $updater, int $id, mixed $meta): void
    {
        if (!is_array($meta) || !function_exists($updater)) {
            return;
        }

        foreach ($meta as $key => $value) {
            $updater($id, (string) $key, $value);
        }
    }
}

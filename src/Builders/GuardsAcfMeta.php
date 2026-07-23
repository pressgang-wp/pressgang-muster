<?php

namespace PressGang\Muster\Builders;

use LogicException;
use PressGang\Muster\MusterContext;

/**
 * Rejects a raw `meta()` write that would silently clobber an ACF field.
 *
 * ACF stores each field's value under its field name as an object meta key and
 * writes a `field_…` reference alongside it; `update_post_meta()` /
 * `update_term_meta()` write only the former, so `get_field()` reads the value
 * back unformatted or empty. When the theme's acf-json declares a `meta()` key
 * as an ACF field for the object being written, that raw write is (almost
 * always) a mistake — this fails loudly rather than let the data land where ACF
 * cannot see it. The post and term builders share the check verbatim; only the
 * object type and locator differ, so it lives here once.
 *
 * Keys the theme does not register as ACF fields pass through untouched, so
 * genuine raw meta is unaffected, and with no acf-json present the check is
 * inert. It runs during planning too, so a conflict blocks application (ADR 0002).
 */
trait GuardsAcfMeta
{
    /**
     * Reject any declared meta key that names an ACF field for the object.
     *
     * @param MusterContext $context The run context, for the acf-json lookup.
     * @param array<string, mixed> $meta The declared raw-meta payload.
     * @param string $objectType `post` or `term` (see {@see MusterContext::acfFieldNames()}).
     * @param string $target The post type slug or taxonomy the object belongs to.
     * @param string $locator A human-readable identifier for the error, e.g. `event:my-slug`.
     * @return void
     * @throws LogicException If any meta key names an ACF field for the object.
     */
    private function assertMetaKeysNotAcfFields(
        MusterContext $context,
        array $meta,
        string $objectType,
        string $target,
        string $locator,
    ): void {
        if ($meta === []) {
            return;
        }

        $collisions = array_values(array_intersect(
            array_keys($meta),
            $context->acfFieldNames($objectType, $target),
        ));

        if ($collisions === []) {
            return;
        }

        throw new LogicException(sprintf(
            'meta() key(s) [%s] on %s [%s] name ACF field(s) declared in the theme\'s acf-json. '
            . 'Write them with acf([...]) so update_field() stores the field-key reference '
            . 'get_field() needs; a raw meta write leaves the value unreadable by ACF.',
            implode(', ', $collisions),
            $objectType,
            $locator,
        ));
    }
}

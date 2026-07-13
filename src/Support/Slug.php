<?php

namespace PressGang\Muster\Support;

/**
 * Canonical slug derivation shared by builders and generated values.
 *
 * Why: several components need "turn arbitrary text into a URL-safe slug" —
 * builders deriving locators from titles, Victuals generating slugs, and ACF
 * fixture wiring naming stub objects. WordPress's own `sanitize_title()` is
 * the authority whenever it is loaded; the pure fallback keeps the same code
 * paths usable in unit tests that run without WordPress.
 */
final class Slug
{
    /**
     * Slugify via WordPress when available, with the pure fallback otherwise.
     *
     * See: https://developer.wordpress.org/reference/functions/sanitize_title/
     *
     * @param string $value Arbitrary source text, e.g. a post title.
     * @return string URL-safe slug.
     */
    public static function sanitize(string $value): string
    {
        if (function_exists('sanitize_title')) {
            return (string) sanitize_title($value);
        }

        return self::fallback($value);
    }

    /**
     * Pure slugify that never calls WordPress: alphanumeric runs joined by
     * single hyphens, lowercased. Deterministic for identical input.
     *
     * @param string $value Arbitrary source text.
     * @return string URL-safe slug.
     */
    public static function fallback(string $value): string
    {
        return strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $value), '-'));
    }
}

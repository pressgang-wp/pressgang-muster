<?php

namespace PressGang\Muster\Builders;

use RuntimeException;
use PressGang\Muster\MusterContext;

/**
 * Clean-slate reset helper for re-seedable environments.
 *
 * Each call executes immediately and returns the builder, so resets read as a
 * declarative list at the top of a Muster `run()`:
 *
 *     $this->truncate()->posts('event')->terms('event_type');
 *
 * Deletions are permanent (`force_delete`) — this is a fixtures tool, not a
 * content-management tool. Guard usage by environment in your Muster class.
 */
final class TruncateBuilder
{
    /**
     * @param MusterContext $context
     */
    public function __construct(private MusterContext $context)
    {
    }

    /**
     * Permanently delete all posts of a type (any status).
     *
     * Media can be reset the same way: attachments are posts of type
     * `attachment`, so `->posts('attachment')` clears the media library.
     *
     * See: https://developer.wordpress.org/reference/functions/wp_delete_post/
     *
     * @param string $postType
     * @return self
     *
     * @throws RuntimeException If WordPress runtime functions are unavailable.
     */
    public function posts(string $postType): self
    {
        if ($this->context->dryRun()) {
            $this->context->logger()->info(sprintf('Dry run truncate posts [%s].', $postType));

            return $this;
        }

        if (!function_exists('get_posts') || !function_exists('wp_delete_post')) {
            throw new RuntimeException('WordPress runtime functions are required to truncate posts.');
        }

        $ids = get_posts([
            'post_type' => $postType,
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'suppress_filters' => true,
            'no_found_rows' => true,
        ]);

        foreach ($ids as $id) {
            wp_delete_post((int) $id, true);
        }

        $this->context->logger()->debug(sprintf('Truncated %d posts [%s].', count($ids), $postType));

        return $this;
    }

    /**
     * Permanently delete all terms in a taxonomy.
     *
     * See: https://developer.wordpress.org/reference/functions/wp_delete_term/
     *
     * @param string $taxonomy
     * @return self
     *
     * @throws RuntimeException If WordPress runtime functions are unavailable.
     */
    public function terms(string $taxonomy): self
    {
        if ($this->context->dryRun()) {
            $this->context->logger()->info(sprintf('Dry run truncate terms [%s].', $taxonomy));

            return $this;
        }

        if (!function_exists('get_terms') || !function_exists('wp_delete_term')) {
            throw new RuntimeException('WordPress runtime functions are required to truncate terms.');
        }

        $ids = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'fields' => 'ids',
        ]);

        if (function_exists('is_wp_error') && is_wp_error($ids)) {
            return $this;
        }

        foreach ((array) $ids as $id) {
            wp_delete_term((int) $id, $taxonomy);
        }

        $this->context->logger()->debug(sprintf('Truncated %d terms [%s].', count((array) $ids), $taxonomy));

        return $this;
    }
}

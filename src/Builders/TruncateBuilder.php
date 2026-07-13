<?php

namespace PressGang\Muster\Builders;

use RuntimeException;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Ownership\OwnedResource;
use PressGang\Muster\Results\Operation;
use PressGang\Muster\Results\OperationAction;

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
        if (!function_exists('get_posts')) {
            throw new RuntimeException('get_posts() is required to plan or truncate posts.');
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
            $resource = new OwnedResource('truncate', "post:{$postType}:{$id}", 'post', (int) $id, $postType, $postType);
            if ($this->context->dryRun()) {
                $this->context->markPlannedDeletion($resource);
                $this->reportPrune($resource);
                continue;
            }

            if (!function_exists('wp_delete_post')) {
                throw new RuntimeException('wp_delete_post() is required to truncate posts.');
            }

            wp_delete_post((int) $id, true);
            $this->reportPrune($resource);
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
        if (!function_exists('get_terms')) {
            throw new RuntimeException('get_terms() is required to plan or truncate terms.');
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
            $resource = new OwnedResource('truncate', "term:{$taxonomy}:{$id}", 'term', (int) $id, $taxonomy, $taxonomy);
            if ($this->context->dryRun()) {
                $this->context->markPlannedDeletion($resource);
                $this->reportPrune($resource);
                continue;
            }

            if (!function_exists('wp_delete_term')) {
                throw new RuntimeException('wp_delete_term() is required to truncate terms.');
            }

            wp_delete_term((int) $id, $taxonomy);
            $this->reportPrune($resource);
        }

        $this->context->logger()->debug(sprintf('Truncated %d terms [%s].', count((array) $ids), $taxonomy));

        return $this;
    }

    private function reportPrune(OwnedResource $resource): void
    {
        $this->context->report()->add(new Operation(
            OperationAction::Prune,
            $resource->type(),
            $resource->scope(),
            $resource->key(),
            $resource->locator(),
            $resource->id()
        ));
    }
}

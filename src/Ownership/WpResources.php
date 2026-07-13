<?php

namespace PressGang\Muster\Ownership;

use RuntimeException;

/**
 * WordPress dispatch for deleting and existence-checking owned resources.
 *
 * The ownership registry decides *which* resources to remove; this class
 * knows *how* each resource type maps onto WordPress's delete and lookup
 * functions. Supporting a new resource type means extending the two matches
 * here without touching claim/conflict policy.
 */
final class WpResources
{
    /**
     * Permanently delete one owned resource through WordPress.
     *
     * Deletion is forced (no trash): the registry only deletes resources it
     * owns, and owned fixtures are disposable by definition. A resource that
     * no longer exists is a silent no-op.
     *
     * See: https://developer.wordpress.org/reference/functions/wp_delete_post/
     * See: https://developer.wordpress.org/reference/functions/wp_delete_term/
     * See: https://developer.wordpress.org/reference/functions/wp_delete_user/
     * See: https://developer.wordpress.org/reference/functions/delete_option/
     * See: https://developer.wordpress.org/reference/functions/wp_delete_nav_menu/
     * See: https://developer.wordpress.org/reference/functions/wp_delete_comment/
     *
     * @param OwnedResource $resource
     * @return void
     * @throws RuntimeException If the delete function is missing or the delete fails.
     */
    public function delete(OwnedResource $resource): void
    {
        if (!$this->exists($resource)) {
            return;
        }

        $result = match ($resource->type()) {
            'post', 'attachment' => function_exists('wp_delete_post')
                ? wp_delete_post($resource->id(), true)
                : throw new RuntimeException('wp_delete_post() is required to reset owned posts.'),
            'term' => function_exists('wp_delete_term')
                ? wp_delete_term($resource->id(), $resource->subtype())
                : throw new RuntimeException('wp_delete_term() is required to reset owned terms.'),
            'user' => function_exists('wp_delete_user')
                ? wp_delete_user($resource->id())
                : throw new RuntimeException('wp_delete_user() is required to reset owned users.'),
            'option' => function_exists('delete_option')
                ? delete_option($resource->locator())
                : throw new RuntimeException('delete_option() is required to reset owned options.'),
            'menu' => function_exists('wp_delete_nav_menu')
                ? wp_delete_nav_menu($resource->id())
                : throw new RuntimeException('wp_delete_nav_menu() is required to reset owned menus.'),
            'comment' => function_exists('wp_delete_comment')
                ? wp_delete_comment($resource->id(), true)
                : throw new RuntimeException('wp_delete_comment() is required to reset owned comments.'),
            default => throw new RuntimeException(sprintf('Cannot delete unknown owned resource type [%s].', $resource->type())),
        };

        if ($result === false || (function_exists('is_wp_error') && is_wp_error($result))) {
            throw new RuntimeException(sprintf('Failed to delete owned resource [%s:%s].', $resource->scope(), $resource->key()));
        }
    }

    /**
     * Check whether the owned resource still exists in WordPress.
     *
     * Unavailable lookup functions err on the side of existence so `delete()`
     * still attempts the removal and fails loudly rather than silently
     * skipping it.
     *
     * See: https://developer.wordpress.org/reference/functions/get_post/
     * See: https://developer.wordpress.org/reference/functions/get_term/
     * See: https://developer.wordpress.org/reference/functions/get_user_by/
     * See: https://developer.wordpress.org/reference/functions/get_option/
     * See: https://developer.wordpress.org/reference/functions/wp_get_nav_menu_object/
     * See: https://developer.wordpress.org/reference/functions/get_comment/
     *
     * @param OwnedResource $resource
     * @return bool
     */
    public function exists(OwnedResource $resource): bool
    {
        return match ($resource->type()) {
            'post', 'attachment' => function_exists('get_post')
                ? (($post = get_post($resource->id())) !== null && $post !== false)
                : true,
            'term' => function_exists('get_term')
                ? (($term = get_term($resource->id(), $resource->subtype())) !== null
                    && $term !== false
                    && !(function_exists('is_wp_error') && is_wp_error($term)))
                : true,
            'user' => function_exists('get_user_by')
                ? get_user_by('id', $resource->id()) !== false
                : true,
            'option' => function_exists('get_option')
                ? $this->optionExists($resource->locator())
                : true,
            'menu' => function_exists('wp_get_nav_menu_object')
                ? wp_get_nav_menu_object($resource->id()) !== false
                : true,
            'comment' => function_exists('get_comment')
                ? get_comment($resource->id()) !== null
                : true,
            default => true,
        };
    }

    private function optionExists(string $name): bool
    {
        $missing = new \stdClass();

        return get_option($name, $missing) !== $missing;
    }
}

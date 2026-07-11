<?php

namespace PressGang\Muster\Builders;

use LogicException;
use RuntimeException;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Refs\MenuRef;
use PressGang\Muster\Refs\PostRef;
use PressGang\Muster\Refs\TermRef;

/**
 * Fluent nav-menu builder with rebuild-on-save semantics.
 *
 * Identity rule: menu name. The menu record is upserted, but its items are
 * deleted and recreated on every save so item order and structure are fully
 * deterministic between runs.
 */
final class MenuBuilder
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $items = [];

    /**
     * @var array<int, string>
     */
    private array $locations = [];

    /**
     * @param MusterContext $context
     * @param string $name
     */
    public function __construct(
        private MusterContext $context,
        private string $name,
    ) {
    }

    /**
     * Assign the menu to a registered theme location.
     *
     * @param string $location
     * @return self
     */
    public function location(string $location): self
    {
        $this->locations[] = $location;

        return $this;
    }

    /**
     * Add a custom-link item.
     *
     * @param string $title
     * @param string $url
     * @param string|null $parent Title of a previously added item to nest under.
     * @return self
     */
    public function link(string $title, string $url, ?string $parent = null): self
    {
        $this->items[] = ['type' => 'custom', 'title' => $title, 'url' => $url, 'parent' => $parent];

        return $this;
    }

    /**
     * Add a post-object item (page, post, or any CPT entry).
     *
     * @param PostRef|int $post
     * @param string|null $title Optional label override.
     * @param string|null $parent Title of a previously added item to nest under.
     * @return self
     */
    public function postItem(PostRef|int $post, ?string $title = null, ?string $parent = null): self
    {
        $this->items[] = [
            'type' => 'post_type',
            'object_id' => $post instanceof PostRef ? $post->id() : $post,
            'object' => $post instanceof PostRef ? $post->postType() : null,
            'title' => $title,
            'parent' => $parent,
        ];

        return $this;
    }

    /**
     * Add a taxonomy-term item.
     *
     * @param TermRef|int $term
     * @param string|null $taxonomy Required when passing a raw term ID.
     * @param string|null $title Optional label override.
     * @param string|null $parent Title of a previously added item to nest under.
     * @return self
     */
    public function termItem(TermRef|int $term, ?string $taxonomy = null, ?string $title = null, ?string $parent = null): self
    {
        $this->items[] = [
            'type' => 'taxonomy',
            'object_id' => $term instanceof TermRef ? $term->termId() : $term,
            'object' => $term instanceof TermRef ? $term->taxonomy() : $taxonomy,
            'title' => $title,
            'parent' => $parent,
        ];

        return $this;
    }

    /**
     * @return MenuRef
     *
     * Menu record is upserted by name via `wp_get_nav_menu_object()` /
     * `wp_create_nav_menu()`. Existing items are removed, declared items are
     * recreated in order via `wp_update_nav_menu_item()`, and theme locations
     * are assigned through the `nav_menu_locations` theme mod.
     *
     * See: https://developer.wordpress.org/reference/functions/wp_create_nav_menu/
     * See: https://developer.wordpress.org/reference/functions/wp_update_nav_menu_item/
     *
     * @throws LogicException If the menu name is empty.
     * @throws RuntimeException If WordPress runtime functions are unavailable or save fails.
     */
    public function save(): MenuRef
    {
        if (trim($this->name) === '') {
            throw new LogicException('Menu name is required.');
        }

        if ($this->context->dryRun()) {
            $this->context->logger()->info(
                sprintf('Dry run menu rebuild [%s] with %d items.', $this->name, count($this->items))
            );

            return new MenuRef(0, $this->name);
        }

        if (!function_exists('wp_create_nav_menu') || !function_exists('wp_update_nav_menu_item')) {
            throw new RuntimeException('WordPress runtime functions are required to save menus.');
        }

        $menuId = $this->upsertMenu();
        $this->deleteExistingItems($menuId);

        $idsByTitle = [];
        foreach ($this->items as $position => $item) {
            $parentId = 0;
            if (is_string($item['parent'] ?? null) && isset($idsByTitle[$item['parent']])) {
                $parentId = $idsByTitle[$item['parent']];
            }

            $args = [
                'menu-item-status' => 'publish',
                'menu-item-position' => $position + 1,
                'menu-item-parent-id' => $parentId,
            ];

            if ($item['type'] === 'custom') {
                $args['menu-item-type'] = 'custom';
                $args['menu-item-title'] = (string) $item['title'];
                $args['menu-item-url'] = (string) $item['url'];
            } else {
                $args['menu-item-type'] = (string) $item['type'];
                $args['menu-item-object-id'] = (int) $item['object_id'];
                if (!empty($item['object'])) {
                    $args['menu-item-object'] = (string) $item['object'];
                }
                if (!empty($item['title'])) {
                    $args['menu-item-title'] = (string) $item['title'];
                }
            }

            $itemId = wp_update_nav_menu_item($menuId, 0, $args);

            if ((function_exists('is_wp_error') && is_wp_error($itemId)) || !is_int($itemId) || $itemId <= 0) {
                throw new RuntimeException(sprintf('Failed to save menu item in [%s].', $this->name));
            }

            $title = (string) ($item['title'] ?? '');
            if ($title !== '') {
                $idsByTitle[$title] = $itemId;
            }
        }

        $this->assignLocations($menuId);

        $this->context->logger()->debug(
            sprintf('Menu rebuilt [%s] as ID %d with %d items.', $this->name, $menuId, count($this->items))
        );

        return new MenuRef($menuId, $this->name);
    }

    /**
     * @return int
     *
     * @throws RuntimeException If menu creation fails.
     */
    private function upsertMenu(): int
    {
        if (function_exists('wp_get_nav_menu_object')) {
            $existing = wp_get_nav_menu_object($this->name);

            if (is_object($existing) && isset($existing->term_id)) {
                return (int) $existing->term_id;
            }
        }

        $menuId = wp_create_nav_menu($this->name);

        if ((function_exists('is_wp_error') && is_wp_error($menuId)) || !is_int($menuId) || $menuId <= 0) {
            throw new RuntimeException(sprintf('Failed to create menu [%s].', $this->name));
        }

        return $menuId;
    }

    /**
     * @param int $menuId
     * @return void
     */
    private function deleteExistingItems(int $menuId): void
    {
        if (!function_exists('wp_get_nav_menu_items') || !function_exists('wp_delete_post')) {
            return;
        }

        $items = wp_get_nav_menu_items($menuId) ?: [];
        foreach ($items as $item) {
            if (isset($item->ID)) {
                wp_delete_post((int) $item->ID, true);
            }
        }
    }

    /**
     * @param int $menuId
     * @return void
     */
    private function assignLocations(int $menuId): void
    {
        if ($this->locations === [] || !function_exists('get_theme_mod') || !function_exists('set_theme_mod')) {
            return;
        }

        $assigned = get_theme_mod('nav_menu_locations');
        $assigned = is_array($assigned) ? $assigned : [];

        foreach ($this->locations as $location) {
            $assigned[$location] = $menuId;
        }

        set_theme_mod('nav_menu_locations', $assigned);
    }
}

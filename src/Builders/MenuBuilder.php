<?php

namespace PressGang\Muster\Builders;

use PressGang\Muster\Contracts\PersistableDeclaration;
use LogicException;
use RuntimeException;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Ownership\HasOwnership;
use PressGang\Muster\Refs\MenuRef;
use PressGang\Muster\Refs\PostRef;
use PressGang\Muster\Refs\TermRef;
use PressGang\Muster\Refs\LazyRef;
use PressGang\Muster\Results\OperationAction;
use PressGang\Muster\Support\WpResult;

/**
 * Fluent nav-menu builder with rebuild-on-save semantics.
 *
 * Muster-scoped builders use an explicit logical key; menu name is the mutable
 * WordPress locator. The menu record is upserted, but its items are always
 * deleted and recreated on save so declared order and nesting remain canonical.
 *
 * Nesting: pass `parent:` with the title of a *previously declared* item.
 */
final class MenuBuilder implements PersistableDeclaration
{
    use HasOwnership;

    /**
     * Declared items in insertion order.
     *
     * Item spec shape: `type` ('custom'|'post_type'|'taxonomy'), `title`,
     * `url` (custom only), `object_id` + `object` (object items),
     * and `parent` (title of an earlier item, or null).
     *
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
     * @param string|null $ownershipScope
     */
    public function __construct(
        private MusterContext $context,
        private string $name,
        ?string $ownershipScope = null,
    ) {
        $this->initializeOwnership($ownershipScope);
    }

    /**
     * Assign the menu to a registered theme location after save.
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
     * @param string|null $parent Title of a previously declared item to nest under.
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
     * @param PostRef|LazyRef|int $post
     * @param string|null $title Optional label override.
     * @param string|null $parent Title of a previously declared item to nest under.
     * @param string|null $postType Required for correct rendering when passing a
     *     raw ID; inferred automatically from a PostRef.
     * @return self
     */
    public function postItem(PostRef|LazyRef|int $post, ?string $title = null, ?string $parent = null, ?string $postType = null): self
    {
        $this->items[] = [
            'type' => 'post_type',
            'object_id' => $post instanceof PostRef ? $post->id() : $post,
            'object' => $post instanceof PostRef ? $post->postType() : $postType,
            'title' => $title,
            'parent' => $parent,
        ];

        return $this;
    }

    /**
     * Add a taxonomy-term item.
     *
     * @param TermRef|LazyRef|int $term
     * @param string|null $taxonomy Required when passing a raw term ID;
     *     inferred automatically from a TermRef.
     * @param string|null $title Optional label override.
     * @param string|null $parent Title of a previously declared item to nest under.
     * @return self
     */
    public function termItem(TermRef|LazyRef|int $term, ?string $taxonomy = null, ?string $title = null, ?string $parent = null): self
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
     * Upsert the menu, rebuild its items, and assign theme locations.
     * Unowned name matches require explicit `adopt()`.
     *
     * See: https://developer.wordpress.org/reference/functions/wp_create_nav_menu/
     * See: https://developer.wordpress.org/reference/functions/wp_update_nav_menu_item/
     *
     * @return MenuRef
     *
     * @throws LogicException If the menu name is empty.
     * @throws RuntimeException If WordPress runtime functions are unavailable or a save fails.
     */
    public function save(): MenuRef
    {
        if (trim($this->name) === '') {
            throw new LogicException('Menu name is required.');
        }

        $intent = $this->ownershipIntent();
        $resolvedItems = $this->resolveItems();
        $this->context->debugDeclaration('Menu', [
            'name',
            ...($resolvedItems === [] ? [] : ['items']),
            ...($this->locations === [] ? [] : ['locations']),
        ]);

        $natural = function_exists('wp_get_nav_menu_object') ? wp_get_nav_menu_object($this->name) : false;
        $naturalId = is_object($natural) && isset($natural->term_id) ? (int) $natural->term_id : null;
        if ($naturalId !== null
            && $this->context->isPlannedDeleted('menu', $naturalId, 'nav_menu', $this->name)) {
            $naturalId = null;
        }

        $ownedId = null;
        $owned = null;

        if ($intent !== null) {
            $owned = $this->currentOwnership($this->context, $intent, 'menu', 'nav_menu');

            if ($owned !== null && function_exists('wp_get_nav_menu_object')) {
                $ownedMenu = wp_get_nav_menu_object($owned->id());
                $ownedId = is_object($ownedMenu) && isset($ownedMenu->term_id) ? (int) $ownedMenu->term_id : null;
                if ($ownedId !== null
                    && $this->context->isPlannedDeleted('menu', $ownedId, 'nav_menu', $owned->locator())) {
                    $ownedId = null;
                }
            }

            if ($ownedId !== null && $naturalId !== null && $ownedId !== $naturalId) {
                $this->throwOwnershipConflict(
                    $this->context,
                    $intent,
                    'menu',
                    $naturalId,
                    $this->name,
                    sprintf('Menu name [%s] belongs to a different menu.', $this->name)
                );
            }

            $existingId = $ownedId ?? $naturalId;
            if ($existingId !== null) {
                $this->claimExistingOwnership($this->context, $intent, 'menu', $existingId, 'nav_menu', $this->name);
            }
        }

        $existingId = $ownedId ?? $naturalId;
        $plannedClaim = $intent !== null
            && $this->context->ownership()->isPlannedClaim($intent['scope'], $intent['key']);
        $operation = $existingId === null
            ? ($plannedClaim ? OperationAction::Keep : OperationAction::Create)
            : OperationAction::Update;
        $plannedId = $existingId ?? 0;

        if ($this->context->dryRun()) {
            $this->finalizeUpsert($this->context, $intent, $operation, 'menu', $plannedId, 'nav_menu', $this->name);

            return new MenuRef($plannedId, $this->name);
        }

        if (!function_exists('wp_create_nav_menu') || !function_exists('wp_update_nav_menu_item')) {
            throw new RuntimeException('WordPress write functions are required to save menus.');
        }

        $menuId = $this->upsertMenu($ownedId);

        $this->deleteExistingItems($menuId);
        $this->createItems($menuId, $resolvedItems);
        $this->assignLocations($menuId);

        $this->finalizeUpsert($this->context, $intent, $operation, 'menu', $menuId, 'nav_menu', $this->name);

        $this->context->logger()->debug(
            sprintf('Menu rebuilt [%s] as ID %d with %d items.', $this->name, $menuId, count($this->items))
        );

        return new MenuRef($menuId, $this->name);
    }

    /**
     * Find the menu by name or create it.
     *
     * @return int
     *
     * @throws RuntimeException If menu creation fails.
     */
    private function upsertMenu(?int $ownedId = null): int
    {
        if ($ownedId !== null) {
            if (!function_exists('wp_update_nav_menu_object')) {
                throw new RuntimeException('wp_update_nav_menu_object() is required to rename owned menus.');
            }

            $updated = wp_update_nav_menu_object($ownedId, ['menu-name' => $this->name]);
            if (!WpResult::isId($updated)) {
                throw new RuntimeException(sprintf('Failed to update owned menu [%s].', $this->name));
            }

            return (int) $updated;
        }

        if (function_exists('wp_get_nav_menu_object')) {
            $existing = wp_get_nav_menu_object($this->name);

            if (is_object($existing) && isset($existing->term_id)) {
                return (int) $existing->term_id;
            }
        }

        $menuId = wp_create_nav_menu($this->name);

        if (!WpResult::isId($menuId)) {
            throw new RuntimeException(sprintf('Failed to create menu [%s].', $this->name));
        }

        return (int) $menuId;
    }

    /**
     * Remove all current items so declared items fully define the menu.
     *
     * @param int $menuId
     * @param array<int, array<string, mixed>> $items Resolved item declarations.
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
     * Create declared items in order, resolving parent titles to item IDs.
     *
     * Invariant: a parent must be declared before its children, because
     * resolution walks the declaration order once.
     *
     * @param int $menuId
     * @return void
     *
     * @throws RuntimeException If an item save fails.
     */
    private function createItems(int $menuId, array $items): void
    {
        $idsByTitle = [];

        foreach ($items as $position => $item) {
            $itemId = wp_update_nav_menu_item($menuId, 0, $this->buildItemArgs($item, $position + 1, $idsByTitle));

            if (!WpResult::isId($itemId)) {
                throw new RuntimeException(sprintf(
                    'Failed to save menu item [%s] in [%s].',
                    (string) ($item['title'] ?? $item['url'] ?? $item['object_id'] ?? '?'),
                    $this->name
                ));
            }

            $title = (string) ($item['title'] ?? '');
            if ($title !== '') {
                $idsByTitle[$title] = (int) $itemId;
            }
        }
    }

    /**
     * Translate an item spec into `wp_update_nav_menu_item()` args.
     *
     * @param array<string, mixed> $item
     * @param int $position 1-based menu position.
     * @param array<string, int> $idsByTitle Item IDs created so far, keyed by title.
     * @return array<string, mixed>
     */
    private function buildItemArgs(array $item, int $position, array $idsByTitle): array
    {
        $parent = $item['parent'] ?? null;

        $args = [
            'menu-item-status' => 'publish',
            'menu-item-position' => $position,
            'menu-item-parent-id' => is_string($parent) ? ($idsByTitle[$parent] ?? 0) : 0,
        ];

        if ($item['type'] === 'custom') {
            $args['menu-item-type'] = 'custom';
            $args['menu-item-title'] = (string) $item['title'];
            $args['menu-item-url'] = (string) $item['url'];

            return $args;
        }

        $args['menu-item-type'] = (string) $item['type'];
        $args['menu-item-object-id'] = (int) $item['object_id'];

        if (!empty($item['object'])) {
            $args['menu-item-object'] = (string) $item['object'];
        }

        if (!empty($item['title'])) {
            $args['menu-item-title'] = (string) $item['title'];
        }

        return $args;
    }

    /**
     * Resolve logical refs and validate raw relationship metadata at save-time.
     *
     * @return array<int, array<string, mixed>>
     */
    private function resolveItems(): array
    {
        $resolved = [];

        foreach ($this->items as $item) {
            $target = $item['object_id'] ?? null;
            if ($target instanceof LazyRef) {
                $type = $item['type'] === 'taxonomy' ? 'term' : 'post';
                $resource = $target->resolve($type);
                $item['object_id'] = $resource->id();
                $item['object'] = $resource->subtype();
            }

            if ($item['type'] === 'post_type' && empty($item['object'])) {
                throw new LogicException('Menu post items created from raw IDs require an explicit post type.');
            }
            if ($item['type'] === 'taxonomy' && empty($item['object'])) {
                throw new LogicException('Menu term items created from raw IDs require an explicit taxonomy.');
            }

            $resolved[] = $item;
        }

        return $resolved;
    }

    /**
     * Point declared theme locations at this menu via the `nav_menu_locations` theme mod.
     *
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

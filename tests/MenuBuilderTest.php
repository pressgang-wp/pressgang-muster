<?php

namespace PressGang\Muster\Tests;

use PHPUnit\Framework\TestCase;
use PressGang\Muster\Builders\MenuBuilder;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Refs\PostRef;
use PressGang\Muster\Victuals\VictualsFactory;

final class MenuBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        reset_wordpress_stub_state();
    }

    public function testSaveCreatesMenuWithOrderedItems(): void
    {
        $context = new MusterContext(new VictualsFactory());

        $ref = (new MenuBuilder($context, 'Main Menu'))
            ->link('Home', '/')
            ->postItem(new PostRef(12, 'page', 'about'), 'About')
            ->link('Team', '/about/team/', parent: 'About')
            ->location('main-menu')
            ->save();

        self::assertSame(100, $ref->id());

        $items = array_values($GLOBALS['__muster_wp_menu_items'][100]);
        self::assertCount(3, $items);
        self::assertSame('custom', $items[0]['menu-item-type']);
        self::assertSame('/', $items[0]['menu-item-url']);
        self::assertSame(12, $items[1]['menu-item-object-id']);
        self::assertSame('page', $items[1]['menu-item-object']);

        $aboutId = array_keys($GLOBALS['__muster_wp_menu_items'][100])[1];
        self::assertSame($aboutId, $items[2]['menu-item-parent-id']);

        self::assertSame(['main-menu' => 100], $GLOBALS['__muster_wp_theme_mods']['nav_menu_locations']);
    }

    public function testSaveRebuildsItemsIdempotently(): void
    {
        $context = new MusterContext(new VictualsFactory());

        $first = (new MenuBuilder($context, 'Footer'))->link('Privacy', '/privacy/')->save();
        $second = (new MenuBuilder($context, 'Footer'))->link('Privacy', '/privacy/')->save();

        self::assertSame($first->id(), $second->id());
        self::assertCount(1, $GLOBALS['__muster_wp_menu_items'][$first->id()]);
        self::assertNotEmpty($GLOBALS['__muster_wp_deleted_posts']);
    }

    public function testDryRunSkipsMenuWrites(): void
    {
        $context = new MusterContext(new VictualsFactory(), dryRun: true);

        $ref = (new MenuBuilder($context, 'Main Menu'))->link('Home', '/')->save();

        self::assertSame(0, $ref->id());
        self::assertSame([], $GLOBALS['__muster_wp_menus']);
    }
}

<?php

namespace PressGang\Muster\Tests;

use LogicException;
use PHPUnit\Framework\TestCase;
use PressGang\Muster\Muster;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Victuals\VictualsFactory;

final class RefPageMuster extends Muster
{
    public function run(): void
    {
    }
}

final class RefUserMuster extends Muster
{
    public function run(): void
    {
    }
}

final class LazyRefTest extends TestCase
{
    protected function setUp(): void
    {
        reset_wordpress_stub_state();
    }

    public function testBuilderCanCaptureAForwardRefResolvedAtSaveTime(): void
    {
        $muster = new RefPageMuster($this->context());
        $parent = $muster->ref('page:parent');
        $child = $muster->page()
            ->key('page:child')
            ->title('Child')
            ->slug('child')
            ->parent($parent);

        $parentRef = $muster->page()
            ->key('page:parent')
            ->title('Parent')
            ->slug('parent')
            ->save();
        $childRef = $child->save();

        self::assertSame($parentRef->id(), get_post($childRef->id())->post_parent);
    }

    public function testRefResolvesPersistedOwnershipInALaterPass(): void
    {
        $first = new RefPageMuster($this->context());
        $parent = $first->page()->key('page:parent')->title('Parent')->slug('parent')->save();

        $second = new RefPageMuster($this->context());
        $child = $second->page()
            ->key('page:child')
            ->title('Child')
            ->slug('child')
            ->parent($second->ref('page:parent'))
            ->save();

        self::assertSame($parent->id(), get_post($child->id())->post_parent);
    }

    public function testCrossMusterRefRequiresAnExplicitScope(): void
    {
        $users = new RefUserMuster($this->context());
        $user = $users->user('fixture_editor')
            ->key('user:editor')
            ->password('initial-password')
            ->email('editor@example.test')
            ->save();

        $pages = new RefPageMuster($this->context());
        $page = $pages->page()
            ->key('page:owned')
            ->title('Owned')
            ->slug('owned')
            ->author($pages->ref('user:editor', RefUserMuster::class))
            ->save();

        self::assertSame($user->userId(), get_post($page->id())->post_author);
    }

    public function testMenuResolvesARefCapturedBeforeItsTargetExists(): void
    {
        $muster = new RefPageMuster($this->context());
        $menu = $muster->menu('Main Menu')
            ->key('menu:main')
            ->postItem($muster->ref('page:about'), 'About');

        $about = $muster->page()->key('page:about')->title('About')->slug('about')->save();
        $menuRef = $menu->save();

        $items = array_values($GLOBALS['__muster_wp_menu_items'][$menuRef->id()]);
        self::assertSame($about->id(), $items[0]['menu-item-object-id']);
        self::assertSame('page', $items[0]['menu-item-object']);
    }

    public function testDryRunResolvesAPlannedRefWithoutAWordPressId(): void
    {
        $context = new MusterContext(new VictualsFactory(), dryRun: true);
        $muster = new RefPageMuster($context);
        $parent = $muster->ref('page:parent');

        $muster->page()->key('page:parent')->slug('parent')->save();
        $muster->page()->key('page:child')->slug('child')->parent($parent)->save();

        self::assertSame(2, $context->report()->summary()['create']);
        self::assertCount(0, $GLOBALS['__muster_wp_posts']);
    }

    public function testUnresolvedRefFailsAtConsumerSave(): void
    {
        $muster = new RefPageMuster($this->context());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('save its declaration before saving the relationship');

        $muster->page()
            ->key('page:child')
            ->slug('child')
            ->parent($muster->ref('page:missing'))
            ->save();
    }

    public function testRefTypeMismatchFailsLoudly(): void
    {
        $users = new RefUserMuster($this->context());
        $users->user('fixture_editor')
            ->key('user:editor')
            ->password('initial-password')
            ->save();
        $pages = new RefPageMuster($this->context());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('identifies [user], expected [post]');

        $pages->page()
            ->key('page:child')
            ->slug('child')
            ->parent($pages->ref('user:editor', RefUserMuster::class))
            ->save();
    }

    private function context(): MusterContext
    {
        return new MusterContext(new VictualsFactory());
    }
}

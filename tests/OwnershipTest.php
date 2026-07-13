<?php

namespace PressGang\Muster\Tests;

use LogicException;
use PHPUnit\Framework\TestCase;
use PressGang\Muster\Builders\PostBuilder;
use PressGang\Muster\Muster;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Ownership\OwnershipConflict;
use PressGang\Muster\Ownership\OwnershipRegistry;
use PressGang\Muster\Victuals\VictualsFactory;

final class OwnedSiteMuster extends Muster
{
    public function run(): void
    {
    }
}

final class OtherOwnedSiteMuster extends Muster
{
    public function run(): void
    {
    }
}

final class OwnershipTest extends TestCase
{
    protected function setUp(): void
    {
        reset_wordpress_stub_state();
    }

    public function testMusterBuildersRequireLogicalKeys(): void
    {
        $this->expectException(LogicException::class);

        $this->muster()->page()->title('About')->slug('about')->save();
    }

    public function testLogicalKeyKeepsPostIdentityWhenSlugChanges(): void
    {
        $muster = $this->muster();

        $first = $muster->page()
            ->key('about-page')
            ->title('About')
            ->slug('about')
            ->save();

        $renamed = $muster->page()
            ->key('about-page')
            ->title('About us')
            ->slug('about-us')
            ->save();

        self::assertSame($first->id(), $renamed->id());
        self::assertSame([], get_posts(['name' => 'about', 'post_type' => 'page']));
        self::assertSame([$first->id()], get_posts(['name' => 'about-us', 'post_type' => 'page']));
    }

    public function testLogicalKeyKeepsTermIdentityWhenSlugChanges(): void
    {
        $muster = $this->muster();

        $first = $muster->term('category')
            ->key('featured-category')
            ->name('Featured')
            ->slug('featured')
            ->save();

        $renamed = $muster->term('category')
            ->key('featured-category')
            ->name('Highlights')
            ->slug('highlights')
            ->save();

        self::assertSame($first->termId(), $renamed->termId());
        self::assertFalse(get_term_by('slug', 'featured', 'category'));
        self::assertSame($first->termId(), get_term_by('slug', 'highlights', 'category')->term_id);
    }

    public function testUnownedNaturalKeyCollisionRequiresExplicitAdoption(): void
    {
        $context = $this->context();
        (new PostBuilder($context, 'page'))->title('Existing')->slug('about')->save();

        $this->expectException(OwnershipConflict::class);

        (new OwnedSiteMuster($context))->page()
            ->key('about-page')
            ->title('Owned')
            ->slug('about')
            ->save();
    }

    public function testAdoptionClaimsAnExistingUnownedResource(): void
    {
        $context = $this->context();
        $existing = (new PostBuilder($context, 'page'))->title('Existing')->slug('about')->save();

        $adopted = (new OwnedSiteMuster($context))->page()
            ->key('about-page')
            ->adopt()
            ->title('Owned')
            ->slug('about')
            ->save();

        self::assertSame($existing->id(), $adopted->id());
        self::assertSame('Owned', $GLOBALS['__muster_wp_posts']['page::about']['post_title']);
    }

    public function testAdoptionCannotStealAnotherMustersResource(): void
    {
        $context = $this->context();
        (new OwnedSiteMuster($context))->page()
            ->key('about-page')
            ->title('About')
            ->slug('about')
            ->save();

        $this->expectException(OwnershipConflict::class);

        (new OtherOwnedSiteMuster($context))->page()
            ->key('stolen-page')
            ->adopt()
            ->title('Stolen')
            ->slug('about')
            ->save();
    }

    public function testAttachmentCannotBeClaimedAgainThroughPostBuilder(): void
    {
        $muster = $this->muster();
        $muster->attachment('fixture-image')->key('image')->placeholder(8, 8)->save();

        $this->expectException(OwnershipConflict::class);

        $muster->post('attachment')
            ->key('shadow-image')
            ->adopt()
            ->slug('fixture-image')
            ->save();
    }

    public function testResetDeletesAllAndOnlyOwnedResources(): void
    {
        $context = $this->context();
        $muster = new OwnedSiteMuster($context);

        (new PostBuilder($context, 'page'))->title('Manual')->slug('manual')->save();
        $muster->page()->key('about')->title('About')->slug('about')->save();
        $muster->term('category')->key('featured')->name('Featured')->slug('featured')->save();
        $muster->user('fixture-editor')->key('editor')->email('editor@example.test')->save();
        $muster->option('fixture_mode')->key('mode')->value('on')->save();
        $muster->menu('Fixture Menu')->key('menu')->link('Home', '/')->save();
        $muster->attachment('fixture-image')->key('image')->placeholder(8, 8)->save();

        self::assertSame(6, $muster->resetOwned());
        self::assertSame(6, $context->report()->summary()['create']);
        self::assertSame(6, $context->report()->summary()['prune']);
        self::assertSame([1], get_posts(['name' => 'manual', 'post_type' => 'page']));
        self::assertSame([], get_posts(['name' => 'about', 'post_type' => 'page']));
        self::assertFalse(get_term_by('slug', 'featured', 'category'));
        self::assertFalse(get_user_by('login', 'fixture-editor'));
        self::assertFalse(get_option('fixture_mode', false));
        self::assertFalse(wp_get_nav_menu_object('Fixture Menu'));
        self::assertSame([], get_posts(['name' => 'fixture-image', 'post_type' => 'attachment']));
        self::assertSame([], get_option(OwnershipRegistry::OPTION, []));
    }

    public function testPruneDeletesOwnedResourcesOutsideAllowlist(): void
    {
        $muster = $this->muster();
        $muster->page()->key('keep')->title('Keep')->slug('keep')->save();
        $muster->page()->key('remove')->title('Remove')->slug('remove')->save();

        $nextRun = $this->muster();
        $nextRun->page()->key('keep')->title('Keep')->slug('keep')->save();

        self::assertSame(1, $nextRun->pruneOwned());
        self::assertSame([1], get_posts(['name' => 'keep', 'post_type' => 'page']));
        self::assertSame([], get_posts(['name' => 'remove', 'post_type' => 'page']));
    }

    public function testPruneRetainsAnExplicitConditionalKey(): void
    {
        $muster = $this->muster();
        $muster->page()->key('conditional')->title('Conditional')->slug('conditional')->save();

        $nextRun = $this->muster();

        self::assertSame(0, $nextRun->pruneOwned(['conditional']));
        self::assertSame([1], get_posts(['name' => 'conditional', 'post_type' => 'page']));
    }

    public function testResetReconcilesARegistryEntryWhoseResourceWasAlreadyDeleted(): void
    {
        $muster = $this->muster();
        $ref = $muster->page()->key('about')->title('About')->slug('about')->save();
        wp_delete_post($ref->id(), true);

        self::assertSame(1, $muster->resetOwned());
        self::assertSame([], get_option(OwnershipRegistry::OPTION, []));
    }

    public function testDryRunResetReportsSelectionWithoutDeleting(): void
    {
        $muster = $this->muster();
        $muster->page()->key('about')->title('About')->slug('about')->save();

        $dryContext = new MusterContext(new VictualsFactory(), dryRun: true);
        $dryRun = new OwnedSiteMuster($dryContext);

        self::assertSame(1, $dryRun->resetOwned());
        self::assertSame(1, $dryContext->report()->summary()['prune']);
        self::assertSame([1], get_posts(['name' => 'about', 'post_type' => 'page']));
        self::assertArrayHasKey(OwnedSiteMuster::class, get_option(OwnershipRegistry::OPTION, []));
    }

    private function muster(): OwnedSiteMuster
    {
        return new OwnedSiteMuster($this->context());
    }

    private function context(): MusterContext
    {
        return new MusterContext(new VictualsFactory(), seed: 1978);
    }
}

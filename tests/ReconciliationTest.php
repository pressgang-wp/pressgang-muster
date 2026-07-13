<?php

namespace PressGang\Muster\Tests;

use PHPUnit\Framework\TestCase;
use PressGang\Muster\Builders\PostBuilder;
use PressGang\Muster\Cli\Invoker;
use PressGang\Muster\Muster;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Victuals\VictualsFactory;

final class ReconciliationMuster extends Muster
{
    public function run(): void
    {
        $title = (string) ($GLOBALS['__muster_reconciliation_title'] ?? 'About');
        $this->page()->key('page:about')->title($title)->slug('about')->save();
    }
}

final class ConflictingReconciliationMuster extends Muster
{
    public function run(): void
    {
        $this->page()->key('page:about')->title('Managed')->slug('about')->save();
    }
}

final class AllResourceReconciliationMuster extends Muster
{
    public function run(): void
    {
        $this->page()->key('page')->title('Page')->slug('page')->save();
        $this->term('category')->key('term')->name('Term')->slug('term')->save();
        $this->user('fixture-user')->key('user')->password('fixture-password')->email('fixture@example.test')->save();
        $this->option('fixture_option')->key('option')->value('value')->save();
        $this->attachment('fixture-image')->key('attachment')->placeholder(8, 8)->save();
        $this->menu('Fixture Menu')->key('menu')->link('Home', '/')->save();
    }
}

final class ReconciliationTest extends TestCase
{
    protected function setUp(): void
    {
        reset_wordpress_stub_state();
        $GLOBALS['__muster_reconciliation_title'] = 'About';
    }

    public function testPlanningReportsCreateWithoutWriting(): void
    {
        $context = $this->context(dryRun: true);
        (new ReconciliationMuster($context))->run();

        self::assertSame([], $GLOBALS['__muster_wp_posts']);
        self::assertSame([
            'create' => 1,
            'update' => 0,
            'keep' => 0,
            'prune' => 0,
            'conflict' => 0,
        ], $context->report()->summary());
    }

    public function testApplicationThenRepeatReportsCreateThenKeep(): void
    {
        $first = $this->context();
        (new ReconciliationMuster($first))->run();

        $second = $this->context();
        (new ReconciliationMuster($second))->run();

        self::assertSame(1, $first->report()->summary()['create']);
        self::assertSame(1, $second->report()->summary()['keep']);
        self::assertCount(1, $GLOBALS['__muster_wp_posts']);
    }

    public function testChangedDeclarationReportsUpdate(): void
    {
        (new ReconciliationMuster($this->context()))->run();

        $GLOBALS['__muster_reconciliation_title'] = 'About us';
        $changed = $this->context();
        (new ReconciliationMuster($changed))->run();

        self::assertSame(1, $changed->report()->summary()['update']);
    }

    public function testFreshPlanShowsPruneThenRecreateWithoutWriting(): void
    {
        (new ReconciliationMuster($this->context()))->run();
        $original = $GLOBALS['__muster_wp_posts'];

        $plan = $this->context(dryRun: true);
        $muster = new ReconciliationMuster($plan);
        $muster->resetOwned();
        $muster->run();

        self::assertSame($original, $GLOBALS['__muster_wp_posts']);
        self::assertSame(1, $plan->report()->summary()['prune']);
        self::assertSame(1, $plan->report()->summary()['create']);
    }

    public function testDuplicateLogicalKeyPlansOneCreateThenKeep(): void
    {
        $context = $this->context(dryRun: true);
        $muster = new ReconciliationMuster($context);

        $muster->page()->key('page:shared')->title('Shared')->slug('shared')->save();
        $muster->page()->key('page:shared')->title('Shared')->slug('shared')->save();

        self::assertSame(1, $context->report()->summary()['create']);
        self::assertSame(1, $context->report()->summary()['keep']);
    }

    public function testConflictStopsApplicationAndAppearsInReport(): void
    {
        $context = $this->context();
        (new PostBuilder($context, 'page'))->title('Editorial')->slug('about')->save();

        $result = Invoker::reconcile(ConflictingReconciliationMuster::class, ['dry-run' => true]);

        self::assertNotNull($result['error']);
        self::assertSame(1, $result['plan']->summary()['conflict']);
        self::assertNull($result['apply']);
        self::assertSame('Editorial', $GLOBALS['__muster_wp_posts']['page::about']['post_title']);
    }

    public function testEveryResourceBuilderReportsAndComparableRepeatsKeep(): void
    {
        $first = $this->context();
        (new AllResourceReconciliationMuster($first))->run();

        $second = $this->context();
        (new AllResourceReconciliationMuster($second))->run();

        self::assertSame(6, $first->report()->summary()['create']);
        self::assertSame(5, $second->report()->summary()['keep']);
        self::assertSame(1, $second->report()->summary()['update']);
    }

    private function context(bool $dryRun = false): MusterContext
    {
        return new MusterContext(new VictualsFactory(), seed: 1978, dryRun: $dryRun);
    }
}

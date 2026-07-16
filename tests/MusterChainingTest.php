<?php

namespace PressGang\Muster\Tests;

use LogicException;
use PHPUnit\Framework\TestCase;
use PressGang\Muster\Clock\FixtureClock;
use PressGang\Muster\Muster;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Victuals\VictualsFactory;

final class ChainedFirstMuster extends Muster
{
    public function run(): void
    {
        $GLOBALS['__muster_chain'][] = ['first', $this->epoch()->format(DATE_ATOM)];
        $this->option('chain_first')->key('option:chain:first')->value(true)->save();
    }
}

final class ChainedSecondMuster extends Muster
{
    public function run(): void
    {
        $GLOBALS['__muster_chain'][] = ['second', $this->epoch()->format(DATE_ATOM)];
        $this->option('chain_second')->key('option:chain:second')->value(true)->save();
    }
}

final class ChainedRootMuster extends Muster
{
    public function run(): void
    {
        $this->call(ChainedFirstMuster::class, ChainedSecondMuster::class);
    }
}

final class RecursiveFirstMuster extends Muster
{
    public function run(): void
    {
        $this->call(RecursiveSecondMuster::class);
    }
}

final class RecursiveSecondMuster extends Muster
{
    public function run(): void
    {
        $this->call(RecursiveFirstMuster::class);
    }
}

final class AcfSharedChildOne extends Muster
{
    public function run(): void
    {
        $this->acfFor('event');
    }
}

final class AcfSharedChildTwo extends Muster
{
    public function run(): void
    {
        $this->acfFor('event');
    }
}

final class AcfSharedRootMuster extends Muster
{
    public function run(): void
    {
        $this->call(AcfSharedChildOne::class, AcfSharedChildTwo::class);
    }
}

final class MusterChainingTest extends TestCase
{
    protected function setUp(): void
    {
        reset_wordpress_stub_state();
        $GLOBALS['__muster_chain'] = [];
    }

    public function testChainedMustersShareAcfSupportResourcesInsteadOfColliding(): void
    {
        // A field group with a media field, so acfFor() must create a
        // placeholder attachment. Two chained Musters generate values for the
        // same target: the shared support attachment has to be created once and
        // reused, not owned twice — otherwise the second call throws an
        // ownership conflict on the resource the first created.
        $dir = $GLOBALS['__muster_stylesheet_dir'] . '/acf-json';
        @mkdir($dir, 0755, true);
        file_put_contents($dir . '/group_event.json', json_encode([
            'key' => 'group_event',
            'title' => 'Event',
            'fields' => [['key' => 'field_hero', 'name' => 'hero', 'type' => 'image']],
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'event']]],
        ]));

        $context = new MusterContext(new VictualsFactory(), seed: 42);

        (new AcfSharedRootMuster($context))->run();

        self::assertCount(
            1,
            get_posts(['name' => 'seed-hero', 'post_type' => 'attachment', 'post_status' => 'any'])
        );
    }

    public function testCallRunsDependenciesInOrderWithTheSharedContext(): void
    {
        $clock = new FixtureClock('2026-01-01 09:00:00+00:00');
        $context = new MusterContext(new VictualsFactory(), clock: $clock);

        (new ChainedRootMuster($context))->run();

        self::assertSame([
            ['first', '2026-01-01T09:00:00+00:00'],
            ['second', '2026-01-01T09:00:00+00:00'],
        ], $GLOBALS['__muster_chain']);
        self::assertSame(2, $context->report()->summary()['create']);
    }

    public function testRecursiveCallReportsTheDependencyPath(): void
    {
        $context = new MusterContext(new VictualsFactory());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            RecursiveFirstMuster::class . ' -> ' . RecursiveSecondMuster::class . ' -> ' . RecursiveFirstMuster::class
        );

        (new RecursiveFirstMuster($context))->run();
    }

    public function testDuplicateDependencyFailsLoudly(): void
    {
        $context = new MusterContext(new VictualsFactory());
        $root = new class($context) extends Muster {
            public function run(): void
            {
                $this->call(ChainedFirstMuster::class, ChainedFirstMuster::class);
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('was called more than once');

        $root->run();
    }

    public function testCallRejectsNonMusterClasses(): void
    {
        $context = new MusterContext(new VictualsFactory());
        $root = new class($context) extends Muster {
            public function run(): void
            {
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must extend');

        $root->call(\stdClass::class);
    }
}

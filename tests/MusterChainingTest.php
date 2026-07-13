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

final class MusterChainingTest extends TestCase
{
    protected function setUp(): void
    {
        reset_wordpress_stub_state();
        $GLOBALS['__muster_chain'] = [];
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

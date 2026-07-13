<?php

namespace PressGang\Muster\Tests;

use LogicException;
use PHPUnit\Framework\TestCase;
use PressGang\Muster\Muster;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Victuals\VictualsFactory;

final class MusterGroupTest extends TestCase
{
    protected function setUp(): void
    {
        reset_wordpress_stub_state();
    }

    public function testOnlyInvokesSelectedGroupCallback(): void
    {
        $context = new MusterContext(new VictualsFactory(), onlyGroups: ['selected']);
        $muster = new class ($context) extends Muster {
            public int $selected = 0;

            public int $skipped = 0;

            public function run(): void
            {
                $this->group('selected', function (): void {
                    $this->selected++;
                    $this->page()->key('page:selected')->title('Selected')->slug('selected')->save();
                });

                $this->group('skipped', function (): void {
                    $this->skipped++;
                    $this->page()->key('page:skipped')->title('Skipped')->slug('skipped')->save();
                });
            }
        };

        $muster->run();
        $context->scope()->assertOnlyGroupsResolved();

        self::assertSame(1, $muster->selected);
        self::assertSame(0, $muster->skipped);
        self::assertCount(1, $GLOBALS['__muster_wp_posts']);
        self::assertSame('selected', $context->report()->operations()[0]->toArray()['group']);
    }

    public function testPartialRunRejectsUngroupedDeclarations(): void
    {
        $context = new MusterContext(new VictualsFactory(), onlyGroups: ['content']);
        $muster = new class ($context) extends Muster {
            public function run(): void
            {
                $this->page();
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('outside a named group');

        $muster->run();
    }

    public function testUnknownOnlyGroupFailsAfterDeclarationsAreDiscovered(): void
    {
        $context = new MusterContext(new VictualsFactory(), onlyGroups: ['missing']);
        $muster = new class ($context) extends Muster {
            public function run(): void
            {
                $this->group('content', function (): void {
                    throw new \RuntimeException('A skipped callback must not execute.');
                });
            }
        };

        $muster->run();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Unknown Muster group requested by --only: missing. Available groups: content.'
        );

        $context->scope()->assertOnlyGroupsResolved();
    }

    public function testGroupsCannotBeNested(): void
    {
        $context = new MusterContext(new VictualsFactory());
        $muster = new class ($context) extends Muster {
            public function run(): void
            {
                $this->group('outer', function (): void {
                    $this->group('inner', static function (): void {
                    });
                });
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cannot be nested');

        $muster->run();
    }

    public function testGroupNamesMustBeUniqueWithinPass(): void
    {
        $context = new MusterContext(new VictualsFactory());
        $muster = new class ($context) extends Muster {
            public function run(): void
            {
                $this->group('content', static function (): void {
                });
                $this->group('content', static function (): void {
                });
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('declared more than once');

        $muster->run();
    }

    public function testPruneOwnedRejectsPartialRun(): void
    {
        $context = new MusterContext(new VictualsFactory(), onlyGroups: ['content']);
        $muster = new class ($context) extends Muster {
            public function run(): void
            {
                $this->pruneOwned();
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires a complete Muster run');

        $muster->run();
    }

    public function testResetOwnedDeclarationRejectsPartialRun(): void
    {
        $context = new MusterContext(new VictualsFactory(), onlyGroups: ['content']);
        $muster = new class ($context) extends Muster {
            public function run(): void
            {
                $this->resetOwned();
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('resetOwned() requires a complete Muster run');

        $muster->run();
    }
}

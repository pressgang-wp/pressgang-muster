<?php

namespace PressGang\Muster\Tests;

use LogicException;
use PHPUnit\Framework\TestCase;
use PressGang\Muster\Muster;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Patterns\Sequence;
use PressGang\Muster\Victuals\VictualsFactory;

final class SequenceTest extends TestCase
{
    protected function setUp(): void
    {
        reset_wordpress_stub_state();
    }

    public function testSequenceCyclesFromTheOneBasedIterationIndex(): void
    {
        $sequence = new Sequence('draft', 'publish');

        self::assertSame('draft', $sequence->at(1));
        self::assertSame('publish', $sequence->at(2));
        self::assertSame('draft', $sequence->at(3));
        self::assertSame(2, $sequence->length());
    }

    public function testSequenceProducesRepeatablePatternValues(): void
    {
        $muster = new class(new MusterContext(new VictualsFactory())) extends Muster {
            public function run(): void
            {
            }
        };
        $statuses = $muster->sequence('draft', 'publish');

        $muster->pattern('sequenced-posts')->count(3)->build(
            fn (int $i) => $muster->post()
                ->key('post:' . $i)
                ->slug('post-' . $i)
                ->status($statuses->at($i))
        );

        self::assertSame('draft', $GLOBALS['__muster_wp_posts']['post::post-1']['post_status']);
        self::assertSame('publish', $GLOBALS['__muster_wp_posts']['post::post-2']['post_status']);
        self::assertSame('draft', $GLOBALS['__muster_wp_posts']['post::post-3']['post_status']);
    }

    public function testSequenceRejectsAnEmptyCycle(): void
    {
        $this->expectException(LogicException::class);

        new Sequence();
    }
}

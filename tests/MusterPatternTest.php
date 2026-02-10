<?php


namespace PressGang\Muster\Tests;

use BadMethodCallException;
use LogicException;
use PHPUnit\Framework\TestCase;
use PressGang\Muster\Builders\PostBuilder;
use PressGang\Muster\Muster;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Victuals\VictualsFactory;

final class MusterPatternTest extends TestCase
{
    protected function setUp(): void
    {
        reset_wordpress_stub_state();
    }

    public function testPatternBuildRequiresExplicitCount(): void
    {
        $muster = $this->makeMuster(new MusterContext(new VictualsFactory(), seed: 100));

        $this->expectException(LogicException::class);

        $muster->pattern('event')->build(
            fn (int $i): PostBuilder => $muster->post('event')->slug('event-' . $i)
        );
    }

    public function testPatternSeedProducesDeterministicSequenceAcrossRuns(): void
    {
        $muster = $this->makeMuster(new MusterContext(new VictualsFactory(), seed: 100));

        $runA = [];
        $muster->pattern('event')
            ->count(3)
            ->seed(1978)
            ->build(function (int $i) use (&$runA, $muster): PostBuilder {
                $headline = $muster->victuals()->headline();
                $runA[] = $headline;

                return $muster->post('event')
                    ->title($headline)
                    ->slug('event-' . $i)
                    ->status('publish');
            });

        $runB = [];
        $muster->pattern('event')
            ->count(3)
            ->seed(1978)
            ->build(function (int $i) use (&$runB, $muster): PostBuilder {
                $headline = $muster->victuals()->headline();
                $runB[] = $headline;

                return $muster->post('event')
                    ->title($headline)
                    ->slug('event-' . $i)
                    ->status('publish');
            });

        self::assertSame($runA, $runB);
        self::assertCount(3, $GLOBALS['__muster_wp_posts']);
    }

    public function testMagicCallResolvesRegisteredPostType(): void
    {
        $muster = $this->makeMuster(new MusterContext(new VictualsFactory()));

        $builder = $muster->event('Magic Event');

        self::assertInstanceOf(PostBuilder::class, $builder);

        $ref = $builder->slug('magic-event')->save();

        self::assertSame('event', $ref->postType());
        self::assertSame('magic-event', $ref->slug());
    }

    public function testMagicCallThrowsForUnknownPostType(): void
    {
        $muster = $this->makeMuster(new MusterContext(new VictualsFactory()));

        $this->expectException(BadMethodCallException::class);

        $muster->unknownType('Nope');
    }

    private function makeMuster(MusterContext $context): Muster
    {
        return new class($context) extends Muster {
            public function run(): void
            {
            }
        };
    }
}

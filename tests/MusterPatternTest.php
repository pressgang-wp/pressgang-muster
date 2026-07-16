<?php


namespace PressGang\Muster\Tests;

use BadMethodCallException;
use LogicException;
use PHPUnit\Framework\TestCase;
use PressGang\Muster\Builders\PostBuilder;
use PressGang\Muster\Builders\TermBuilder;
use PressGang\Muster\Muster;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Victuals\VictualsFactory;
use UnexpectedValueException;

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
            fn (int $i): PostBuilder => $muster->post('event')->key('event-' . $i)->slug('event-' . $i)
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
                    ->key('event-' . $i)
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
                    ->key('event-' . $i)
                    ->title($headline)
                    ->slug('event-' . $i)
                    ->status('publish');
            });

        self::assertSame($runA, $runB);
        self::assertCount(3, $GLOBALS['__muster_wp_posts']);
    }

    public function testPatternPersistsAnyDeclarationContract(): void
    {
        $muster = $this->makeMuster(new MusterContext(new VictualsFactory(), seed: 100));

        $muster->pattern('event-types')
            ->count(2)
            ->build(
                fn (int $i): TermBuilder => $muster->term('event_type')
                    ->key('event-type:' . $i)
                    ->name('Type ' . $i)
                    ->slug('type-' . $i)
            );

        self::assertCount(2, $GLOBALS['__muster_wp_terms']);
    }

    public function testPatternFailsLoudlyForAnUnsupportedReturnValue(): void
    {
        $muster = $this->makeMuster(new MusterContext(new VictualsFactory()));

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('iteration 1 must return PersistableDeclaration; received [stdClass]');

        $muster->pattern('invalid')->count(1)->build(fn (int $i): object => new \stdClass());
    }

    public function testMagicCallResolvesRegisteredPostType(): void
    {
        $muster = $this->makeMuster(new MusterContext(new VictualsFactory()));

        $builder = $muster->event('Magic Event');

        self::assertInstanceOf(PostBuilder::class, $builder);

        $ref = $builder->key('magic-event')->slug('magic-event')->save();

        self::assertSame('event', $ref->postType());
        self::assertSame('magic-event', $ref->slug());
    }

    public function testMagicCallThrowsForUnknownPostType(): void
    {
        $muster = $this->makeMuster(new MusterContext(new VictualsFactory()));

        $this->expectException(BadMethodCallException::class);

        $muster->unknownType('Nope');
    }

    public function testPatternRowsSelfKeyWhenNoKeyIsGiven(): void
    {
        $muster = $this->makeMuster(new MusterContext(new VictualsFactory(), seed: 100));

        // No ->key() in the recipe: without an auto-key the ownership contract
        // would throw, so the pattern must supply a stable default (hit:1..3).
        $muster->pattern('hit')->count(3)->build(
            fn (int $i): PostBuilder => $muster->post('post')->slug('hit-' . $i)->status('publish')
        );

        self::assertCount(3, $GLOBALS['__muster_wp_posts']);
        // All three were recorded as owned via their auto-keys, so reset finds them.
        self::assertSame(3, $muster->resetOwned());
    }

    public function testWithThumbnailFeaturesAPlaceholderOnEveryRow(): void
    {
        $muster = $this->makeMuster(new MusterContext(new VictualsFactory(), seed: 100));

        $muster->pattern('hit')->count(2)->withThumbnail(8, 8)->build(
            fn (int $i): PostBuilder => $muster->post('post')->slug('hit-' . $i)->status('publish')
        );

        // Each of the two rows received a featured image (self-keyed attachment).
        self::assertCount(2, $GLOBALS['__muster_wp_thumbnails']);
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

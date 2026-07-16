<?php

namespace PressGang\Muster\Tests;

use LogicException;
use PHPUnit\Framework\TestCase;
use PressGang\Muster\Builders\TermBuilder;
use PressGang\Muster\Contracts\PersistableDeclaration;
use PressGang\Muster\Muster;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Victuals\VictualsFactory;
use UnexpectedValueException;

final class RecipeTest extends TestCase
{
    protected function setUp(): void
    {
        reset_wordpress_stub_state();
    }

    public function testRecipeCanBeReusedWithAnIsolatedNamedState(): void
    {
        $muster = $this->muster();
        $recipe = $muster->recipe(
            'event-type',
            fn (int $i): TermBuilder => $muster->term('event_type')
                ->key('event-type:' . $i)
                ->name('Type ' . $i)
                ->slug('type-' . $i)
        )->state(
            'described',
            fn (PersistableDeclaration $term, int $i): PersistableDeclaration => $term->description('Description ' . $i)
        );

        $muster->pattern('described-types')->count(2)->using($recipe->with('described'));

        self::assertSame('Description 1', $GLOBALS['__muster_wp_terms']['event_type::type-1']['description']);
        self::assertSame('Description 2', $GLOBALS['__muster_wp_terms']['event_type::type-2']['description']);

        $plain = $recipe->make(3);
        self::assertInstanceOf(TermBuilder::class, $plain);
    }

    public function testUnknownStateFailsBeforeThePatternRuns(): void
    {
        $muster = $this->muster();
        $recipe = $muster->recipe(
            'event-type',
            fn (int $i): TermBuilder => $muster->term('event_type')->key('type:' . $i)->name('Type')
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('has no state [missing]');

        $recipe->with('missing');
    }

    public function testInvalidStateResultFailsLoudly(): void
    {
        $muster = $this->muster();
        $recipe = $muster->recipe(
            'event-type',
            fn (int $i): TermBuilder => $muster->term('event_type')->key('type:' . $i)->name('Type')
        )->state('invalid', fn (PersistableDeclaration $term, int $i): object => new \stdClass());

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('state [invalid] must return PersistableDeclaration');

        $recipe->with('invalid')->make(1);
    }

    private function muster(): Muster
    {
        return new class(new MusterContext(new VictualsFactory())) extends Muster {
            public function run(): void
            {
            }
        };
    }
}

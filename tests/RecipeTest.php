<?php

namespace PressGang\Muster\Tests;

use LogicException;
use PHPUnit\Framework\TestCase;
use PressGang\Muster\Builders\TermBuilder;
use PressGang\Muster\Muster;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Patterns\Recipe;
use PressGang\Muster\Victuals\VictualsFactory;

/**
 * A reusable recipe for one taxonomy term, with a composable named state.
 */
final class EventTypeRecipe extends Recipe
{
    public function define(int $iteration): TermBuilder
    {
        return $this->muster->term('event_type')
            ->name('Type ' . $iteration)
            ->slug('type-' . $iteration);
    }

    public function described(): static
    {
        return $this->state(
            fn (TermBuilder $term, int $i): TermBuilder => $term->description('Description ' . $i)
        );
    }
}

final class RecipeTest extends TestCase
{
    protected function setUp(): void
    {
        reset_wordpress_stub_state();
    }

    public function testCreateSeedsCountSelfKeyedRows(): void
    {
        $muster = $this->muster();

        $muster->recipe(EventTypeRecipe::class)->count(3)->create();

        self::assertCount(3, $GLOBALS['__muster_wp_terms']);
    }

    public function testStatesComposeAndApply(): void
    {
        $muster = $this->muster();

        $described = $muster->recipe(EventTypeRecipe::class)->described();
        $muster->pattern('described-types')->count(2)->using($described);

        self::assertSame('Description 1', $GLOBALS['__muster_wp_terms']['event_type::type-1']['description']);
        self::assertSame('Description 2', $GLOBALS['__muster_wp_terms']['event_type::type-2']['description']);
    }

    public function testMakeBuildsWithoutPersistingAndWithoutInactiveStates(): void
    {
        $muster = $this->muster();

        $plain = $muster->recipe(EventTypeRecipe::class)->make(3);

        self::assertInstanceOf(TermBuilder::class, $plain);
        self::assertCount(0, $GLOBALS['__muster_wp_terms']);
    }

    public function testRecipeRejectsNonRecipeClasses(): void
    {
        $muster = $this->muster();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('expects a');

        $muster->recipe(\stdClass::class);
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

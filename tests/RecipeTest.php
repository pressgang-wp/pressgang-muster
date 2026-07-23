<?php

namespace PressGang\Muster\Tests;

use LogicException;
use PHPUnit\Framework\TestCase;
use PressGang\Muster\Builders\PostBuilder;
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
            ->slug($this->slugFor($iteration));
    }

    public function described(): static
    {
        return $this->state(
            fn (TermBuilder $term, int $i): TermBuilder => $term->description('Description ' . $i)
        );
    }
}

/**
 * A post recipe whose body declares its fields as a WP-native `fill()` array
 * (ADR 0010) rather than chained setters — proving `fill()` needs no Recipe
 * wiring, since `define()` already returns a builder.
 */
final class FeaturedEventRecipe extends Recipe
{
    public function define(int $iteration): PostBuilder
    {
        return $this->post('event')
            ->slug($this->slugFor($iteration))
            ->fill([
                'post_title'  => 'Event ' . $iteration,
                'post_status' => 'publish',
                'meta_input'  => ['featured' => true],
            ]);
    }
}

final class RecipeTest extends TestCase
{
    protected function setUp(): void
    {
        reset_wordpress_stub_state();
    }

    public function testDefineCanDeclareFieldsWithAFillArray(): void
    {
        $muster = $this->muster();

        $muster->recipe(FeaturedEventRecipe::class)->count(2)->create();

        $events = array_filter(
            $GLOBALS['__muster_wp_posts'],
            static fn (array $p): bool => ($p['post_type'] ?? '') === 'event'
        );
        self::assertCount(2, $events);
        self::assertSame('publish', reset($events)['post_status']);
        self::assertTrue($GLOBALS['__muster_wp_meta'][1]['featured']);
        self::assertTrue($GLOBALS['__muster_wp_meta'][2]['featured']);
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

        self::assertSame('Description 1', $GLOBALS['__muster_wp_terms']['event_type::eventtype-1']['description']);
        self::assertSame('Description 2', $GLOBALS['__muster_wp_terms']['event_type::eventtype-2']['description']);
    }

    public function testNamedGivesTheBatchADistinctIdentity(): void
    {
        $muster = $this->muster();

        // The default batch and a `named()` batch of the same recipe coexist
        // rather than colliding — the identity a test scenario relies on.
        $muster->recipe(EventTypeRecipe::class)->count(1)->create();
        $muster->recipe(EventTypeRecipe::class)->named('special')->count(1)->create();

        self::assertArrayHasKey('event_type::eventtype-1', $GLOBALS['__muster_wp_terms']);
        self::assertArrayHasKey('event_type::special-1', $GLOBALS['__muster_wp_terms']);
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

<?php

namespace PressGang\Muster\Tests;

use LogicException;
use PHPUnit\Framework\TestCase;
use PressGang\Muster\Builders\TermBuilder;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Victuals\VictualsFactory;

final class TermBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        reset_wordpress_stub_state();
    }

    public function testSaveInsertsTermWhenMissing(): void
    {
        $context = new MusterContext(new VictualsFactory());

        $ref = (new TermBuilder($context, 'category'))
            ->name('Featured')
            ->slug('featured')
            ->description('Featured terms')
            ->meta(['priority' => 1])
            ->save();

        self::assertSame(1, $ref->termId());
        self::assertSame('category', $ref->taxonomy());
        self::assertSame('featured', $ref->slug());
        self::assertSame(1, $GLOBALS['__muster_wp_term_meta'][1]['priority']);
    }

    public function testSaveUpdatesTermWhenExisting(): void
    {
        $context = new MusterContext(new VictualsFactory());

        $first = (new TermBuilder($context, 'category'))
            ->name('Original')
            ->slug('same')
            ->description('One')
            ->save();

        $second = (new TermBuilder($context, 'category'))
            ->name('Original')
            ->slug('same')
            ->description('Two')
            ->save();

        self::assertSame($first->termId(), $second->termId());
        self::assertCount(1, $GLOBALS['__muster_wp_terms']);
    }

    public function testUpdatePreservesFieldsThatWereNotSupplied(): void
    {
        $context = new MusterContext(new VictualsFactory());

        (new TermBuilder($context, 'category'))
            ->name('Original name')
            ->slug('merge-term')
            ->description('Keep this description')
            ->parent(9)
            ->save();

        (new TermBuilder($context, 'category'))
            ->name('Updated name')
            ->slug('merge-term')
            ->save();

        $stored = $GLOBALS['__muster_wp_terms']['category::merge-term'];
        self::assertSame('Updated name', $stored['name']);
        self::assertSame('Keep this description', $stored['description']);
        self::assertSame(9, $stored['parent']);
    }

    public function testDryRunSkipsTermWrites(): void
    {
        $context = new MusterContext(new VictualsFactory(), dryRun: true);

        $ref = (new TermBuilder($context, 'category'))
            ->name('No Write')
            ->slug('no-write')
            ->save();

        self::assertSame(0, $ref->termId());
        self::assertCount(0, $GLOBALS['__muster_wp_terms']);
    }

    public function testFillAppliesWpInsertTermArgsLikeTheFluentSetters(): void
    {
        $context = new MusterContext(new VictualsFactory());

        // The same result as testSaveInsertsTermWhenMissing, declared as data.
        $ref = (new TermBuilder($context, 'category'))->fill([
            'name'        => 'Featured',
            'slug'        => 'featured',
            'description' => 'Featured terms',
            'meta_input'  => ['priority' => 1],
        ])->save();

        self::assertSame('category', $ref->taxonomy());
        self::assertSame('featured', $ref->slug());
        self::assertSame(1, $GLOBALS['__muster_wp_term_meta'][$ref->termId()]['priority']);
    }

    public function testFillMergesWithFluentSettersLastWriteWins(): void
    {
        $context = new MusterContext(new VictualsFactory());

        (new TermBuilder($context, 'category'))
            ->fill(['slug' => 'merged', 'name' => 'Data name'])
            ->name('Setter name')
            ->save();

        self::assertSame('Setter name', $GLOBALS['__muster_wp_terms']['category::merged']['name']);
    }

    public function testFillThrowsOnUnrecognisedKey(): void
    {
        $context = new MusterContext(new VictualsFactory());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('post_title');

        // A post field on a term is a category error the mapper must reject.
        (new TermBuilder($context, 'category'))->fill(['post_title' => 'Wrong builder']);
    }
}

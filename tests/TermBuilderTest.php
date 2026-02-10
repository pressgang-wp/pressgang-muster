<?php

namespace PressGang\Muster\Tests;

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
}

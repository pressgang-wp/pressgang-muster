<?php

declare(strict_types=1);

namespace PressGang\Muster\Tests;

use PHPUnit\Framework\TestCase;
use PressGang\Muster\Builders\PostBuilder;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Victuals\VictualsFactory;

final class PostBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        reset_wordpress_stub_state();
    }

    public function testSaveInsertsWhenPostDoesNotExist(): void
    {
        $context = new MusterContext(new VictualsFactory());

        $ref = (new PostBuilder($context, 'event'))
            ->title('Launch Event')
            ->slug('launch-event')
            ->status('publish')
            ->content('Body')
            ->meta(['featured' => 1])
            ->save();

        self::assertSame(1, $ref->id());
        self::assertSame('event', $ref->postType());
        self::assertSame('launch-event', $ref->slug());
        self::assertSame(1, $GLOBALS['__muster_wp_meta'][1]['featured']);
    }

    public function testSaveUpdatesWhenPostAlreadyExists(): void
    {
        $context = new MusterContext(new VictualsFactory());

        $first = (new PostBuilder($context, 'event'))
            ->title('Original')
            ->slug('same-slug')
            ->content('First')
            ->status('draft')
            ->save();

        $second = (new PostBuilder($context, 'event'))
            ->title('Updated')
            ->slug('same-slug')
            ->content('Second')
            ->status('publish')
            ->save();

        self::assertSame($first->id(), $second->id());
        self::assertCount(1, $GLOBALS['__muster_wp_posts']);

        $stored = $GLOBALS['__muster_wp_posts']['event::same-slug'];
        self::assertSame('Updated', $stored['post_title']);
        self::assertSame('Second', $stored['post_content']);
        self::assertSame('publish', $stored['post_status']);
    }

    public function testSaveUsesTitleToBuildSlugWhenSlugNotProvided(): void
    {
        $context = new MusterContext(new VictualsFactory());

        $ref = (new PostBuilder($context, 'post'))
            ->title('Hello World Again')
            ->save();

        self::assertSame('hello-world-again', $ref->slug());
    }

    public function testDryRunDoesNotPersistPost(): void
    {
        $context = new MusterContext(new VictualsFactory(), seed: 123, dryRun: true);

        $ref = (new PostBuilder($context, 'event'))
            ->title('No Write')
            ->slug('no-write')
            ->save();

        self::assertSame(0, $ref->id());
        self::assertCount(0, $GLOBALS['__muster_wp_posts']);
    }
}

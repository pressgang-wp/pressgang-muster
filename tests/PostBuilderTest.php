<?php

namespace PressGang\Muster\Tests;

use LogicException;
use PHPUnit\Framework\TestCase;
use PressGang\Muster\Adapters\AcfAdapterInterface;
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

    public function testSaveAppliesExtendedFields(): void
    {
        $context = new MusterContext(new VictualsFactory(), acf: new TestAcfAdapter());

        (new PostBuilder($context, 'event'))
            ->title('Extended')
            ->slug('extended')
            ->status('publish')
            ->excerpt('Summary')
            ->author(3)
            ->parent(2)
            ->template('templates/custom.php')
            ->terms('category', ['featured'])
            ->acf(['field_key' => 'field_value'])
            ->save();

        self::assertSame('Summary', $GLOBALS['__muster_wp_posts']['event::extended']['post_excerpt']);
        self::assertSame(3, $GLOBALS['__muster_wp_posts']['event::extended']['post_author']);
        self::assertSame(2, $GLOBALS['__muster_wp_posts']['event::extended']['post_parent']);
        self::assertSame('templates/custom.php', $GLOBALS['__muster_wp_meta'][1]['_wp_page_template']);
        self::assertSame(['featured'], $GLOBALS['__muster_wp_post_terms'][1]['category']);
        self::assertSame('post', $GLOBALS['__muster_wp_acf_last']['objectType']);
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

    public function testSaveThrowsWhenNeitherSlugNorTitleIsProvided(): void
    {
        $context = new MusterContext(new VictualsFactory());

        $this->expectException(LogicException::class);

        (new PostBuilder($context, 'event'))
            ->status('publish')
            ->save();
    }
}

final class TestAcfAdapter implements AcfAdapterInterface
{
    /**
     * @param array<string, mixed> $fields
     * @param string $objectType
     * @param int $objectId
     * @return void
     */
    public function updateFields(array $fields, string $objectType, int $objectId): void
    {
        $GLOBALS['__muster_wp_acf_last'] = [
            'fields' => $fields,
            'objectType' => $objectType,
            'objectId' => $objectId,
        ];
    }
}

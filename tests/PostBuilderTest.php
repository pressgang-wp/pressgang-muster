<?php

namespace PressGang\Muster\Tests;

use LogicException;
use PHPUnit\Framework\TestCase;
use PressGang\Muster\Adapters\AcfAdapterInterface;
use PressGang\Muster\Builders\PostBuilder;
use PressGang\Muster\Clock\FixtureClock;
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

    public function testUpdatePreservesFieldsThatWereNotSupplied(): void
    {
        $context = new MusterContext(new VictualsFactory());

        (new PostBuilder($context, 'event'))
            ->title('Original')
            ->slug('merge-post')
            ->content('Keep this body')
            ->excerpt('Keep this excerpt')
            ->status('publish')
            ->parent(7)
            ->save();

        (new PostBuilder($context, 'event'))
            ->slug('merge-post')
            ->title('Updated title')
            ->save();

        $stored = $GLOBALS['__muster_wp_posts']['event::merge-post'];
        self::assertSame('Updated title', $stored['post_title']);
        self::assertSame('Keep this body', $stored['post_content']);
        self::assertSame('Keep this excerpt', $stored['post_excerpt']);
        self::assertSame('publish', $stored['post_status']);
        self::assertSame(7, $stored['post_parent']);
    }

    public function testInsertDefaultsStatusToPublishAndDateToTheEpoch(): void
    {
        $context = new MusterContext(new VictualsFactory(), clock: new FixtureClock('2026-01-01 09:00:00+00:00'));

        (new PostBuilder($context, 'event'))
            ->title('No status or date')
            ->slug('defaults')
            ->save();

        $stored = $GLOBALS['__muster_wp_posts']['event::defaults'];
        self::assertSame('publish', $stored['post_status']);
        self::assertSame('2026-01-01 09:00:00', $stored['post_date']);
    }

    public function testExplicitStatusAndDateOverrideTheInsertDefaults(): void
    {
        $context = new MusterContext(new VictualsFactory(), clock: new FixtureClock('2026-01-01 09:00:00+00:00'));

        (new PostBuilder($context, 'event'))
            ->slug('explicit')
            ->status('draft')
            ->date('2020-05-05 12:00:00')
            ->save();

        $stored = $GLOBALS['__muster_wp_posts']['event::explicit'];
        self::assertSame('draft', $stored['post_status']);
        self::assertSame('2020-05-05 12:00:00', $stored['post_date']);
    }

    public function testInsertDefaultsNeverClobberAnExistingPostOnUpdate(): void
    {
        $context = new MusterContext(new VictualsFactory(), clock: new FixtureClock('2026-01-01 09:00:00+00:00'));

        // Created as a draft; a re-run that omits the status must not force it
        // to publish — the default is an insert-only convenience, not an update.
        (new PostBuilder($context, 'event'))->slug('kept')->status('draft')->save();
        (new PostBuilder($context, 'event'))->slug('kept')->title('Updated')->save();

        self::assertSame('draft', $GLOBALS['__muster_wp_posts']['event::kept']['post_status']);
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

    public function testFillAppliesWpNativeKeysLikeTheFluentSetters(): void
    {
        $context = new MusterContext(new VictualsFactory(), acf: new TestAcfAdapter());

        // The same result as testSaveAppliesExtendedFields, declared as data.
        (new PostBuilder($context, 'event'))->fill([
            'post_title'    => 'Extended',
            'post_name'     => 'extended',
            'post_status'   => 'publish',
            'post_excerpt'  => 'Summary',
            'post_author'   => 3,
            'post_parent'   => 2,
            'page_template' => 'templates/custom.php',
            'tax_input'     => ['category' => ['featured']],
            'acf'           => ['field_key' => 'field_value'],
        ])->save();

        self::assertSame('Summary', $GLOBALS['__muster_wp_posts']['event::extended']['post_excerpt']);
        self::assertSame(3, $GLOBALS['__muster_wp_posts']['event::extended']['post_author']);
        self::assertSame(2, $GLOBALS['__muster_wp_posts']['event::extended']['post_parent']);
        self::assertSame('templates/custom.php', $GLOBALS['__muster_wp_meta'][1]['_wp_page_template']);
        self::assertSame(['featured'], $GLOBALS['__muster_wp_post_terms'][1]['category']);
        self::assertSame(['field_key' => 'field_value'], $GLOBALS['__muster_wp_acf_last']['fields']);
    }

    public function testFillDispatchesMetaInput(): void
    {
        $context = new MusterContext(new VictualsFactory());

        $ref = (new PostBuilder($context, 'event'))
            ->fill(['post_name' => 'metafill', 'meta_input' => ['legacy_id' => 42]])
            ->save();

        self::assertSame(42, $GLOBALS['__muster_wp_meta'][$ref->id()]['legacy_id']);
    }

    public function testFillMergesWithFluentSettersLastWriteWins(): void
    {
        $context = new MusterContext(new VictualsFactory());

        (new PostBuilder($context, 'event'))
            ->fill(['post_name' => 'merged', 'post_status' => 'draft'])
            ->status('publish')
            ->save();

        self::assertSame('publish', $GLOBALS['__muster_wp_posts']['event::merged']['post_status']);
    }

    public function testFillThrowsOnUnrecognisedKey(): void
    {
        $context = new MusterContext(new VictualsFactory());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('titel');

        (new PostBuilder($context, 'event'))->fill(['titel' => 'Typo']);
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

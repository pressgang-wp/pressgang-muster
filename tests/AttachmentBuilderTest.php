<?php

namespace PressGang\Muster\Tests;

use PHPUnit\Framework\TestCase;
use PressGang\Muster\Builders\AttachmentBuilder;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Refs\PostRef;
use PressGang\Muster\Victuals\VictualsFactory;

final class AttachmentBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        reset_wordpress_stub_state();
    }

    public function testPlaceholderSaveCreatesAttachmentAndFile(): void
    {
        $context = new MusterContext(new VictualsFactory());

        $ref = (new AttachmentBuilder($context, 'hero-image'))
            ->placeholder(64, 48, 'Hero')
            ->title('Hero image')
            ->alt('A hero image')
            ->save();

        self::assertSame('attachment', $ref->postType());
        self::assertGreaterThan(0, $ref->id());

        $file = $GLOBALS['__muster_wp_attachment_files'][$ref->id()];
        self::assertFileExists($file);
        self::assertSame('A hero image', $GLOBALS['__muster_wp_meta'][$ref->id()]['_wp_attachment_image_alt']);
    }

    public function testSaveReusesExistingAttachmentBySlug(): void
    {
        $context = new MusterContext(new VictualsFactory());

        $first = (new AttachmentBuilder($context, 'shared'))->placeholder(8, 8)->save();
        $second = (new AttachmentBuilder($context, 'shared'))->placeholder(8, 8)->save();

        self::assertSame($first->id(), $second->id());
        self::assertCount(1, $GLOBALS['__muster_wp_attachment_files']);
    }

    public function testFeaturedOnSetsPostThumbnail(): void
    {
        $context = new MusterContext(new VictualsFactory());

        $ref = (new AttachmentBuilder($context, 'feature'))
            ->placeholder(8, 8)
            ->featuredOn(new PostRef(33, 'event', 'launch'))
            ->save();

        self::assertSame($ref->id(), $GLOBALS['__muster_wp_thumbnails'][33]);
    }

    public function testDryRunSkipsAttachmentWrites(): void
    {
        $context = new MusterContext(new VictualsFactory(), dryRun: true);

        $ref = (new AttachmentBuilder($context, 'hero-image'))->placeholder(8, 8)->save();

        self::assertSame(0, $ref->id());
        self::assertSame([], $GLOBALS['__muster_wp_attachment_files']);
    }
}

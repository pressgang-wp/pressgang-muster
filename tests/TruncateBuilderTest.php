<?php

namespace PressGang\Muster\Tests;

use PHPUnit\Framework\TestCase;
use PressGang\Muster\Builders\PostBuilder;
use PressGang\Muster\Builders\TermBuilder;
use PressGang\Muster\Builders\TruncateBuilder;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Victuals\VictualsFactory;

final class TruncateBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        reset_wordpress_stub_state();
    }

    public function testPostsDeletesAllOfTypeOnly(): void
    {
        $context = new MusterContext(new VictualsFactory());

        (new PostBuilder($context, 'event'))->title('One')->slug('one')->save();
        (new PostBuilder($context, 'event'))->title('Two')->slug('two')->save();
        $kept = (new PostBuilder($context, 'page'))->title('Keep')->slug('keep')->save();

        (new TruncateBuilder($context))->posts('event');

        self::assertCount(2, $GLOBALS['__muster_wp_deleted_posts']);
        self::assertNotContains($kept->id(), $GLOBALS['__muster_wp_deleted_posts']);
    }

    public function testTermsDeletesAllInTaxonomy(): void
    {
        $context = new MusterContext(new VictualsFactory());

        (new TermBuilder($context, 'event_type'))->name('Talk')->slug('talk')->save();
        (new TermBuilder($context, 'event_type'))->name('Workshop')->slug('workshop')->save();

        (new TruncateBuilder($context))->terms('event_type');

        self::assertCount(2, $GLOBALS['__muster_wp_deleted_terms']);
    }

    public function testDryRunSkipsDeletes(): void
    {
        $context = new MusterContext(new VictualsFactory());
        (new PostBuilder($context, 'event'))->title('One')->slug('one')->save();

        $dry = new MusterContext(new VictualsFactory(), dryRun: true);
        (new TruncateBuilder($dry))->posts('event')->terms('event_type');

        self::assertSame([], $GLOBALS['__muster_wp_deleted_posts']);
        self::assertSame([], $GLOBALS['__muster_wp_deleted_terms']);
        self::assertSame(1, $dry->report()->summary()['prune']);
    }
}

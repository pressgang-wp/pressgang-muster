<?php

namespace PressGang\Muster\Tests;

use PHPUnit\Framework\TestCase;
use PressGang\Muster\Muster;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Testing\AssertsWordPressFixtures;
use PressGang\Muster\Testing\MusterSnapshot;
use PressGang\Muster\Testing\SnapshotMismatch;
use PressGang\Muster\Victuals\VictualsFactory;

final class TestingUtilitiesTest extends TestCase
{
    use AssertsWordPressFixtures;

    protected function setUp(): void
    {
        reset_wordpress_stub_state();
    }

    public function testWordPressAssertionsReturnResolvedCoreObjects(): void
    {
        $muster = new class(new MusterContext(new VictualsFactory())) extends Muster {
            public function run(): void
            {
            }
        };
        $post = $muster->page()->key('page:about')->slug('about')->title('About')->save();
        $muster->term('category')->key('term:news')->slug('news')->name('News')->save();
        $muster->user('editor')->key('user:editor')->password('initial-password')->save();
        $muster->option('fixture_mode')->key('option:mode')->value('ready')->save();
        $muster->comment($post)->key('comment:welcome')->author('Editor')->content('Welcome')->save();

        self::assertSame($post->id(), $this->assertPostExists('about', 'page')->ID);
        self::assertSame('news', $this->assertTermExists('category', 'news')->slug);
        self::assertSame('editor', $this->assertUserExists('editor')->user_login);
        $this->assertOptionEquals('fixture_mode', 'ready');
        self::assertSame('Welcome', $this->assertCommentExists($post->id(), 'Welcome')->comment_content);
    }

    public function testSnapshotExcludesVolatileIdsByDefault(): void
    {
        $first = $this->reportForExistingId(10);
        $second = $this->reportForExistingId(99);

        self::assertSame(MusterSnapshot::serialize($first), MusterSnapshot::serialize($second));
        self::assertNotSame(
            MusterSnapshot::serialize($first, includeIds: true),
            MusterSnapshot::serialize($second, includeIds: true)
        );
    }

    public function testSnapshotMismatchIsExplicit(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'muster-snapshot-');
        self::assertIsString($path);
        file_put_contents($path, "different\n");

        try {
            $this->expectException(SnapshotMismatch::class);
            MusterSnapshot::assertMatches($path, $this->reportForExistingId(1));
        } finally {
            @unlink($path);
        }
    }

    private function reportForExistingId(int $id): \PressGang\Muster\Results\RunReport
    {
        reset_wordpress_stub_state();
        $GLOBALS['__muster_wp_next_post_id'] = $id;
        $context = new MusterContext(new VictualsFactory());
        $muster = new class($context) extends Muster {
            public function run(): void
            {
            }
        };
        $muster->page()->key('page:snapshot')->slug('snapshot')->save();

        return $context->report();
    }
}

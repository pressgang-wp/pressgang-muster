<?php

namespace PressGang\Muster\Tests;

use PHPUnit\Framework\TestCase;
use PressGang\Muster\Muster;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Refs\PostRef;
use PressGang\Muster\Victuals\VictualsFactory;
use UnexpectedValueException;

final class PatternAfterHookTest extends TestCase
{
    protected function setUp(): void
    {
        reset_wordpress_stub_state();
    }

    public function testAfterHookPersistsRelatedDeclarationsInOrder(): void
    {
        $muster = $this->muster();

        $muster->pattern('articles')
            ->count(2)
            ->after(
                'welcome-comment',
                fn (PostRef $post, int $i) => $muster->comment($post)
                    ->key('comment:article:' . $i)
                    ->author('Fixture Editor')
                    ->email('fixtures@example.test')
                    ->date('2026-01-01 09:0' . $i . ':00')
                    ->content('Welcome ' . $i)
            )
            ->build(
                fn (int $i) => $muster->post()
                    ->key('article:' . $i)
                    ->slug('article-' . $i)
                    ->title('Article ' . $i)
            );

        self::assertCount(2, $GLOBALS['__muster_wp_posts']);
        self::assertCount(2, $GLOBALS['__muster_wp_comments']);
        self::assertSame(1, get_comment(1)->comment_post_ID);
        self::assertSame(2, get_comment(2)->comment_post_ID);
    }

    public function testAfterHookDeclarationsAppearInDryRunReportWithoutWrites(): void
    {
        $context = new MusterContext(new VictualsFactory(), dryRun: true);
        $muster = $this->muster($context);

        $muster->pattern('articles')
            ->count(1)
            ->after(
                'welcome-comment',
                fn (PostRef $post, int $i) => $muster->comment($post)
                    ->key('comment:article:' . $i)
                    ->author('Fixture Editor')
                    ->content('Welcome')
            )
            ->build(fn (int $i) => $muster->post()->key('article:' . $i)->slug('article-' . $i));

        self::assertSame(2, $context->report()->summary()['create']);
        self::assertCount(0, $GLOBALS['__muster_wp_posts']);
        self::assertCount(0, $GLOBALS['__muster_wp_comments']);
    }

    public function testAfterHookRejectsInvisibleArbitraryResults(): void
    {
        $muster = $this->muster();

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('after-hook [invalid] must return PersistableDeclaration, iterable, or null');

        $muster->pattern('invalid')
            ->count(1)
            ->after('invalid', fn (PostRef $post, int $i): string => 'side effect')
            ->build(fn (int $i) => $muster->post()->key('post:' . $i)->slug('post-' . $i));
    }

    private function muster(?MusterContext $context = null): Muster
    {
        return new class($context ?? new MusterContext(new VictualsFactory())) extends Muster {
            public function run(): void
            {
            }
        };
    }
}

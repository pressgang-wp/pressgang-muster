<?php

namespace PressGang\Muster\Tests;

use PHPUnit\Framework\TestCase;
use PressGang\Muster\Builders\CommentBuilder;
use PressGang\Muster\Clock\FixtureClock;
use PressGang\Muster\Muster;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Ownership\OwnershipConflict;
use PressGang\Muster\Victuals\VictualsFactory;

final class CommentTestMuster extends Muster
{
    public function run(): void
    {
    }
}

final class CommentBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        reset_wordpress_stub_state();
    }

    public function testCommentUpsertUsesStableNativeLocator(): void
    {
        $postId = wp_insert_post(['post_type' => 'post', 'post_name' => 'article']);
        $muster = new CommentTestMuster($this->context());

        $first = $muster->comment($postId)
            ->key('comment:review')
            ->author('Reviewer')
            ->email('reviewer@example.test')
            ->date('2026-01-02 09:00:00')
            ->content('First version')
            ->status('hold')
            ->url('https://example.test/reviewer')
            ->meta(['rating' => 4])
            ->save();

        $second = (new CommentTestMuster($this->context()))->comment($postId)
            ->key('comment:review')
            ->author('Reviewer')
            ->email('reviewer@example.test')
            ->date('2026-01-02 09:00:00')
            ->content('Updated version')
            ->save();

        self::assertSame($first->id(), $second->id());
        self::assertCount(1, $GLOBALS['__muster_wp_comments']);
        self::assertSame('Updated version', get_comment($first->id())->comment_content);
        self::assertSame('0', get_comment($first->id())->comment_approved);
        self::assertSame('https://example.test/reviewer', get_comment($first->id())->comment_author_url);
        self::assertSame(4, $GLOBALS['__muster_wp_comment_meta'][$first->id()]['rating']);
    }

    public function testMetaOnlyUpdateDoesNotRequireACommentCoreWrite(): void
    {
        $postId = wp_insert_post(['post_type' => 'post', 'post_name' => 'metadata']);
        $muster = new CommentTestMuster($this->context());

        $muster->comment($postId)
            ->key('comment:metadata')
            ->author('Reviewer')
            ->email('reviewer@example.test')
            ->content('Stable content')
            ->save();

        (new CommentTestMuster($this->context()))->comment($postId)
            ->key('comment:metadata')
            ->author('Reviewer')
            ->email('reviewer@example.test')
            ->content('Stable content')
            ->meta(['rating' => 5])
            ->save();

        self::assertSame(0, $GLOBALS['__muster_wp_update_comment_calls']);
        self::assertSame(5, $GLOBALS['__muster_wp_comment_meta'][1]['rating']);
    }

    public function testCommentRepliesAcceptRefs(): void
    {
        $postId = wp_insert_post(['post_type' => 'post', 'post_name' => 'thread']);
        $muster = new CommentTestMuster($this->context());

        $parent = $muster->comment($postId)
            ->key('comment:parent')
            ->author('Parent')
            ->email('parent@example.test')
            ->date('2026-01-02 09:00:00')
            ->content('Parent comment')
            ->save();

        $reply = $muster->comment($postId)
            ->key('comment:reply')
            ->author('Reply')
            ->email('reply@example.test')
            ->date('2026-01-02 09:01:00')
            ->content('Reply comment')
            ->parent($parent)
            ->save();

        self::assertSame($parent->id(), (int) get_comment($reply->id())->comment_parent);
    }

    public function testUnownedNativeLocatorRequiresAdoption(): void
    {
        $postId = wp_insert_post(['post_type' => 'post', 'post_name' => 'collision']);
        wp_insert_comment([
            'comment_post_ID' => $postId,
            'comment_author' => 'Existing',
            'comment_author_email' => 'existing@example.test',
            'comment_content' => 'Existing',
            'comment_type' => 'comment',
            'comment_parent' => 0,
            'user_id' => 0,
            'comment_approved' => '1',
            'comment_date' => '2026-01-02 09:00:00',
            'comment_date_gmt' => '2026-01-02 09:00:00',
        ]);

        $this->expectException(OwnershipConflict::class);

        (new CommentTestMuster($this->context()))->comment($postId)
            ->key('comment:existing')
            ->author('Existing')
            ->email('existing@example.test')
            ->date('2026-01-02 09:00:00')
            ->content('Claim')
            ->save();
    }

    public function testDryRunReportsWithoutWriting(): void
    {
        $postId = wp_insert_post(['post_type' => 'post', 'post_name' => 'plan']);
        $context = $this->context(true);

        $ref = (new CommentTestMuster($context))->comment($postId)
            ->key('comment:planned')
            ->author('Planner')
            ->email('planner@example.test')
            ->content('Planned comment')
            ->save();

        self::assertSame(0, $ref->id());
        self::assertCount(0, $GLOBALS['__muster_wp_comments']);
        self::assertSame(1, $context->report()->summary()['create']);
    }

    public function testLowLevelBuilderRequiresContentAndAuthorIdentity(): void
    {
        $postId = wp_insert_post(['post_type' => 'post', 'post_name' => 'invalid']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('requires an author email, author name, or resolvable user');

        (new CommentBuilder($this->context(), $postId))->content('No author')->save();
    }

    private function context(bool $dryRun = false): MusterContext
    {
        return new MusterContext(
            new VictualsFactory(),
            dryRun: $dryRun,
            clock: new FixtureClock('2026-01-01 09:00:00+00:00')
        );
    }
}

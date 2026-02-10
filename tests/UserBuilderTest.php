<?php

namespace PressGang\Muster\Tests;

use PHPUnit\Framework\TestCase;
use PressGang\Muster\Builders\UserBuilder;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Victuals\VictualsFactory;

final class UserBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        reset_wordpress_stub_state();
    }

    public function testSaveInsertsUserWhenMissing(): void
    {
        $context = new MusterContext(new VictualsFactory());

        $ref = (new UserBuilder($context))
            ->login('editor-one')
            ->email('editor-one@example.test')
            ->displayName('Editor One')
            ->role('editor')
            ->meta(['department' => 'content'])
            ->save();

        self::assertSame(1, $ref->userId());
        self::assertSame('editor-one', $ref->login());
        self::assertSame('content', $GLOBALS['__muster_wp_user_meta'][1]['department']);
    }

    public function testSaveUpdatesUserWhenExisting(): void
    {
        $context = new MusterContext(new VictualsFactory());

        $first = (new UserBuilder($context))
            ->login('same-user')
            ->displayName('One')
            ->save();

        $second = (new UserBuilder($context))
            ->login('same-user')
            ->displayName('Two')
            ->save();

        self::assertSame($first->userId(), $second->userId());
        self::assertCount(1, $GLOBALS['__muster_wp_users']);
    }

    public function testDryRunSkipsUserWrites(): void
    {
        $context = new MusterContext(new VictualsFactory(), dryRun: true);

        $ref = (new UserBuilder($context))
            ->login('dry-user')
            ->save();

        self::assertSame(0, $ref->userId());
        self::assertCount(0, $GLOBALS['__muster_wp_users']);
    }
}

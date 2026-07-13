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
            ->password('initial-password')
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
            ->password('initial-password')
            ->displayName('One')
            ->save();

        $second = (new UserBuilder($context))
            ->login('same-user')
            ->displayName('Two')
            ->save();

        self::assertSame($first->userId(), $second->userId());
        self::assertCount(1, $GLOBALS['__muster_wp_users']);
    }

    public function testUpdatePreservesFieldsThatWereNotSupplied(): void
    {
        $context = new MusterContext(new VictualsFactory());

        (new UserBuilder($context))
            ->login('merge-user')
            ->password('initial-password')
            ->email('keep@example.test')
            ->displayName('Original name')
            ->role('editor')
            ->save();

        (new UserBuilder($context))
            ->login('merge-user')
            ->displayName('Updated name')
            ->save();

        $stored = $GLOBALS['__muster_wp_users']['merge-user'];
        self::assertSame('Updated name', $stored['display_name']);
        self::assertSame('keep@example.test', $stored['user_email']);
        self::assertSame('editor', $stored['role']);
    }

    public function testDryRunSkipsUserWrites(): void
    {
        $context = new MusterContext(new VictualsFactory(), dryRun: true);

        $ref = (new UserBuilder($context))
            ->login('dry-user')
            ->password('initial-password')
            ->save();

        self::assertSame(0, $ref->userId());
        self::assertCount(0, $GLOBALS['__muster_wp_users']);
    }

    public function testNewUserRequiresExplicitInitialPassword(): void
    {
        $context = new MusterContext(new VictualsFactory());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('requires an explicit initial password via password()');

        (new UserBuilder($context))->login('missing-password')->save();
    }

    public function testExistingUserPasswordIsNotResetOnRerun(): void
    {
        $context = new MusterContext(new VictualsFactory());

        (new UserBuilder($context))
            ->login('stable-password')
            ->password('initial-password')
            ->save();

        (new UserBuilder($context))
            ->login('stable-password')
            ->password('different-password')
            ->displayName('Updated')
            ->save();

        self::assertSame('initial-password', $GLOBALS['__muster_wp_users']['stable-password']['user_pass']);
    }
}

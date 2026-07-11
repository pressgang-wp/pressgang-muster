<?php


namespace PressGang\Muster\Tests;

use PHPUnit\Framework\TestCase;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Victuals\VictualsFactory;

final class MusterContextTest extends TestCase
{
    public function testVictualsReturnsSameInstanceWithinContext(): void
    {
        $context = new MusterContext(new VictualsFactory(), seed: 1978);

        $first = $context->victuals();
        $second = $context->victuals();

        self::assertSame($first, $second);
    }

    public function testVictualsForSeedReturnsFreshSeededInstances(): void
    {
        // Sequential draws: Faker seeding is global-stream, so each seeded
        // instance's values must be drawn before the next is created.
        $context = new MusterContext(new VictualsFactory(), seed: 100);

        $first = $context->victualsForSeed(1978)->headline();
        $second = $context->victualsForSeed(1978)->headline();

        self::assertSame($first, $second);
    }
}

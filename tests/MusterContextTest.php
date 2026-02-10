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
        $context = new MusterContext(new VictualsFactory(), seed: 100);

        $a = $context->victualsForSeed(1978);
        $b = $context->victualsForSeed(1978);

        self::assertSame($a->headline(), $b->headline());
    }
}

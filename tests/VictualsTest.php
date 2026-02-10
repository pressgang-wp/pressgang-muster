<?php

declare(strict_types=1);

namespace PressGang\Muster\Tests;

use PHPUnit\Framework\TestCase;
use PressGang\Muster\Victuals\VictualsFactory;

final class VictualsTest extends TestCase
{
    public function testSeededOutputIsDeterministic(): void
    {
        $factory = new VictualsFactory();

        $a = $factory->make(1978);
        $b = $factory->make(1978);

        self::assertSame($a->headline(), $b->headline());
        self::assertSame($a->sentence(9), $b->sentence(9));
        self::assertSame($a->paragraphs(2), $b->paragraphs(2));
        self::assertSame(
            $a->dateBetween('-10 days', '+10 days')->format('Y-m-d H:i:s'),
            $b->dateBetween('-10 days', '+10 days')->format('Y-m-d H:i:s')
        );
    }

    public function testParagraphsReturnsJoinedParagraphString(): void
    {
        $victuals = (new VictualsFactory())->make(42);

        $content = $victuals->paragraphs(3);

        self::assertIsString($content);
        self::assertStringContainsString("\n\n", $content);
    }
}

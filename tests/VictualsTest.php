<?php

namespace PressGang\Muster\Tests;

use PHPUnit\Framework\TestCase;
use PressGang\Muster\Clock\FixtureClock;
use PressGang\Muster\Victuals\VictualsFactory;

final class VictualsTest extends TestCase
{
    public function testSeededOutputIsDeterministic(): void
    {
        // Faker's seed() drives PHP's GLOBAL mt_rand stream, so determinism
        // holds per sequence, not per coexisting instance: draw each
        // instance's values fully before creating the next, mirroring how
        // Muster actually consumes Victuals (one context, sequential draws).
        $draw = static function (): array {
            $victuals = (new VictualsFactory())->make(1978);

            return [
                $victuals->headline(),
                $victuals->sentence(9),
                $victuals->paragraphs(2),
                $victuals->dateBetween('-10 days', '+10 days')->format('Y-m-d H:i:s'),
            ];
        };

        self::assertSame($draw(), $draw());
    }

    public function testVictualsMethodShapesAreUsable(): void
    {
        $victuals = (new VictualsFactory())->make(42);

        self::assertIsString($victuals->content(2));
        self::assertIsString($victuals->excerpt(12));
        self::assertIsString($victuals->slug());
        self::assertIsString($victuals->name());
        self::assertIsString($victuals->company());
        self::assertIsString($victuals->email());
        self::assertIsString($victuals->url());
        self::assertStringStartsWith('data:image/svg+xml;charset=UTF-8,', $victuals->imageUrl(640, 360));
        self::assertStringContainsString('<!-- wp:paragraph -->', $victuals->gutenbergBlocks());
        self::assertStringContainsString('<blockquote>', $victuals->richContent());
        self::assertIsString($victuals->ukPhone());
        self::assertIsString($victuals->ukPostcode());
        self::assertIsString($victuals->ukTown());
        self::assertIsString($victuals->date());
        self::assertIsString($victuals->datetime());
    }

    public function testParagraphsReturnsJoinedParagraphString(): void
    {
        $victuals = (new VictualsFactory())->make(42);

        $content = $victuals->paragraphs(3);

        self::assertIsString($content);
        self::assertStringContainsString("\n\n", $content);
    }

    public function testDateHelpersUsePinnedEpoch(): void
    {
        $victuals = (new VictualsFactory())->make(
            1978,
            new FixtureClock('2026-01-01 09:00:00+00:00')
        );

        self::assertSame(
            '2026-01-01T09:00:00+00:00',
            $victuals->dateBetween('now', 'now')->format(DATE_ATOM)
        );
        self::assertSame('2026-01-01T09:00:00+00:00', $victuals->epoch()->format(DATE_ATOM));
    }

    public function testSeedAndEpochProduceRepeatableDateSequence(): void
    {
        $draw = static function (): array {
            $victuals = (new VictualsFactory())->make(
                1978,
                new FixtureClock('2026-01-01 09:00:00+00:00')
            );

            return [
                $victuals->date(),
                $victuals->datetime(),
                $victuals->dateBetween('+1 week', '+6 months')->format(DATE_ATOM),
            ];
        };

        self::assertSame($draw(), $draw());
    }

    public function testGeneratedContentHelpersAreDeterministicPerSequence(): void
    {
        $draw = static function (): array {
            $victuals = (new VictualsFactory())->make(1978);

            return [
                $victuals->imageUrl(800, 450, 'Event image'),
                $victuals->gutenbergBlocks(2),
                $victuals->richContent(2),
                $victuals->repeaterRows(2, [
                    'title' => fn ($values, int $i): string => $i . '. ' . $values->headline(),
                    'featured' => false,
                ]),
            ];
        };

        self::assertSame($draw(), $draw());
    }

    public function testRepeaterRowsUseOneBasedIndexesAndExplicitConstants(): void
    {
        $victuals = (new VictualsFactory())->make(42);
        $rows = $victuals->repeaterRows(3, [
            'position' => fn ($values, int $i): int => $i,
            'enabled' => true,
        ]);

        self::assertSame([1, 2, 3], array_column($rows, 'position'));
        self::assertSame([true, true, true], array_column($rows, 'enabled'));
    }
}

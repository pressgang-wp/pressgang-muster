<?php

namespace PressGang\Muster\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PressGang\Muster\Clock\FixtureClock;
use PressGang\Muster\Muster;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Victuals\VictualsFactory;

final class FixtureClockTest extends TestCase
{
    public function testRelativeBoundariesResolveFromEpoch(): void
    {
        $clock = new FixtureClock('2026-01-01 09:00:00+00:00');

        self::assertSame('2026-01-01T09:00:00+00:00', $clock->resolve('now')->format(DATE_ATOM));
        self::assertSame('2026-01-08T09:00:00+00:00', $clock->resolve('+1 week')->format(DATE_ATOM));
        self::assertSame('2025-07-01T09:00:00+00:00', $clock->resolve('-6 months')->format(DATE_ATOM));
    }

    public function testDateTimeBoundaryRemainsExplicit(): void
    {
        $clock = new FixtureClock('2026-01-01');
        $boundary = new DateTimeImmutable('2030-03-04 05:06:07+02:00');

        self::assertSame($boundary->format(DATE_ATOM), $clock->resolve($boundary)->format(DATE_ATOM));
    }

    public function testRelativeEpochIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an absolute date');

        new FixtureClock('+1 week');
    }

    public function testInvalidCalendarEpochIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid fixture epoch [2026-02-30].');

        new FixtureClock('2026-02-30');
    }

    public function testMusterExposesEpochAndRelativeResolution(): void
    {
        $context = new MusterContext(
            new VictualsFactory(),
            clock: new FixtureClock('2026-01-01 09:00:00+00:00')
        );
        $muster = new class ($context) extends Muster {
            public function run(): void
            {
            }
        };

        self::assertSame('2026-01-01 09:00:00', $muster->epoch()->format('Y-m-d H:i:s'));
        self::assertSame('2026-02-01 09:00:00', $muster->at('+1 month')->format('Y-m-d H:i:s'));
    }

    public function testMusterDefaultEpochAppliesWithoutExplicitContextClock(): void
    {
        $muster = new class (new MusterContext(new VictualsFactory())) extends Muster {
            public static function defaultEpoch(): string
            {
                return '2027-04-05 06:07:08+00:00';
            }

            public function run(): void
            {
            }
        };

        self::assertSame('2027-04-05T06:07:08+00:00', $muster->epoch()->format(DATE_ATOM));
    }

    public function testExplicitContextClockOverridesMusterDefault(): void
    {
        $context = new MusterContext(
            new VictualsFactory(),
            clock: new FixtureClock('2030-01-01 00:00:00+00:00')
        );
        $muster = new class ($context) extends Muster {
            public static function defaultEpoch(): string
            {
                return '2027-04-05 06:07:08+00:00';
            }

            public function run(): void
            {
            }
        };

        self::assertSame('2030-01-01T00:00:00+00:00', $muster->epoch()->format(DATE_ATOM));
    }

    public function testExplicitClockDoesNotParseOverriddenDefault(): void
    {
        $context = new MusterContext(
            new VictualsFactory(),
            clock: new FixtureClock('2030-01-01 00:00:00+00:00')
        );
        $muster = new class ($context) extends Muster {
            public static function defaultEpoch(): string
            {
                return '+1 week';
            }

            public function run(): void
            {
            }
        };

        self::assertSame('2030-01-01T00:00:00+00:00', $muster->epoch()->format(DATE_ATOM));
    }
}

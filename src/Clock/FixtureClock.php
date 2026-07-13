<?php

namespace PressGang\Muster\Clock;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Immutable reference clock for one deterministic fixture run.
 *
 * The epoch is deliberately independent of Faker's random seed. Relative
 * boundaries resolve from this instant instead of the machine's wall clock.
 */
final class FixtureClock
{
    private DateTimeImmutable $epoch;

    /**
     * @param string|DateTimeInterface $epoch Absolute fixture epoch. Strings
     *        must begin with an ISO-style calendar date (`YYYY-MM-DD`).
     */
    public function __construct(string|DateTimeInterface $epoch)
    {
        $this->epoch = $epoch instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($epoch)
            : self::parseEpoch($epoch);
    }

    /**
     * Parse and validate an absolute epoch string in UTC.
     *
     * Relative expressions are rejected here on purpose: an epoch that drifts
     * with the invocation time would defeat deterministic fixture dates.
     *
     * @param string $epoch Epoch beginning with an ISO calendar date.
     * @return DateTimeImmutable
     * @throws InvalidArgumentException If the string is relative or unparseable.
     */
    private static function parseEpoch(string $epoch): DateTimeImmutable
    {
        $epoch = trim($epoch);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}(?:$|[T ])/D', $epoch)) {
            throw new InvalidArgumentException(
                'Fixture epoch must be an absolute date or datetime beginning YYYY-MM-DD.'
            );
        }

        $parsed = date_parse($epoch);
        if (($parsed['error_count'] ?? 0) > 0 || ($parsed['warning_count'] ?? 0) > 0) {
            throw new InvalidArgumentException(sprintf('Invalid fixture epoch [%s].', $epoch));
        }

        try {
            return new DateTimeImmutable($epoch, new DateTimeZone('UTC'));
        } catch (\Throwable $error) {
            throw new InvalidArgumentException(
                sprintf('Invalid fixture epoch [%s]: %s', $epoch, $error->getMessage()),
                previous: $error
            );
        }
    }

    /**
     * Capture the current instant once for an unpinned run.
     *
     * @return self
     */
    public static function system(): self
    {
        return new self(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    /**
     * Return the immutable fixture epoch.
     *
     * @return DateTimeImmutable
     */
    public function epoch(): DateTimeImmutable
    {
        return $this->epoch;
    }

    /**
     * Resolve a relative or absolute boundary against the fixture epoch.
     *
     * DateTime values are preserved as explicit boundaries. String modifiers
     * such as `+1 week`, `next monday`, and `now` are evaluated from the epoch,
     * never from the process clock.
     *
     * @param string|DateTimeInterface $boundary
     * @return DateTimeImmutable
     */
    public function resolve(string|DateTimeInterface $boundary): DateTimeImmutable
    {
        if ($boundary instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($boundary);
        }

        $boundary = trim($boundary);
        if ($boundary === '') {
            throw new InvalidArgumentException('Fixture date boundary must not be empty.');
        }

        try {
            $resolved = $this->epoch->modify($boundary);
        } catch (\Throwable $error) {
            throw new InvalidArgumentException(
                sprintf('Invalid fixture date boundary [%s]: %s', $boundary, $error->getMessage()),
                previous: $error
            );
        }

        if ($resolved === false) {
            throw new InvalidArgumentException(sprintf('Invalid fixture date boundary [%s].', $boundary));
        }

        return $resolved;
    }
}

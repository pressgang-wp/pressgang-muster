<?php

namespace Faker;

use DateTimeImmutable;
use DateTimeInterface;

final class Generator
{
    private int $state = 1;

    /**
     * @param int $seed
     * @return void
     */
    public function seed(int $seed): void
    {
        $this->state = max(1, $seed);
    }

    /**
     * @param int $words
     * @return string
     */
    public function sentence(int $words = 6): string
    {
        $parts = [];

        for ($i = 0; $i < $words; $i++) {
            $parts[] = $this->word();
        }

        return ucfirst(implode(' ', $parts)) . '.';
    }

    /**
     * @param int $count
     * @return array<int, string>
     */
    public function paragraphs(int $count = 3): array
    {
        $out = [];

        for ($i = 0; $i < $count; $i++) {
            $out[] = $this->sentence(10) . ' ' . $this->sentence(8);
        }

        return $out;
    }

    /**
     * @param int $count
     * @param bool $asText
     * @return array<int, string>|string
     */
    public function words(int $count = 3, bool $asText = false): array|string
    {
        $words = [];
        for ($i = 0; $i < $count; $i++) {
            $words[] = $this->word();
        }

        if ($asText) {
            return implode(' ', $words);
        }

        return $words;
    }

    /**
     * @return string
     */
    public function slug(): string
    {
        return strtolower(str_replace(' ', '-', (string) $this->words(3, true)));
    }

    /**
     * @return string
     */
    public function name(): string
    {
        return ucfirst($this->word()) . ' ' . ucfirst($this->word());
    }

    /**
     * @return string
     */
    public function company(): string
    {
        return ucfirst($this->word()) . ' Ltd';
    }

    /**
     * @return string
     */
    public function safeEmail(): string
    {
        return $this->word() . '@example.test';
    }

    /**
     * @return string
     */
    public function email(): string
    {
        return $this->safeEmail();
    }

    /**
     * @return string
     */
    public function url(): string
    {
        return 'https://example.test/' . $this->slug();
    }

    /**
     * @param string $mask
     * @return string
     */
    public function numerify(string $mask): string
    {
        $out = '';
        $len = strlen($mask);

        for ($i = 0; $i < $len; $i++) {
            $char = $mask[$i];
            $out .= $char === '#' ? (string) $this->nextInt(0, 9) : $char;
        }

        return $out;
    }

    /**
     * @return string
     */
    public function postcode(): string
    {
        return 'BS1 ' . $this->nextInt(1, 9) . 'AA';
    }

    /**
     * @return string
     */
    public function city(): string
    {
        return ucfirst($this->word());
    }

    /**
     * @param string $format
     * @return string
     */
    public function date(string $format = 'Y-m-d'): string
    {
        return $this->dateTime()->format($format);
    }

    /**
     * @return DateTimeInterface
     */
    public function dateTime(): DateTimeInterface
    {
        return $this->dateTimeBetween('-1 year', '+1 year');
    }

    /**
     * @param string $from
     * @param string $to
     * @return DateTimeInterface
     */
    public function dateTimeBetween(string $from, string $to): DateTimeInterface
    {
        $base = new DateTimeImmutable('2026-01-01 00:00:00');
        $fromDate = $this->parseDate($base, $from);
        $toDate = $this->parseDate($base, $to);

        $fromTs = $fromDate->getTimestamp();
        $toTs = $toDate->getTimestamp();

        if ($toTs < $fromTs) {
            [$fromTs, $toTs] = [$toTs, $fromTs];
        }

        $ts = $this->nextInt($fromTs, $toTs);

        return (new DateTimeImmutable())->setTimestamp($ts);
    }

    /**
     * @param DateTimeImmutable $base
     * @param string $value
     * @return DateTimeImmutable
     */
    private function parseDate(DateTimeImmutable $base, string $value): DateTimeImmutable
    {
        $direct = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value)
            ?: DateTimeImmutable::createFromFormat('Y-m-d', $value);

        if ($direct instanceof DateTimeImmutable) {
            return $direct;
        }

        return $base->modify($value);
    }

    /**
     * @return string
     */
    private function word(): string
    {
        $words = [
            'harbour',
            'signal',
            'anchor',
            'deck',
            'beacon',
            'voyage',
            'windward',
            'meridian',
            'chart',
            'quarter',
            'muster',
            'keel',
        ];

        return $words[$this->nextInt(0, count($words) - 1)];
    }

    /**
     * @param int $min
     * @param int $max
     * @return int
     */
    private function nextInt(int $min, int $max): int
    {
        $this->state = (1103515245 * $this->state + 12345) & 0x7fffffff;
        $range = ($max - $min) + 1;

        return $min + ($this->state % $range);
    }
}

final class Factory
{
    /**
     * @param string $locale
     * @return Generator
     */
    public static function create(string $locale = 'en_GB'): Generator
    {
        unset($locale);

        return new Generator();
    }
}

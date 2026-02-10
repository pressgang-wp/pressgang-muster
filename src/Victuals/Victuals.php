<?php

namespace PressGang\Muster\Victuals;

/**
 * Curated Faker wrapper for deterministic, project-friendly generated values.
 *
 * This class exposes a stable set of helper methods so seed scripts avoid direct
 * dependence on low-level Faker formatters.
 */
final class Victuals
{
    public function __construct(private \Faker\Generator $faker)
    {
    }

    /**
     * Generate a short headline-style sentence.
     *
     * @return string
     */
    public function headline(): string
    {
        return $this->faker->sentence(6);
    }

    /**
     * Generate one sentence with a preferred word count.
     *
     * @param int $words
     * @return string
     */
    public function sentence(int $words = 6): string
    {
        return $this->faker->sentence($words);
    }

    /**
     * Generate multiple paragraphs joined by blank lines.
     *
     * @param int $count
     * @return string
     */
    public function paragraphs(int $count = 3): string
    {
        return implode("\n\n", $this->faker->paragraphs($count));
    }

    /**
     * Generate body content as joined paragraphs.
     *
     * @param int $paragraphs
     * @return string
     */
    public function content(int $paragraphs = 3): string
    {
        return $this->paragraphs($paragraphs);
    }

    /**
     * Generate a short excerpt-like text fragment.
     *
     * @param int $words
     * @return string
     */
    public function excerpt(int $words = 20): string
    {
        if (method_exists($this->faker, 'words')) {
            return $this->faker->words($words, true);
        }

        return $this->sentence($words);
    }

    /**
     * Generate a slug from source text or Faker fallback.
     *
     * @param string|null $from
     * @return string
     */
    public function slug(?string $from = null): string
    {
        if ($from !== null && $from !== '') {
            return strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $from), '-'));
        }

        if (method_exists($this->faker, 'slug')) {
            return (string) $this->faker->slug();
        }

        return strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $this->sentence(4)), '-'));
    }

    /**
     * Generate a person name.
     *
     * @return string
     */
    public function name(): string
    {
        return (string) $this->faker->name();
    }

    /**
     * Generate a company name.
     *
     * @return string
     */
    public function company(): string
    {
        return (string) $this->faker->company();
    }

    /**
     * Generate a safe email address.
     *
     * @return string
     */
    public function email(): string
    {
        if (method_exists($this->faker, 'safeEmail')) {
            return (string) $this->faker->safeEmail();
        }

        return (string) $this->faker->email();
    }

    /**
     * Generate a URL.
     *
     * @return string
     */
    public function url(): string
    {
        return (string) $this->faker->url();
    }

    /**
     * Generate a UK-style phone number shape.
     *
     * @return string
     */
    public function ukPhone(): string
    {
        if (method_exists($this->faker, 'numerify')) {
            return (string) $this->faker->numerify('0##########');
        }

        return '07' . str_pad((string) mt_rand(0, 999999999), 9, '0', STR_PAD_LEFT);
    }

    /**
     * Generate a UK-style postcode.
     *
     * @return string
     */
    public function ukPostcode(): string
    {
        if (method_exists($this->faker, 'postcode')) {
            return (string) $this->faker->postcode();
        }

        return 'SW1A 1AA';
    }

    /**
     * Generate a UK-style town or city name.
     *
     * @return string
     */
    public function ukTown(): string
    {
        if (method_exists($this->faker, 'city')) {
            return (string) $this->faker->city();
        }

        return 'Bristol';
    }

    /**
     * Generate a formatted date string.
     *
     * @param string $format
     * @return string
     */
    public function date(string $format = 'Y-m-d'): string
    {
        if (method_exists($this->faker, 'date')) {
            return (string) $this->faker->date($format);
        }

        return (new \DateTimeImmutable())->format($format);
    }

    /**
     * Generate a formatted datetime string.
     *
     * @param string $format
     * @return string
     */
    public function datetime(string $format = 'Y-m-d H:i:s'): string
    {
        if (method_exists($this->faker, 'dateTime')) {
            return $this->faker->dateTime()->format($format);
        }

        return (new \DateTimeImmutable())->format($format);
    }

    /**
     * Generate a datetime constrained between two relative/absolute boundaries.
     *
     * @param string $from
     * @param string $to
     * @return \DateTimeInterface
     *
     * See: https://fakerphp.org/formatters/date-and-time/#datetimebetween
     */
    public function dateBetween(string $from, string $to): \DateTimeInterface
    {
        return $this->faker->dateTimeBetween($from, $to);
    }

    /**
     * Access the underlying Faker generator for advanced cases.
     *
     * @return \Faker\Generator
     */
    public function raw(): \Faker\Generator
    {
        return $this->faker;
    }
}

<?php

namespace PressGang\Muster\Victuals;

/**
 * Curated Faker wrapper for deterministic, project-friendly generated values.
 */
final class Victuals
{
    public function __construct(private \Faker\Generator $faker)
    {
    }

    /**
     * @return string
     */
    public function headline(): string
    {
        return '';
    }

    /**
     * @param int $words
     * @return string
     */
    public function sentence(int $words = 6): string
    {
        return '';
    }

    /**
     * @param int $count
     * @return string
     */
    public function paragraphs(int $count = 3): string
    {
        return '';
    }

    /**
     * @param int $paragraphs
     * @return string
     */
    public function content(int $paragraphs = 3): string
    {
        return '';
    }

    /**
     * @param int $words
     * @return string
     */
    public function excerpt(int $words = 20): string
    {
        return '';
    }

    /**
     * @param string|null $from
     * @return string
     */
    public function slug(?string $from = null): string
    {
        return '';
    }

    /**
     * @return string
     */
    public function name(): string
    {
        return '';
    }

    /**
     * @return string
     */
    public function company(): string
    {
        return '';
    }

    /**
     * @return string
     */
    public function email(): string
    {
        return '';
    }

    /**
     * @return string
     */
    public function url(): string
    {
        return '';
    }

    /**
     * @return string
     */
    public function ukPhone(): string
    {
        return '';
    }

    /**
     * @return string
     */
    public function ukPostcode(): string
    {
        return '';
    }

    /**
     * @return string
     */
    public function ukTown(): string
    {
        return '';
    }

    /**
     * @param string $format
     * @return string
     */
    public function date(string $format = 'Y-m-d'): string
    {
        return '';
    }

    /**
     * @param string $format
     * @return string
     */
    public function datetime(string $format = 'Y-m-d H:i:s'): string
    {
        return '';
    }

    /**
     * @param string $from
     * @param string $to
     * @return \DateTimeInterface
     */
    public function dateBetween(string $from, string $to): \DateTimeInterface
    {
        return new \DateTimeImmutable();
    }

    /**
     * @return \Faker\Generator
     */
    public function raw(): \Faker\Generator
    {
        return $this->faker;
    }
}

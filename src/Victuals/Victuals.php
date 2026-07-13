<?php

namespace PressGang\Muster\Victuals;

use DateTimeInterface;
use LogicException;
use PressGang\Muster\Clock\FixtureClock;

/**
 * Curated Faker wrapper for deterministic, project-friendly generated values.
 *
 * This class exposes a stable set of helper methods so seed scripts avoid direct
 * dependence on low-level Faker formatters.
 */
final class Victuals
{
    private FixtureClock $clock;

    public function __construct(private \Faker\Generator $faker, ?FixtureClock $clock = null)
    {
        $this->clock = $clock ?? FixtureClock::system();
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
     * Generate a self-contained deterministic SVG data URL.
     *
     * The colour consumes the seeded Victuals stream. No remote placeholder
     * service or network request is involved, making the URL suitable for
     * HTML/ACF URL fixtures and stable visual test input.
     *
     * @param int $width
     * @param int $height
     * @param string|null $label
     * @return string
     */
    public function imageUrl(int $width = 1200, int $height = 800, ?string $label = null): string
    {
        if ($width < 1 || $height < 1) {
            throw new LogicException('Victuals image dimensions must be positive integers.');
        }

        $colour = (string) $this->faker->hexColor();
        $text = htmlspecialchars($label ?? sprintf('%d × %d', $width, $height), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d"><rect width="100%%" height="100%%" fill="%s"/><text x="50%%" y="50%%" dominant-baseline="middle" text-anchor="middle" fill="#fff" font-family="sans-serif" font-size="%d">%s</text></svg>',
            $width,
            $height,
            $width,
            $height,
            $colour,
            max(12, (int) floor(min($width, $height) / 12)),
            $text
        );

        return 'data:image/svg+xml;charset=UTF-8,' . rawurlencode($svg);
    }

    /**
     * Generate valid serialized Gutenberg heading and paragraph blocks.
     *
     * @param int $paragraphs Number of paragraph blocks after the heading.
     * @return string
     */
    public function gutenbergBlocks(int $paragraphs = 3): string
    {
        if ($paragraphs < 1) {
            throw new LogicException('Victuals Gutenberg content requires at least one paragraph.');
        }

        $blocks = [sprintf(
            "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">%s</h2>\n<!-- /wp:heading -->",
            $this->escapeHtml($this->headline())
        )];

        for ($i = 0; $i < $paragraphs; $i++) {
            $blocks[] = sprintf(
                "<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->",
                $this->escapeHtml($this->faker->paragraph())
            );
        }

        return implode("\n\n", $blocks);
    }

    /**
     * Generate deterministic semantic HTML with varied editorial structures.
     *
     * @param int $sections Number of heading/paragraph sections.
     * @return string
     */
    public function richContent(int $sections = 3): string
    {
        if ($sections < 1) {
            throw new LogicException('Victuals rich content requires at least one section.');
        }

        $html = [];
        for ($i = 0; $i < $sections; $i++) {
            $html[] = sprintf('<h2>%s</h2>', $this->escapeHtml($this->headline()));
            $html[] = sprintf('<p>%s</p>', $this->escapeHtml($this->faker->paragraph()));
        }

        $items = $this->faker->words(3);
        $html[] = '<ul>' . implode('', array_map(
            fn (mixed $item): string => sprintf('<li>%s</li>', $this->escapeHtml((string) $item)),
            $items
        )) . '</ul>';
        $html[] = sprintf('<blockquote><p>%s</p></blockquote>', $this->escapeHtml($this->sentence(10)));
        $html[] = sprintf(
            '<p><a href="%s">%s</a></p>',
            $this->escapeHtml($this->url()),
            $this->escapeHtml($this->sentence(3))
        );

        return implode("\n", $html);
    }

    /**
     * Generate explicit structured rows for an ACF repeater declaration.
     *
     * Callable schema values receive this Victuals instance and the one-based
     * row index. Scalar/array values are copied unchanged into every row.
     *
     * @param int $count
     * @param array<string, mixed|callable(self, int): mixed> $schema
     * @return array<int, array<string, mixed>>
     */
    public function repeaterRows(int $count, array $schema): array
    {
        if ($count < 1) {
            throw new LogicException('Victuals repeaterRows() count must be at least 1.');
        }
        if ($schema === []) {
            throw new LogicException('Victuals repeaterRows() schema must not be empty.');
        }

        foreach (array_keys($schema) as $field) {
            if (!is_string($field) || trim($field) === '') {
                throw new LogicException('Victuals repeaterRows() schema keys must be non-empty strings.');
            }
        }

        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $row = [];
            foreach ($schema as $field => $value) {
                $row[$field] = is_callable($value) ? $value($this, $i) : $value;
            }
            $rows[] = $row;
        }

        return $rows;
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
        return $this->dateBetween('-30 years', 'now')->format($format);
    }

    /**
     * Generate a formatted datetime string.
     *
     * @param string $format
     * @return string
     */
    public function datetime(string $format = 'Y-m-d H:i:s'): string
    {
        return $this->dateBetween('-30 years', 'now')->format($format);
    }

    /**
     * Generate a datetime constrained between two relative/absolute boundaries.
     *
     * Relative strings resolve from the fixture epoch, not the process clock.
     *
     * @param string|DateTimeInterface $from
     * @param string|DateTimeInterface $to
     * @return DateTimeInterface
     *
     * See: https://fakerphp.org/formatters/date-and-time/#datetimebetween
     */
    public function dateBetween(string|DateTimeInterface $from, string|DateTimeInterface $to): DateTimeInterface
    {
        return $this->faker->dateTimeBetween(
            $this->clock->resolve($from)->format('Y-m-d H:i:s.uP'),
            $this->clock->resolve($to)->format('Y-m-d H:i:s.uP'),
            $this->clock->epoch()->getTimezone()->getName()
        );
    }

    /**
     * Return the immutable epoch used by date helpers.
     *
     * @return \DateTimeImmutable
     */
    public function epoch(): \DateTimeImmutable
    {
        return $this->clock->epoch();
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

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

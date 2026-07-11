<?php

namespace PressGang\Muster\Victuals;

/**
 * Factory for creating deterministic Victuals instances.
 *
 * Locale defaults to `en_GB` so generated data better matches UK-first content
 * assumptions used by PressGang projects.
 */
final class VictualsFactory
{
    /**
     * @param string $locale
     */
    public function __construct(private string $locale = 'en_GB')
    {
    }

    /**
     * Build a Victuals wrapper around a seeded Faker generator.
     *
     * When a seed is provided, repeated calls with the same seed produce the same
     * output sequence for supported Faker formatters.
     *
     * Caveat: Faker's seed() drives PHP's global mt_rand stream, so determinism
     * is per SEQUENCE of draws, not per coexisting instance — two live
     * same-seed instances interleaving draws will diverge. Muster's own flows
     * (one context, sequential draws) stay within this contract.
     *
     * @param int|null $seed
     * @return Victuals
     */
    public function make(?int $seed = null): Victuals
    {
        $faker = \Faker\Factory::create($this->locale);

        if ($seed !== null) {
            $faker->seed($seed);
        }

        return new Victuals($faker);
    }
}

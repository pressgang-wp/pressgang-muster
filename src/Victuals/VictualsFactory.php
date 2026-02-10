<?php

namespace PressGang\Muster\Victuals;

/**
 * Factory for creating seeded Victuals instances.
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

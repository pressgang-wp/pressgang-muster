<?php

namespace PressGang\Muster\Patterns;

use PressGang\Muster\Builders\PostBuilder;
use PressGang\Muster\Muster;
use UnexpectedValueException;

/**
 * Coordinates pattern iterations and per-pattern deterministic scope.
 */
final class PatternRunner
{
    /**
     * @param Pattern $pattern
     * @param callable(int): PostBuilder $builder
     * @param Muster $muster
     * @return void
     *
     * Each run receives a fresh seeded Victuals instance scoped to the Muster lifecycle
     * for this pattern execution only.
     *
     * @throws UnexpectedValueException If the callable does not return a PostBuilder.
     */
    public function run(Pattern $pattern, callable $builder, Muster $muster): void
    {
        $iterations = $pattern->iterations();
        $seed = $pattern->effectiveSeed();
        $victuals = $pattern->context()->victualsForSeed($seed);

        if ($pattern->context()->dryRun()) {
            $pattern->context()->logger()->info(
                sprintf('Dry run pattern [%s] for %d iterations.', $pattern->name(), $iterations)
            );

            return;
        }

        $muster->beginPatternVictualsScope($victuals);

        try {
            for ($i = 1; $i <= $iterations; $i++) {
                $result = $builder($i);

                if (!$result instanceof PostBuilder) {
                    throw new UnexpectedValueException('Pattern builder must return PostBuilder for this slice.');
                }

                $result->save();
            }
        } finally {
            $muster->endPatternVictualsScope();
        }

        $pattern->context()->logger()->debug(
            sprintf('Pattern [%s] completed with seed [%s].', $pattern->name(), (string) $seed)
        );
    }
}

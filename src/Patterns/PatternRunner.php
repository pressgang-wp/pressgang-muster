<?php

namespace PressGang\Muster\Patterns;

use PressGang\Muster\Contracts\PersistableDeclaration;
use PressGang\Muster\Muster;
use UnexpectedValueException;

/**
 * Coordinates pattern iterations and per-pattern deterministic scope.
 */
final class PatternRunner
{
    use AssertsDeclarations;

    /**
     * @param Pattern $pattern
     * @param callable(int): PersistableDeclaration $builder
     * @param Muster $muster
     * @return void
     *
     * Each run receives a fresh seeded Victuals instance scoped to the Muster lifecycle
     * for this pattern execution only.
     *
     * @throws UnexpectedValueException If the callable does not return a persistable declaration.
     */
    public function run(Pattern $pattern, callable $builder, Muster $muster): void
    {
        $iterations = $pattern->iterations();
        $seed = $pattern->effectiveSeed();
        $victuals = $pattern->context()->victualsForSeed($seed);

        if ($pattern->context()->dryRun()) {
            $pattern->context()->logger()->info(
                sprintf('Planning pattern [%s] for %d iterations.', $pattern->name(), $iterations)
            );
        }

        $muster->beginPatternVictualsScope($victuals);

        try {
            for ($i = 1; $i <= $iterations; $i++) {
                $this->executeIteration($pattern, $builder, $i, $iterations);
            }
        } finally {
            $muster->endPatternVictualsScope();
        }

        $pattern->context()->logger()->debug(
            sprintf('Pattern [%s] completed with seed [%s].', $pattern->name(), (string) $seed)
        );
    }

    /**
     * Build, persist, and post-process one pattern iteration.
     *
     * @param Pattern $pattern
     * @param callable(int): PersistableDeclaration $builder
     * @param int $iteration One-based iteration index.
     * @param int $total Total declared iterations, for progress logging.
     * @return void
     */
    private function executeIteration(Pattern $pattern, callable $builder, int $iteration, int $total): void
    {
        $result = $this->assertDeclaration(
            $builder($iteration),
            sprintf('Pattern [%s] iteration %d', $pattern->name(), $iteration)
        );

        $ref = $result->save();
        $this->runAfterHooks($pattern, $ref, $iteration);
        $pattern->context()->logger()->progress($pattern->name(), $iteration, $total);
    }

    private function runAfterHooks(Pattern $pattern, object $ref, int $iteration): void
    {
        foreach ($pattern->afterHooks() as $name => $hook) {
            $result = $hook($ref, $iteration);
            if ($result === null) {
                continue;
            }

            if ($result instanceof PersistableDeclaration) {
                $result->save();
                continue;
            }

            if (is_iterable($result)) {
                foreach ($result as $position => $declaration) {
                    $this->assertDeclaration($declaration, sprintf(
                        'Pattern [%s] after-hook [%s] item [%s]',
                        $pattern->name(),
                        $name,
                        (string) $position
                    ))->save();
                }

                continue;
            }

            throw new UnexpectedValueException(sprintf(
                'Pattern [%s] after-hook [%s] must return PersistableDeclaration, iterable, or null; received [%s].',
                $pattern->name(),
                $name,
                get_debug_type($result)
            ));
        }
    }
}

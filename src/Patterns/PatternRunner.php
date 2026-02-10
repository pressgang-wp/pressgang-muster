<?php

namespace PressGang\Muster\Patterns;

use PressGang\Muster\Builders\OptionBuilder;
use PressGang\Muster\Builders\PostBuilder;
use PressGang\Muster\Builders\TermBuilder;
use PressGang\Muster\Builders\UserBuilder;
use PressGang\Muster\Muster;

/**
 * Coordinates pattern iterations and per-pattern deterministic scope.
 */
final class PatternRunner
{
    /**
     * @param Pattern $pattern
     * @param callable(int, Muster): PostBuilder|TermBuilder|UserBuilder|OptionBuilder $builder
     * @param Muster $muster
     * @return void
     */
    public function run(Pattern $pattern, callable $builder, Muster $muster): void
    {
        $iterations = $pattern->iterations();
        $seed = $pattern->effectiveSeed();

        if ($pattern->context()->dryRun()) {
            $pattern->context()->logger()->info(
                sprintf('Dry run pattern [%s] for %d iterations.', $pattern->name(), $iterations)
            );

            return;
        }

        for ($i = 1; $i <= $iterations; $i++) {
            $result = $builder($i, $muster);

            if (is_object($result) && method_exists($result, 'save')) {
                $result->save();
            }
        }

        $pattern->context()->logger()->debug(
            sprintf('Pattern [%s] completed with seed [%s].', $pattern->name(), (string) $seed)
        );
    }
}

<?php

namespace PressGang\Muster\Patterns;

use PressGang\Muster\Contracts\PersistableDeclaration;
use UnexpectedValueException;

/**
 * Shared fail-fast check that a callable honoured the declaration contract.
 *
 * Pattern factories, Recipe factories and states, and after-hooks all
 * promise to return a PersistableDeclaration without persisting anything
 * themselves. This trait centralises the loud failure when that promise is
 * broken, so every violation reads the same way regardless of where the
 * callable was registered.
 */
trait AssertsDeclarations
{
    /**
     * Assert that a callable's return value is a persistable declaration.
     *
     * @param mixed $value The callable's return value.
     * @param string $source Human-readable description of the callable for the
     *        exception message, e.g. "Pattern [events] iteration 3".
     * @return PersistableDeclaration The value, confirmed as a declaration.
     * @throws UnexpectedValueException If the value is anything else.
     */
    private function assertDeclaration(mixed $value, string $source): PersistableDeclaration
    {
        if (!$value instanceof PersistableDeclaration) {
            throw new UnexpectedValueException(sprintf(
                '%s must return PersistableDeclaration; received [%s].',
                $source,
                get_debug_type($value)
            ));
        }

        return $value;
    }
}

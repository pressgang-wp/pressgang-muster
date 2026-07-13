<?php

namespace PressGang\Muster\Refs;

/**
 * Immutable WordPress option reference returned by option save operations.
 */
final class OptionRef
{
    public function __construct(private string $name)
    {
    }

    public function name(): string
    {
        return $this->name;
    }
}

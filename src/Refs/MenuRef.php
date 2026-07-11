<?php

namespace PressGang\Muster\Refs;

/**
 * Immutable menu reference returned by menu save operations.
 */
final class MenuRef
{
    public function __construct(
        private int $id,
        private string $name,
    ) {
    }

    /**
     * @return int
     */
    public function id(): int
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function name(): string
    {
        return $this->name;
    }
}

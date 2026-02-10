<?php

namespace PressGang\Muster\Refs;

/**
 * Immutable term reference returned by term save operations.
 */
final class TermRef
{
    public function __construct(
        private int $termId,
        private string $taxonomy,
        private string $slug,
    ) {
    }

    /**
     * @return int
     */
    public function termId(): int
    {
        return $this->termId;
    }

    /**
     * @return string
     */
    public function taxonomy(): string
    {
        return $this->taxonomy;
    }

    /**
     * @return string
     */
    public function slug(): string
    {
        return $this->slug;
    }
}

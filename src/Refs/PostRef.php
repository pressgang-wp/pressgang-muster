<?php

namespace PressGang\Muster\Refs;

/**
 * Immutable post reference returned by post save operations.
 */
final class PostRef
{
    public function __construct(
        private int $id,
        private string $postType,
        private string $slug,
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
    public function postType(): string
    {
        return $this->postType;
    }

    /**
     * @return string
     */
    public function slug(): string
    {
        return $this->slug;
    }
}

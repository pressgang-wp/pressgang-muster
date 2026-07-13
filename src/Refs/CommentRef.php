<?php

namespace PressGang\Muster\Refs;

/**
 * Immutable comment reference returned by comment save operations.
 */
final class CommentRef
{
    public function __construct(private int $id, private int $postId)
    {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function postId(): int
    {
        return $this->postId;
    }
}

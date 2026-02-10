<?php

namespace PressGang\Muster\Refs;

/**
 * Immutable user reference returned by user save operations.
 */
final class UserRef
{
    public function __construct(
        private int $userId,
        private string $login,
    ) {
    }

    /**
     * @return int
     */
    public function userId(): int
    {
        return $this->userId;
    }

    /**
     * @return string
     */
    public function login(): string
    {
        return $this->login;
    }
}

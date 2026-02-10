<?php

namespace PressGang\Muster\Builders;

use PressGang\Muster\MusterContext;
use PressGang\Muster\Refs\UserRef;

/**
 * Fluent user builder with idempotent-upsert intent.
 */
final class UserBuilder
{
    /**
     * @var array<string, mixed>
     */
    private array $payload = [];

    /**
     * @param MusterContext $context
     * @param string|null $login
     */
    public function __construct(private MusterContext $context, ?string $login = null)
    {
        if ($login !== null) {
            $this->payload['user_login'] = $login;
        }
    }

    /**
     * @param string $login
     * @return self
     */
    public function login(string $login): self
    {
        $this->payload['user_login'] = $login;

        return $this;
    }

    /**
     * @param string $email
     * @return self
     */
    public function email(string $email): self
    {
        $this->payload['user_email'] = $email;

        return $this;
    }

    /**
     * @param string $name
     * @return self
     */
    public function displayName(string $name): self
    {
        $this->payload['display_name'] = $name;

        return $this;
    }

    /**
     * @param string $role
     * @return self
     */
    public function role(string $role): self
    {
        $this->payload['role'] = $role;

        return $this;
    }

    /**
     * @param array<string, mixed> $meta
     * @return self
     */
    public function meta(array $meta): self
    {
        $this->payload['meta'] = $meta;

        return $this;
    }

    /**
     * @return UserRef
     */
    public function save(): UserRef
    {
        return new UserRef(0, (string) ($this->payload['user_login'] ?? ''));
    }
}

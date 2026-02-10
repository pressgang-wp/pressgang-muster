<?php

namespace PressGang\Muster\Builders;

use LogicException;
use RuntimeException;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Refs\UserRef;

/**
 * Fluent user builder with idempotent-upsert intent.
 *
 * This builder targets WordPress users and persists with deterministic identity:
 * `user_login`.
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
     * Set explicit user login.
     *
     * This mutates builder state only and does not write to WordPress.
     *
     * @param string $login
     * @return self
     */
    public function login(string $login): self
    {
        $this->payload['user_login'] = $login;

        return $this;
    }

    /**
     * Set user email.
     *
     * This mutates builder state only and does not write to WordPress.
     *
     * @param string $email
     * @return self
     */
    public function email(string $email): self
    {
        $this->payload['user_email'] = $email;

        return $this;
    }

    /**
     * Set display name.
     *
     * This mutates builder state only and does not write to WordPress.
     *
     * @param string $name
     * @return self
     */
    public function displayName(string $name): self
    {
        $this->payload['display_name'] = $name;

        return $this;
    }

    /**
     * Set user role.
     *
     * This mutates builder state only and does not write to WordPress.
     *
     * @param string $role
     * @return self
     */
    public function role(string $role): self
    {
        $this->payload['role'] = $role;

        return $this;
    }

    /**
     * Set user meta payload to be applied during `save()`.
     *
     * This mutates builder state only and does not write to WordPress.
     *
     * @param array<string, mixed> $meta
     * @return self
     */
    public function meta(array $meta): self
    {
        $this->payload['meta'] = $meta;

        return $this;
    }

    /**
     * Persist the user to WordPress via idempotent upsert.
     *
     * Identity rule: `user_login`.
     * Existing users are updated via `wp_update_user()`, missing users are inserted
     * with `wp_insert_user()`. User meta is applied using `update_user_meta()`.
     *
     * See: https://developer.wordpress.org/reference/functions/get_user_by/
     * See: https://developer.wordpress.org/reference/functions/wp_update_user/
     * See: https://developer.wordpress.org/reference/functions/wp_insert_user/
     * See: https://developer.wordpress.org/reference/functions/update_user_meta/
     *
     * @return UserRef
     * @throws LogicException If login cannot be resolved from configured fields.
     * @throws RuntimeException If WordPress runtime functions are unavailable or save fails.
     */
    public function save(): UserRef
    {
        $login = $this->resolveLogin();

        if ($this->context->dryRun()) {
            $this->context->logger()->info(sprintf('Dry run user upsert [%s].', $login));

            return new UserRef(0, $login);
        }

        if (!function_exists('get_user_by') || !function_exists('wp_insert_user') || !function_exists('wp_update_user')) {
            throw new RuntimeException('WordPress user runtime functions are required to save users.');
        }

        $existing = get_user_by('login', $login);

        $attributes = [
            'user_login' => $login,
            'user_email' => (string) ($this->payload['user_email'] ?? ''),
            'display_name' => (string) ($this->payload['display_name'] ?? ''),
            'role' => (string) ($this->payload['role'] ?? ''),
        ];

        /** @var int|\WP_Error $result */
        $result = 0;
        $action = 'created';

        if ($existing !== false && isset($existing->ID)) {
            $attributes['ID'] = (int) $existing->ID;
            $result = wp_update_user($attributes);
            $action = 'updated';
        } else {
            $result = wp_insert_user($attributes);
        }

        if ((function_exists('is_wp_error') && is_wp_error($result)) || !is_int($result) || $result <= 0) {
            throw new RuntimeException('Failed to save user.');
        }

        $userId = $result;

        $meta = $this->payload['meta'] ?? [];
        if (is_array($meta) && function_exists('update_user_meta')) {
            foreach ($meta as $key => $value) {
                update_user_meta($userId, (string) $key, $value);
            }
        }

        $this->context->logger()->debug(sprintf('User %s [%s] as ID %d.', $action, $login, $userId));

        return new UserRef($userId, $login);
    }

    /**
     * Resolve effective user login used for identity.
     *
     * Prefers explicit `login()`, then derives from email local-part.
     *
     * @return string
     * @throws LogicException If login cannot be resolved.
     */
    private function resolveLogin(): string
    {
        $login = (string) ($this->payload['user_login'] ?? '');
        if ($login !== '') {
            return $login;
        }

        $email = (string) ($this->payload['user_email'] ?? '');
        if ($email !== '' && str_contains($email, '@')) {
            return explode('@', $email, 2)[0];
        }

        throw new LogicException('User login is required when email is not set.');
    }
}

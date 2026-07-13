<?php

namespace PressGang\Muster\Builders;

use PressGang\Muster\Contracts\PersistableDeclaration;
use LogicException;
use RuntimeException;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Ownership\HasOwnership;
use PressGang\Muster\Ownership\OwnedResource;
use PressGang\Muster\Ownership\ResolvesIdentity;
use PressGang\Muster\Refs\UserRef;
use PressGang\Muster\Results\OperationAction;
use PressGang\Muster\Support\WpMeta;
use PressGang\Muster\Support\WpResult;

/**
 * Fluent user builder with idempotent merge-upsert behaviour.
 *
 * Muster-scoped builders use an explicit logical key; `user_login` is the
 * immutable WordPress locator. Existing users retain values for fields not set
 * on this builder; passing an empty value explicitly clears that field. New
 * users require an explicit create-only password.
 */
final class UserBuilder implements PersistableDeclaration
{
    use HasOwnership;
    use ResolvesIdentity;

    /**
     * @var array<string, mixed>
     */
    private array $payload = [];

    /**
     * @param MusterContext $context
     * @param string|null $login
     * @param string|null $ownershipScope
     */
    public function __construct(
        private MusterContext $context,
        ?string $login = null,
        ?string $ownershipScope = null,
    ) {
        $this->initializeOwnership($ownershipScope);

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
     * Set the initial password required when WordPress creates the user.
     *
     * The password is create-only. Muster deliberately leaves credentials
     * untouched on later runs because WordPress stores only a one-way hash and
     * cannot prove that a declared plaintext password is already satisfied.
     *
     * @param string $password
     * @return self
     */
    public function password(string $password): self
    {
        $this->payload['user_pass'] = $password;

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
     * Managed identity is `Muster class + logical key`; the immutable WordPress
     * locator is `user_login`. Unowned locator matches require `adopt()`.
     * Existing users are updated via `wp_update_user()`, missing users are inserted
     * with `wp_insert_user()` and require `password()`. User meta is applied
     * using `update_user_meta()`; existing credentials are never reset.
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
        $intent = $this->ownershipIntent();

        if (!function_exists('get_user_by')) {
            throw new RuntimeException('get_user_by() is required to plan or save users.');
        }

        ['existing' => $existing, 'owned' => $owned] = $this->resolveIdentity(
            $this->context,
            $intent,
            'user',
            'user',
            $login,
            findNatural: static function () use ($login): ?object {
                $user = get_user_by('login', $login);

                return is_object($user) && isset($user->ID) ? $user : null;
            },
            resolveOwned: fn (OwnedResource $owned): ?object => $this->resolveOwnedUser($owned, $login, $intent),
            idOf: static fn (object $user): int => (int) $user->ID,
            conflictMessage: static fn (int $naturalId): string => sprintf(
                'User login [%s] belongs to a different user.',
                $login
            ),
        );

        $existingId = $existing === null ? null : (int) $existing->ID;

        if ($existingId === null
            && (!array_key_exists('user_pass', $this->payload) || (string) $this->payload['user_pass'] === '')) {
            throw new LogicException(sprintf(
                'New user [%s] requires an explicit initial password via password().',
                $login
            ));
        }

        $attributes = $this->buildAttributes($login, $existingId === null);

        $this->context->debugDeclaration('User', [
            ...array_keys($attributes),
            ...array_map(static fn (string $key): string => 'meta.' . $key, array_keys((array) ($this->payload['meta'] ?? []))),
        ]);

        $operation = $this->userOperation($existing, $attributes, $owned);

        if ($this->context->dryRun()) {
            $plannedId = $existingId ?? 0;
            $this->finalizeUpsert($this->context, $intent, $operation, 'user', $plannedId, 'user', $login);

            return new UserRef($plannedId, $login);
        }

        if ($operation === OperationAction::Keep && $existingId !== null) {
            $this->finalizeUpsert($this->context, $intent, $operation, 'user', $existingId, 'user', $login);

            return new UserRef($existingId, $login);
        }

        $userId = $this->writeUser($existingId, $attributes);
        WpMeta::write('update_user_meta', $userId, $this->payload['meta'] ?? []);
        $this->finalizeUpsert($this->context, $intent, $operation, 'user', $userId, 'user', $login);

        $this->context->logger()->debug(sprintf('User %s [%s] as ID %d.', $operation->value, $login, $userId));

        return new UserRef($userId, $login);
    }

    /**
     * Look up the owned user and enforce login immutability.
     *
     * `user_login` cannot change on WordPress users, so an owned user whose
     * stored login differs from the declaration is a programming error, not a
     * reconciliation conflict.
     *
     * @param OwnedResource $owned
     * @param string $login Declared login.
     * @param array{scope: string, key: string, adopt: bool}|null $intent
     * @return object|null The live user, or null when deleted or planned-deleted.
     * @throws LogicException If the declaration changes the owned user's login.
     */
    private function resolveOwnedUser(OwnedResource $owned, string $login, ?array $intent): ?object
    {
        $user = get_user_by('id', $owned->id());
        if (!is_object($user) || !isset($user->ID)) {
            return null;
        }

        if ($this->context->isPlannedDeleted('user', (int) $user->ID, 'user', $owned->locator())) {
            return null;
        }

        if (isset($user->user_login) && (string) $user->user_login !== $login) {
            throw new LogicException(sprintf(
                'Owned user [%s:%s] cannot change login from [%s] to [%s].',
                $intent['scope'] ?? '',
                $intent['key'] ?? '',
                (string) $user->user_login,
                $login
            ));
        }

        return $user;
    }

    /**
     * Assemble the WordPress write attributes from declared builder state.
     *
     * The create-only password is included solely for inserts; credentials on
     * existing users are never reset.
     *
     * @param string $login
     * @param bool $creating Whether the save will insert a new user.
     * @return array<string, mixed>
     */
    private function buildAttributes(string $login, bool $creating): array
    {
        $attributes = ['user_login' => $login];
        if ($creating) {
            $attributes['user_pass'] = (string) $this->payload['user_pass'];
        }

        foreach (['user_email', 'display_name', 'role'] as $field) {
            if (array_key_exists($field, $this->payload)) {
                $attributes[$field] = (string) $this->payload[$field];
            }
        }

        return $attributes;
    }

    /**
     * Insert or update the core user record and return its ID.
     *
     * @param int|null $existingId
     * @param array<string, mixed> $attributes
     * @return int
     * @throws RuntimeException If write functions are unavailable or the save fails.
     */
    private function writeUser(?int $existingId, array $attributes): int
    {
        if (!function_exists('wp_insert_user') || !function_exists('wp_update_user')) {
            throw new RuntimeException('WordPress write functions are required to save users.');
        }

        if ($existingId !== null) {
            $attributes['ID'] = $existingId;
            $result = wp_update_user($attributes);
        } else {
            $result = wp_insert_user($attributes);
        }

        if (!WpResult::isId($result)) {
            throw new RuntimeException('Failed to save user.');
        }

        return (int) $result;
    }

    /**
     * @param object|null $existing
     * @param array<string, mixed> $attributes
     * @param OwnedResource|null $owned
     * @return OperationAction
     */
    private function userOperation(?object $existing, array $attributes, ?OwnedResource $owned): OperationAction
    {
        if ($existing === null) {
            if ($owned !== null && $this->context->ownership()->isPlannedClaim($owned->scope(), $owned->key())) {
                return OperationAction::Keep;
            }

            return OperationAction::Create;
        }

        if ($owned === null || !empty($this->payload['meta'])) {
            return OperationAction::Update;
        }

        foreach ($attributes as $field => $value) {
            if (!property_exists($existing, $field) || (string) $existing->{$field} !== (string) $value) {
                return OperationAction::Update;
            }
        }

        return OperationAction::Keep;
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

<?php

namespace PressGang\Muster\Ownership;

use RuntimeException;
use PressGang\Muster\MusterContext;

/**
 * Persists Muster logical ownership in one non-autoloaded WordPress option.
 *
 * Registry entries map a Muster class + logical key to the current WordPress
 * resource ID and locator. WordPress remains the source of truth for resource
 * data; the registry only records identity and ownership needed for collision
 * detection, locator changes, reset, and pruning.
 */
final class OwnershipRegistry
{
    public const OPTION = 'pressgang_muster_registry';

    /**
     * @var array<string, array<string, true>>
     */
    private array $touched = [];

    public function __construct(private MusterContext $context)
    {
    }

    public function find(string $scope, string $key): ?OwnedResource
    {
        $record = $this->records()[$scope][$key] ?? null;

        if ($record === null) {
            return null;
        }

        $resource = is_array($record) ? OwnedResource::fromArray($record) : null;
        if ($resource === null || $resource->scope() !== $scope || $resource->key() !== $key) {
            throw new RuntimeException(sprintf('Ownership registry entry [%s:%s] is malformed.', $scope, $key));
        }

        return $resource;
    }

    /**
     * @return array<string, OwnedResource>
     */
    public function forScope(string $scope): array
    {
        $owned = [];

        foreach ($this->records()[$scope] ?? [] as $key => $record) {
            if (!is_array($record)) {
                continue;
            }

            $resource = OwnedResource::fromArray($record);
            if ($resource === null || $resource->scope() !== $scope || $resource->key() !== (string) $key) {
                throw new RuntimeException(sprintf('Ownership registry entry [%s:%s] is malformed.', $scope, (string) $key));
            }

            $owned[(string) $key] = $resource;
        }

        return $owned;
    }

    /**
     * Fail unless an existing resource is already this claim or adoption was explicit.
     *
     * @throws OwnershipConflict
     */
    public function assertClaimable(OwnedResource $requested, bool $adopt): void
    {
        $claim = $this->findByResource($requested);

        if ($claim !== null) {
            if ($claim->scope() === $requested->scope() && $claim->key() === $requested->key()) {
                return;
            }

            throw new OwnershipConflict(sprintf(
                'Resource [%s:%s] is already owned by [%s:%s].',
                $requested->type(),
                $requested->locator(),
                $claim->scope(),
                $claim->key()
            ));
        }

        if (!$adopt) {
            throw new OwnershipConflict(sprintf(
                'Resource [%s:%s] already exists but is not Muster-owned; call adopt() to claim it as [%s:%s].',
                $requested->type(),
                $requested->locator(),
                $requested->scope(),
                $requested->key()
            ));
        }
    }

    /**
     * Record or refresh a successful resource claim.
     */
    public function record(OwnedResource $resource): void
    {
        $this->touched[$resource->scope()][$resource->key()] = true;

        if ($this->context->dryRun()) {
            return;
        }

        $records = $this->records();
        $records[$resource->scope()][$resource->key()] = $resource->toArray();
        $this->persist($records);
    }

    /**
     * Delete every resource owned by a Muster scope.
     *
     * @return int Number of resources selected for deletion.
     */
    public function reset(string $scope): int
    {
        unset($this->touched[$scope]);

        return $this->prune($scope, []);
    }

    /**
     * Delete owned resources neither touched this run nor explicitly retained.
     *
     * @param string $scope
     * @param array<int, string> $keepKeys
     * @return int Number of resources selected for deletion.
     */
    public function prune(string $scope, array $keepKeys = []): int
    {
        $owned = $this->forScope($scope);
        $keep = array_unique(array_merge(
            array_keys($this->touched[$scope] ?? []),
            array_values($keepKeys)
        ));
        $remove = array_diff(array_keys($owned), $keep);

        if ($this->context->dryRun()) {
            foreach ($remove as $key) {
                $this->context->logger()->info(sprintf('Dry run delete owned resource [%s:%s].', $scope, $key));
            }

            return count($remove);
        }

        $records = $this->records();
        $changed = false;

        try {
            foreach ($remove as $key) {
                $this->delete($owned[$key]);
                unset($records[$scope][$key]);
                $changed = true;
            }
        } finally {
            if ($changed) {
                if (($records[$scope] ?? []) === []) {
                    unset($records[$scope]);
                }

                $this->persist($records);
            }
        }

        unset($this->touched[$scope]);

        return count($remove);
    }

    private function findByResource(OwnedResource $requested): ?OwnedResource
    {
        foreach ($this->records() as $scopeRecords) {
            if (!is_array($scopeRecords)) {
                continue;
            }

            foreach ($scopeRecords as $record) {
                if (!is_array($record)) {
                    continue;
                }

                $owned = OwnedResource::fromArray($record);
                if ($owned === null) {
                    throw new RuntimeException('Ownership registry contains a malformed resource record.');
                }

                if ($this->resourceFamily($owned->type()) !== $this->resourceFamily($requested->type())) {
                    continue;
                }

                if ($requested->id() > 0 && $owned->id() === $requested->id()) {
                    return $owned;
                }

                if ($requested->id() === 0
                    && $owned->subtype() === $requested->subtype()
                    && $owned->locator() === $requested->locator()) {
                    return $owned;
                }
            }
        }

        return null;
    }

    /**
     * Normalize builders that address the same underlying WordPress object.
     *
     * Attachments are posts and nav menus are terms in WordPress, so their IDs
     * must not be claimable twice merely through different Muster builders.
     *
     * @param string $type
     * @return string
     */
    private function resourceFamily(string $type): string
    {
        return match ($type) {
            'attachment' => 'post',
            'menu' => 'term',
            default => $type,
        };
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function records(): array
    {
        if (!function_exists('get_option')) {
            return [];
        }

        $stored = get_option(self::OPTION, []);

        return is_array($stored) ? $stored : [];
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $records
     */
    private function persist(array $records): void
    {
        if (!function_exists('update_option')) {
            throw new RuntimeException('WordPress option functions are required for Muster ownership.');
        }

        update_option(self::OPTION, $records, false);
    }

    private function delete(OwnedResource $resource): void
    {
        if (!$this->exists($resource)) {
            return;
        }

        $result = match ($resource->type()) {
            'post', 'attachment' => function_exists('wp_delete_post')
                ? wp_delete_post($resource->id(), true)
                : throw new RuntimeException('wp_delete_post() is required to reset owned posts.'),
            'term' => function_exists('wp_delete_term')
                ? wp_delete_term($resource->id(), $resource->subtype())
                : throw new RuntimeException('wp_delete_term() is required to reset owned terms.'),
            'user' => function_exists('wp_delete_user')
                ? wp_delete_user($resource->id())
                : throw new RuntimeException('wp_delete_user() is required to reset owned users.'),
            'option' => function_exists('delete_option')
                ? delete_option($resource->locator())
                : throw new RuntimeException('delete_option() is required to reset owned options.'),
            'menu' => function_exists('wp_delete_nav_menu')
                ? wp_delete_nav_menu($resource->id())
                : throw new RuntimeException('wp_delete_nav_menu() is required to reset owned menus.'),
            default => throw new RuntimeException(sprintf('Cannot delete unknown owned resource type [%s].', $resource->type())),
        };

        if ($result === false || (function_exists('is_wp_error') && is_wp_error($result))) {
            throw new RuntimeException(sprintf('Failed to delete owned resource [%s:%s].', $resource->scope(), $resource->key()));
        }
    }

    private function exists(OwnedResource $resource): bool
    {
        return match ($resource->type()) {
            'post', 'attachment' => function_exists('get_post')
                ? (($post = get_post($resource->id())) !== null && $post !== false)
                : true,
            'term' => function_exists('get_term')
                ? (($term = get_term($resource->id(), $resource->subtype())) !== null
                    && $term !== false
                    && !(function_exists('is_wp_error') && is_wp_error($term)))
                : true,
            'user' => function_exists('get_user_by')
                ? get_user_by('id', $resource->id()) !== false
                : true,
            'option' => function_exists('get_option')
                ? $this->optionExists($resource->locator())
                : true,
            'menu' => function_exists('wp_get_nav_menu_object')
                ? wp_get_nav_menu_object($resource->id()) !== false
                : true,
            default => true,
        };
    }

    private function optionExists(string $name): bool
    {
        $missing = new \stdClass();

        return get_option($name, $missing) !== $missing;
    }
}

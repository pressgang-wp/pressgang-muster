<?php

namespace PressGang\Muster\Ownership;

use RuntimeException;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Results\Operation;
use PressGang\Muster\Results\OperationAction;

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

    /**
     * @var array<string, array<string, OwnedResource>>
     */
    private array $plannedClaims = [];

    /**
     * Planned-deletion overlay keyed by resource identity token.
     *
     * @var array<string, true>
     */
    private array $plannedDeletions = [];

    private WpResources $wp;

    public function __construct(private MusterContext $context)
    {
        $this->wp = new WpResources();
    }

    /**
     * Mark a resource as absent from the planning overlay after planned pruning.
     *
     * @param OwnedResource $resource
     * @return void
     */
    public function markPlannedDeletion(OwnedResource $resource): void
    {
        $this->plannedDeletions[self::deletionToken(
            $resource->type(),
            $resource->id(),
            $resource->subtype(),
            $resource->locator()
        )] = true;
    }

    /**
     * Check whether a prior planned operation removes this resource.
     *
     * @param string $type
     * @param int $id
     * @param string $subtype
     * @param string $locator
     * @return bool
     */
    public function isPlannedDeleted(string $type, int $id, string $subtype, string $locator): bool
    {
        return isset($this->plannedDeletions[self::deletionToken($type, $id, $subtype, $locator)]);
    }

    /**
     * Build the identity token used to key the planned-deletion overlay.
     *
     * Tokens use the WordPress storage family (see OwnedResource::family()) so
     * that, for example, deleting posts of type `attachment` also hides those
     * IDs from the attachment builder during the same planning pass.
     */
    private static function deletionToken(string $type, int $id, string $subtype, string $locator): string
    {
        $family = OwnedResource::family($type);

        return $id > 0
            ? sprintf('%s:id:%d', $family, $id)
            : sprintf('%s:%s:%s', $family, $subtype, $locator);
    }

    /**
     * Resolve and type-check the registry record for one builder intent.
     *
     * @param array{scope: string, key: string, adopt: bool} $intent
     * @param string $type
     * @param string $subtype
     * @return OwnedResource|null
     * @throws OwnershipConflict If the key already identifies another resource type.
     */
    public function resolve(array $intent, string $type, string $subtype): ?OwnedResource
    {
        $owned = $this->find($intent['scope'], $intent['key']);

        if ($owned !== null && ($owned->type() !== $type || $owned->subtype() !== $subtype)) {
            $error = new OwnershipConflict(sprintf(
                'Logical key [%s:%s] already identifies a different resource type.',
                $intent['scope'],
                $intent['key']
            ));
            $this->reportOperation($intent, OperationAction::Conflict, $type, $owned->id(), $owned->locator(), $error->getMessage());

            throw $error;
        }

        return $owned;
    }

    /**
     * Assert that an existing WordPress resource may be claimed by this intent.
     *
     * @param array{scope: string, key: string, adopt: bool} $intent
     * @param string $type
     * @param int $id
     * @param string $subtype
     * @param string $locator
     * @return void
     * @throws OwnershipConflict If the resource is owned elsewhere or needs adopt().
     */
    public function claim(array $intent, string $type, int $id, string $subtype, string $locator): void
    {
        try {
            $this->assertClaimable(
                new OwnedResource($intent['scope'], $intent['key'], $type, $id, $subtype, $locator),
                $intent['adopt']
            );
        } catch (OwnershipConflict $error) {
            $this->reportOperation($intent, OperationAction::Conflict, $type, $id, $locator, $error->getMessage());

            throw $error;
        }
    }

    /**
     * Record a resource-addressed conflict and abort the current pass.
     *
     * @param array{scope: string, key: string, adopt: bool} $intent
     * @param string $type
     * @param int $id
     * @param string $locator
     * @param string $message
     * @return never
     */
    public function recordConflict(array $intent, string $type, int $id, string $locator, string $message): never
    {
        $this->reportOperation($intent, OperationAction::Conflict, $type, $id, $locator, $message);

        throw new OwnershipConflict($message);
    }

    /**
     * Add one resource outcome to the current reconciliation report.
     *
     * @param array{scope: string, key: string, adopt: bool} $intent
     * @param OperationAction $action
     * @param string $type
     * @param int $id
     * @param string $locator
     * @param string|null $message
     * @return void
     */
    public function reportOperation(
        array $intent,
        OperationAction $action,
        string $type,
        int $id,
        string $locator,
        ?string $message = null,
    ): void {
        $this->context->report()->add(new Operation(
            $action,
            $type,
            $intent['scope'],
            $intent['key'],
            $locator,
            $id,
            $message,
            $this->context->scope()->activeGroup()
        ));
    }

    public function find(string $scope, string $key): ?OwnedResource
    {
        if (isset($this->plannedClaims[$scope][$key])) {
            return $this->plannedClaims[$scope][$key];
        }

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
            $this->plannedClaims[$resource->scope()][$resource->key()] = $resource;
            return;
        }

        $records = $this->records();
        $records[$resource->scope()][$resource->key()] = $resource->toArray();
        $this->persist($records);
    }

    /**
     * Check whether the read-only pass already declared this logical key.
     *
     * @param string $scope
     * @param string $key
     * @return bool
     */
    public function isPlannedClaim(string $scope, string $key): bool
    {
        return isset($this->plannedClaims[$scope][$key]);
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
                $this->context->logger()->info(sprintf('Planning delete owned resource [%s:%s].', $scope, $key));
                $this->markPlannedDeletion($owned[$key]);
                $this->reportPrune($owned[$key]);
            }

            return count($remove);
        }

        $records = $this->records();
        $changed = false;

        try {
            foreach ($remove as $key) {
                $this->wp->delete($owned[$key]);
                $this->reportPrune($owned[$key]);
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

    private function reportPrune(OwnedResource $resource): void
    {
        $this->context->report()->add(new Operation(
            OperationAction::Prune,
            $resource->type(),
            $resource->scope(),
            $resource->key(),
            $resource->locator(),
            $resource->id(),
            group: $this->context->scope()->activeGroup()
        ));
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

                if (OwnedResource::family($owned->type()) !== OwnedResource::family($requested->type())) {
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

}

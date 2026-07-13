<?php

namespace PressGang\Muster\Ownership;

use LogicException;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Results\Operation;
use PressGang\Muster\Results\OperationAction;

/**
 * Adds explicit logical identity and adoption intent to a resource builder.
 *
 * Builders created through Muster receive the concrete Muster class as their
 * ownership scope and must declare a logical key before saving. Low-level
 * builders without a scope remain available for focused adapter and unit use.
 */
trait HasOwnership
{
    private ?string $ownershipScope = null;

    private ?string $logicalKey = null;

    private bool $adoptExisting = false;

    private function initializeOwnership(?string $scope): void
    {
        $this->ownershipScope = $scope;
    }

    /**
     * Assign a stable logical key within the current Muster class.
     *
     * This mutates builder state only. On save, the key is used before mutable
     * natural locators and the resulting WordPress resource is recorded as
     * Muster-owned.
     *
     * @param string $key
     * @return self
     */
    public function key(string $key): self
    {
        $key = trim($key);

        if ($key === '') {
            throw new LogicException('Muster logical key cannot be empty.');
        }

        $this->logicalKey = $key;

        return $this;
    }

    /**
     * Explicitly allow this logical key to claim an existing unowned resource.
     *
     * Adoption never steals a resource already owned by another scope or key.
     * This mutates builder state only; the claim is recorded during save().
     *
     * @param bool $adopt
     * @return self
     */
    public function adopt(bool $adopt = true): self
    {
        $this->adoptExisting = $adopt;

        return $this;
    }

    /**
     * @return array{scope: string, key: string, adopt: bool}|null
     */
    private function ownershipIntent(): ?array
    {
        if ($this->logicalKey === null) {
            if ($this->ownershipScope !== null && $this->ownershipScope !== '') {
                throw new LogicException('Builders created through a Muster require an explicit logical key; call key() before save().');
            }

            return null;
        }

        if ($this->ownershipScope === null || $this->ownershipScope === '') {
            throw new LogicException('A logical key requires an ownership scope; create the builder through a Muster or pass a scope to its constructor.');
        }

        return [
            'scope' => $this->ownershipScope,
            'key' => $this->logicalKey,
            'adopt' => $this->adoptExisting,
        ];
    }

    /**
     * Resolve and type-check the registry record for one builder intent.
     *
     * @param MusterContext $context
     * @param array{scope: string, key: string, adopt: bool} $intent
     * @param string $type
     * @param string $subtype
     * @return OwnedResource|null
     */
    private function currentOwnership(
        MusterContext $context,
        array $intent,
        string $type,
        string $subtype,
    ): ?OwnedResource {
        $owned = $context->ownership()->find($intent['scope'], $intent['key']);

        if ($owned !== null && ($owned->type() !== $type || $owned->subtype() !== $subtype)) {
            $error = new OwnershipConflict(sprintf(
                'Logical key [%s:%s] already identifies a different resource type.',
                $intent['scope'],
                $intent['key']
            ));
            $context->report()->add(new Operation(
                OperationAction::Conflict,
                $type,
                $intent['scope'],
                $intent['key'],
                $owned->locator(),
                $owned->id(),
                $error->getMessage(),
                $context->activeGroup()
            ));

            throw $error;
        }

        return $owned;
    }

    /**
     * Assert that an existing WordPress resource may be claimed by this intent.
     *
     * @param MusterContext $context
     * @param array{scope: string, key: string, adopt: bool} $intent
     * @param string $type
     * @param int $id
     * @param string $subtype
     * @param string $locator
     * @return void
     */
    private function claimExistingOwnership(
        MusterContext $context,
        array $intent,
        string $type,
        int $id,
        string $subtype,
        string $locator,
    ): void {
        try {
            $context->ownership()->assertClaimable(
                new OwnedResource($intent['scope'], $intent['key'], $type, $id, $subtype, $locator),
                $intent['adopt']
            );
        } catch (OwnershipConflict $error) {
            $context->report()->add(new Operation(
                OperationAction::Conflict,
                $type,
                $intent['scope'],
                $intent['key'],
                $locator,
                $id,
                $error->getMessage(),
                $context->activeGroup()
            ));

            throw $error;
        }
    }

    /**
     * Persist ownership only after the resource and declared side effects save.
     *
     * @param MusterContext $context
     * @param array{scope: string, key: string, adopt: bool} $intent
     * @param string $type
     * @param int $id
     * @param string $subtype
     * @param string $locator
     * @return void
     */
    private function recordOwnership(
        MusterContext $context,
        array $intent,
        string $type,
        int $id,
        string $subtype,
        string $locator,
    ): void {
        $context->ownership()->record(
            new OwnedResource($intent['scope'], $intent['key'], $type, $id, $subtype, $locator)
        );
    }

    /**
     * Add one resource outcome to the current reconciliation report.
     *
     * @param MusterContext $context
     * @param array{scope: string, key: string, adopt: bool} $intent
     * @param OperationAction $action
     * @param string $type
     * @param int $id
     * @param string $locator
     * @return void
     */
    private function reportOwnership(
        MusterContext $context,
        array $intent,
        OperationAction $action,
        string $type,
        int $id,
        string $locator,
    ): void {
        $context->report()->add(new Operation(
            $action,
            $type,
            $intent['scope'],
            $intent['key'],
            $locator,
            $id,
            group: $context->activeGroup()
        ));
    }

    /**
     * Record a resource-addressed conflict and abort the current pass.
     *
     * @param MusterContext $context
     * @param array{scope: string, key: string, adopt: bool} $intent
     * @param string $type
     * @param int $id
     * @param string $locator
     * @param string $message
     * @return never
     */
    private function throwOwnershipConflict(
        MusterContext $context,
        array $intent,
        string $type,
        int $id,
        string $locator,
        string $message,
    ): never {
        $context->report()->add(new Operation(
            OperationAction::Conflict,
            $type,
            $intent['scope'],
            $intent['key'],
            $locator,
            $id,
            $message,
            $context->activeGroup()
        ));

        throw new OwnershipConflict($message);
    }
}

<?php

namespace PressGang\Muster\Ownership;

use LogicException;
use PressGang\Muster\MusterContext;
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
     * Resolve the builder's declared identity into one ownership intent.
     *
     * @return array{scope: string, key: string, adopt: bool}|null Null for
     *         unscoped low-level builders, which skip ownership entirely.
     * @throws LogicException If a Muster-scoped builder has no key, or a key
     *         was declared without an ownership scope.
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
     * Record ownership and report the outcome for one completed upsert.
     *
     * Record-then-report in one fixed order, so the dry-run, keep, and
     * real-write paths of every builder cannot drift apart. A null intent
     * (builder used without a Muster scope) is a no-op.
     *
     * @param MusterContext $context
     * @param array{scope: string, key: string, adopt: bool}|null $intent
     * @param OperationAction $action
     * @param string $type
     * @param int $id
     * @param string $subtype
     * @param string $locator
     * @return void
     */
    private function finalizeUpsert(
        MusterContext $context,
        ?array $intent,
        OperationAction $action,
        string $type,
        int $id,
        string $subtype,
        string $locator,
    ): void {
        if ($intent === null) {
            return;
        }

        $registry = $context->ownership();
        $registry->record(new OwnedResource($intent['scope'], $intent['key'], $type, $id, $subtype, $locator));
        $registry->reportOperation($intent, $action, $type, $id, $locator);
    }
}

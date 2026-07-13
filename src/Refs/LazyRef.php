<?php

namespace PressGang\Muster\Refs;

use LogicException;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Ownership\OwnedResource;

/**
 * Immutable logical-key reference resolved against Muster ownership at save-time.
 *
 * A LazyRef may be created before its target builder. The target must be saved
 * before a consuming builder is saved on the first run; later runs can resolve
 * the persisted ownership record directly.
 */
final class LazyRef
{
    public function __construct(
        private MusterContext $context,
        private string $scope,
        private string $key,
    ) {
    }

    /**
     * Resolve and validate the referenced owned resource.
     *
     * @param string|array<int, string> $types Accepted resource type or types.
     * @param string|null $subtype Optional exact WordPress subtype/taxonomy.
     * @return OwnedResource
     * @throws LogicException If the reference is unresolved, identifies a
     *         different type or subtype, or lacks a persisted ID outside a
     *         dry run.
     */
    public function resolve(string|array $types, ?string $subtype = null): OwnedResource
    {
        $resource = $this->context->ownership()->find($this->scope, $this->key);
        if ($resource === null) {
            throw new LogicException(sprintf(
                'Reference [%s:%s] is unresolved; save its declaration before saving the relationship.',
                $this->scope,
                $this->key
            ));
        }

        $types = (array) $types;
        if (!in_array($resource->type(), $types, true)) {
            throw new LogicException(sprintf(
                'Reference [%s:%s] identifies [%s], expected [%s].',
                $this->scope,
                $this->key,
                $resource->type(),
                implode('|', $types)
            ));
        }

        if ($subtype !== null && $resource->subtype() !== $subtype) {
            throw new LogicException(sprintf(
                'Reference [%s:%s] identifies subtype [%s], expected [%s].',
                $this->scope,
                $this->key,
                $resource->subtype(),
                $subtype
            ));
        }

        if ($resource->id() < 1 && !$this->context->dryRun()) {
            throw new LogicException(sprintf('Reference [%s:%s] has no persisted WordPress ID.', $this->scope, $this->key));
        }

        return $resource;
    }

    public function scope(): string
    {
        return $this->scope;
    }

    public function key(): string
    {
        return $this->key;
    }
}

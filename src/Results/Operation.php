<?php

namespace PressGang\Muster\Results;

/**
 * Immutable description of one planned or applied resource operation.
 */
final class Operation
{
    /**
     * @param OperationAction $action
     * @param string $resource
     * @param string $scope
     * @param string $key
     * @param string $locator
     * @param int $id
     * @param string|null $message
     * @param string|null $group Named declaration group that produced the operation.
     */
    public function __construct(
        private OperationAction $action,
        private string $resource,
        private string $scope,
        private string $key,
        private string $locator,
        private int $id = 0,
        private ?string $message = null,
        private ?string $group = null,
    ) {
    }

    public function action(): OperationAction
    {
        return $this->action;
    }

    /**
     * @return array{action: string, resource: string, group: string|null, scope: string, key: string, locator: string, id: int, message: string|null}
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action->value,
            'resource' => $this->resource,
            'group' => $this->group,
            'scope' => $this->scope,
            'key' => $this->key,
            'locator' => $this->locator,
            'id' => $this->id,
            'message' => $this->message,
        ];
    }
}

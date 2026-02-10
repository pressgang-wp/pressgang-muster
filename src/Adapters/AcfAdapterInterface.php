<?php

namespace PressGang\Muster\Adapters;

/**
 * Adapter contract for updating ACF fields against saved entities.
 */
interface AcfAdapterInterface
{
    /**
     * @param array<string, mixed> $fields
     * @param string $objectType
     * @param int $objectId
     * @return void
     */
    public function updateFields(array $fields, string $objectType, int $objectId): void;
}

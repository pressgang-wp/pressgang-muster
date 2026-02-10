<?php

namespace PressGang\Muster\Adapters;

/**
 * Adapter contract for updating ACF fields against saved entities.
 *
 * Implementations should translate Muster object targets (`post`, `term`, etc.)
 * into the object reference shape expected by ACF update APIs.
 */
interface AcfAdapterInterface
{
    /**
     * Persist ACF fields for a saved WordPress object.
     *
     * @param array<string, mixed> $fields
     * @param string $objectType
     * @param int $objectId
     * @return void
     */
    public function updateFields(array $fields, string $objectType, int $objectId): void;
}

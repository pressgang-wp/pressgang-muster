<?php

namespace PressGang\Muster\Adapters;

/**
 * No-op ACF adapter used when no integration is registered.
 */
final class NullAcfAdapter implements AcfAdapterInterface
{
    /**
     * @param array<string, mixed> $fields
     * @param string $objectType
     * @param int $objectId
     * @return void
     */
    public function updateFields(array $fields, string $objectType, int $objectId): void
    {
    }
}

<?php

namespace PressGang\Muster\Ownership;

/**
 * Immutable registry record for one resource managed by a Muster scope.
 *
 * The logical key is stable even when a mutable WordPress locator such as a
 * post or term slug changes. IDs are runtime references, never authored fixture
 * identity.
 */
final class OwnedResource
{
    public function __construct(
        private string $scope,
        private string $key,
        private string $type,
        private int $id,
        private string $subtype,
        private string $locator,
    ) {
    }

    public function scope(): string
    {
        return $this->scope;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function subtype(): string
    {
        return $this->subtype;
    }

    public function locator(): string
    {
        return $this->locator;
    }

    /**
     * @return array{scope: string, key: string, type: string, id: int, subtype: string, locator: string}
     */
    public function toArray(): array
    {
        return [
            'scope' => $this->scope,
            'key' => $this->key,
            'type' => $this->type,
            'id' => $this->id,
            'subtype' => $this->subtype,
            'locator' => $this->locator,
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @return self|null
     */
    public static function fromArray(array $record): ?self
    {
        foreach (['scope', 'key', 'type', 'id', 'subtype', 'locator'] as $field) {
            if (!array_key_exists($field, $record)) {
                return null;
            }
        }

        return new self(
            (string) $record['scope'],
            (string) $record['key'],
            (string) $record['type'],
            (int) $record['id'],
            (string) $record['subtype'],
            (string) $record['locator'],
        );
    }
}

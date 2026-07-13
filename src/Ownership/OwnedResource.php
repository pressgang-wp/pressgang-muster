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
     * Normalize builder types that address the same underlying WordPress object.
     *
     * Attachments are posts and nav menus are terms in WordPress, so their IDs
     * must not be claimable twice merely through different Muster builders.
     * This single mapping is shared by every component that compares resources
     * across builder types.
     *
     * @param string $type Builder resource type, e.g. `attachment`.
     * @return string The WordPress storage family, e.g. `post`.
     */
    public static function family(string $type): string
    {
        return match ($type) {
            'attachment' => 'post',
            'menu' => 'term',
            default => $type,
        };
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
     * Rebuild a record persisted by the ownership registry.
     *
     * Values are cast rather than validated because the registry itself is the
     * only writer of this data; callers treat a null return (missing field) as
     * a malformed registry and fail loudly.
     *
     * @param array<string, mixed> $record
     * @return self|null Null when a required field is absent.
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

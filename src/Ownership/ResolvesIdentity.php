<?php

namespace PressGang\Muster\Ownership;

use PressGang\Muster\MusterContext;

/**
 * Shared identity-resolution walk for the upsert builders.
 *
 * Every upsert builder answers "which existing WordPress resource, if any, am
 * I about to write?" with the same guarded sequence: natural-locator lookup,
 * planned-deletion overlay, owned-resource lookup, moved-locator conflict
 * check, then an ownership claim. The order of those guards is load-bearing —
 * this trait holds it once so six builders cannot drift apart.
 *
 * Builders differ only in how they look resources up, so those steps are
 * injected as callables over an opaque per-builder handle (an ID for posts
 * and menus, a WordPress object for terms, users, and comments).
 */
trait ResolvesIdentity
{
    /**
     * Resolve the existing resource this declaration addresses, if any.
     *
     * @param MusterContext $context
     * @param array{scope: string, key: string, adopt: bool}|null $intent
     * @param string $type Muster resource type, e.g. `post`.
     * @param string $subtype WordPress subtype (post type, taxonomy, ...).
     * @param string $locator Natural WordPress locator, e.g. a slug.
     * @param callable(): mixed $findNatural Look up the natural-locator match; null when absent.
     * @param callable(OwnedResource): mixed $resolveOwned Look up the owned resource's live handle; null when gone.
     * @param callable(mixed): int $idOf Extract the WordPress ID from a handle.
     * @param callable(int): string $conflictMessage Conflict text given the natural match's ID.
     * @return array{existing: mixed, ownedMatch: mixed, owned: OwnedResource|null}
     *         `existing` is the handle the upsert should write to (owned match
     *         wins over natural), `ownedMatch` the owned handle alone, and
     *         `owned` the registry record for the intent.
     * @throws OwnershipConflict If the owned resource and the natural match are
     *         different resources, or the claim is not permitted.
     */
    private function resolveIdentity(
        MusterContext $context,
        ?array $intent,
        string $type,
        string $subtype,
        string $locator,
        callable $findNatural,
        callable $resolveOwned,
        callable $idOf,
        callable $conflictMessage,
    ): array {
        $natural = $findNatural();
        if ($natural !== null && $context->ownership()->isPlannedDeleted($type, $idOf($natural), $subtype, $locator)) {
            $natural = null;
        }

        $existing = $natural;
        $ownedMatch = null;
        $owned = null;

        if ($intent !== null) {
            $registry = $context->ownership();
            $owned = $registry->resolve($intent, $type, $subtype);

            $ownedMatch = $owned === null ? null : $resolveOwned($owned);
            if ($ownedMatch !== null
                && $registry->isPlannedDeleted($type, $idOf($ownedMatch), $subtype, $owned->locator())) {
                $ownedMatch = null;
            }
            if ($ownedMatch !== null && $natural !== null && $idOf($ownedMatch) !== $idOf($natural)) {
                $registry->recordConflict($intent, $type, $idOf($natural), $locator, $conflictMessage($idOf($natural)));
            }

            $existing = $ownedMatch ?? $natural;
            if ($existing !== null) {
                $registry->claim($intent, $type, $idOf($existing), $subtype, $locator);
            }
        }

        return ['existing' => $existing, 'ownedMatch' => $ownedMatch, 'owned' => $owned];
    }
}

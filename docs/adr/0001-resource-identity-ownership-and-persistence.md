# ADR 0001: Resource identity, ownership, and persistence semantics

- Status: Accepted
- Date: 2026-07-13

## Context

Muster provisions WordPress resources through WordPress APIs. WordPress does
not provide a shared Model or ORM contract across posts, terms, users, options,
menus, and plugin-owned entities, so Muster must not pretend that persistence
semantics are implicit or uniform.

Natural keys such as `post_type + post_name` are useful for locating a resource,
but they do not prove that Muster owns it. Likewise, an "upsert" is ambiguous:
it can preserve omitted fields, replace a complete resource, or merely ensure
that a resource exists.

## Decision

Muster will distinguish three persistence modes:

- **ensure** creates a missing resource and leaves an existing resource unchanged.
- **merge** creates a missing resource or updates only fields explicitly supplied
  by the declaration. Merge is the default builder behaviour.
- **replace** treats the complete declaration as authoritative and may reset
  omitted fields. Replace must always be explicitly requested.

Until the public mode API is introduced, existing builders implement **merge**.
Defaults required for a new WordPress resource are delegated to the relevant
WordPress insertion API rather than being sent during updates.

Resource identity has two distinct parts:

1. A stable Muster logical key, independent of mutable values such as a slug.
2. A WordPress-native locator used to find the current object.

Builders created by a Muster must declare a logical `key()`. Muster persists the
concrete Muster class, logical key, resource kind, WordPress ID, subtype, and
current locator in a non-autoloaded WordPress option. Natural keys remain the
WordPress-native discovery mechanism, but do not imply ownership.

When a natural-key match exists without an ownership claim, saving fails unless
the declaration explicitly calls `adopt()`. Adoption may claim an unowned
resource but may never steal a resource registered to another Muster/key.
Owned reset and pruning operate only on registry entries for the concrete
Muster class.

## Consequences

- Calling a builder with only a slug and meta will not erase existing content.
- Clearing a field is explicit: callers pass the empty value intentionally.
- Builders must track whether a value was supplied instead of manufacturing
  update defaults.
- Ownership-aware adoption, pruning, reset, and conflict reporting allow Muster
  to converge collections without selecting unrelated WordPress content.
- Direct table mapping and ORM-style persistence remain out of scope; drivers
  continue to use WordPress and plugin APIs.

# ADR 0001: Ownership-scoped resource identity and merge persistence

- Status: Accepted
- Date: 2026-07-13

## Context

Muster provisions WordPress resources through WordPress APIs. WordPress does not
provide a shared Model or ORM contract across posts, terms, users, options,
menus, and plugin-owned entities, so Muster must not pretend that persistence
semantics are implicit or uniform.

Natural keys such as `post_type + post_name` are useful for locating a resource,
but they do not prove that Muster owns it.

"Upsert" is likewise ambiguous: it can preserve omitted fields, replace a
complete resource, or merely ensure that a resource exists. A tool that provisions
content into a database also containing editor-authored content cannot leave that
choice implicit.

## Decision

**Resource identity has two distinct parts:**

1. A stable Muster logical key, independent of mutable values such as a slug.
2. A WordPress-native locator used to find the current object.

Builders created by a Muster must declare a logical `key()`. Muster persists the
concrete Muster class, logical key, resource kind, WordPress ID, subtype, and
current locator in a non-autoloaded WordPress option. Natural keys remain the
WordPress-native discovery mechanism, but do not imply ownership.

**Builders persist by merge.** A declaration is a partial statement of intent, not
a complete resource. Saving creates a missing resource, or updates only the fields
explicitly supplied. Defaults required for a new resource are delegated to the
relevant WordPress insertion API rather than being sent during updates, because a
builder that manufactured update defaults would silently erase content Muster did
not author.

**Ownership is proven, not inferred.** When a natural-key match exists without an
ownership claim, saving fails unless the declaration explicitly calls `adopt()`.
Adoption may claim an unowned resource but may never steal a resource registered
to another Muster or key. Owned reset and pruning operate only on registry entries
for the concrete Muster class.

## Consequences

- Clearing a field is explicit: callers pass the empty value intentionally.
- Builders must track whether a value was supplied, instead of manufacturing
  update defaults.
- Builders are the persistence boundary and must report their outcome.
- Ownership-aware adoption, pruning, reset, and conflict reporting allow Muster to
  converge collections without selecting unrelated WordPress content.
- Direct table mapping and ORM-style persistence remain out of scope; drivers
  continue to use WordPress and plugin APIs.

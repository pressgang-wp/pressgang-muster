# ADR 0008: `Definition` renamed to `Recipe`

- Status: Accepted
- Date: 2026-07-16

## Context

[ADR 0007](0007-vocabulary-not-orm-factories.md) rejected "Factory" — it implies
a hydrated Model backed by an ORM, which Muster deliberately lacks — and recorded
`Recipe` as a candidate rename for `Definition`, to evaluate against worked
examples before adopting.

`Definition` is accurate and ORM-free but flat, and it does not connect to the
rest of Muster's register. `Victuals` is already the seeded value source; a
**Recipe** uses victuals to produce a resource declaration. The word is legible to
any developer (unlike the period-authentic but ambiguous "receipt"), stays clear
of "Factory"'s Model baggage, and completes the metaphor: **Muster** assembles the
content, from a **Recipe**, using the **Victuals**.

A more esoteric age-of-sail term was considered — the galley register is rich in
*dish* names (burgoo, lobscouse, duff) but those are outputs, not methods, and the
only genuine period word for a recipe, "receipt", now means a till slip. None was
both accurate and legible, so plain `Recipe` won.

## Decision

Rename `Definition` to `Recipe` throughout the public API:

- `PressGang\Muster\Patterns\Definition` → `PressGang\Muster\Patterns\Recipe`.
- `Muster::definition()` → `Muster::recipe()`.
- `Pattern::using(Definition)` → `Pattern::using(Recipe)`.

No deprecation shim: Muster is pre-1.0 and the symbol has no external consumers,
so the rename is a clean break rather than an aliased transition. This supersedes
the candidate note in ADR 0007.

## Consequences

- The mental model is now uniform: Muster (assembles) → Recipe (the reusable
  shape) → Victuals (the provisions) — no Model, no ORM, no "Factory".
- `state()`, `with()`, `make()`, and `Pattern::using()` are unchanged in behaviour;
  only the type and entry-point names move.
- Themes that referenced `definition()` must switch to `recipe()`. None do yet.

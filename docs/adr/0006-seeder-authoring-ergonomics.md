# ADR 0006: Seeder authoring ergonomics

- Status: Accepted
- Date: 2026-07-16

## Context

A Muster is read and edited far more than it is designed. In practice the
generated seeders carry a lot of per-line ceremony: every builder repeats
`->key('type:' . $i)`, `->status('publish')`, `->date($this->epoch()->format(...))`
and `->title($this->victuals()->headline())`, and a post with a featured image
needs a whole `after('thumbnail', fn …)` block. A five-line intent — "five hits,
each with a thumbnail" — reads as twenty.

Not all of that verbosity is waste. Some of it is **load-bearing**: it buys the
guarantees the toolkit exists to provide.

- **Logical keys** (ADR 0001) are identity independent of mutable slugs — rename
  a slug and the resource still resolves. That only holds if the key does *not*
  derive from the slug. So keys cannot simply be dropped or inferred from the
  natural locator.
- **Explicit dates** feed the deterministic clock (ADR 0004); the *value* is
  essential even when the ceremony is not.

The rest is **accidental**: sensible defaults written out longhand, and common
compositions expressed from primitives every time.

Modern factory/fixture tools resolve the same tension by declaring a resource's
shape once and keeping seeding terse: Zenstruck Foundry (`PostFactory::new()
->published()->create()`), Laravel factories (`->count()`, `sequence()`,
`recycle()`), Nelmio Alice (declarative YAML with ranges and references), and
drizzle-seed / Snaplet (schema-aware auto-generation refined per field). Muster
already ships the primitives to move this way — `Pattern`, `Definition`,
`sequence()`, states, and after-hooks — but the generated seeders hand-roll
everything instead of using them.

## Decision

Reduce accidental verbosity in stages, preserving every load-bearing guarantee.
Keys stay stable and slug-independent; determinism, ownership, and plan/apply
inspectability are untouched.

**Stage 1 — auto-keys and compositions (this ADR).**

- **Pattern rows self-key.** A builder produced inside `pattern('hit')->count(n)`
  that sets no `key()` of its own is assigned the stable, slug-independent key
  `hit:{index}` (and after-hook declarations `hit:{hook}:{index}`). An explicit
  `key()` still wins. This keeps ADR 0001's identity contract — the index is
  stable across slug renames — while removing the most-repeated line.
- **`Pattern::withThumbnail()`** registers the standard placeholder-featured-image
  after-hook, so the common "post with a thumbnail" needs one call, not a block.

**Stage 2 — builder defaults (planned).** Default `status` to `publish` and, when
unset, a post's `date` to the fixture epoch; a terser accessor for generated
values so `title`/`content` need not name `victuals()` for the typical case.

**Stage 3 — definitions with defaults (planned).** Upgrade the existing
`Definition` primitive to hold per-type faker defaults, auto-ACF, and thumbnails,
so a seeder declares "5 hits" and the shape lives in one place (the Foundry /
Laravel-factory model, on primitives Muster already has).

**Stage 4 — a declarative manifest (planned).** A `muster/` PHP config array —
"5 of each type, a page per template, a menu per location" — for the common
whole-surface case, matching what `wp capstan make muster` already introspects
and PressGang's "config returns arrays" convention. Class-based seeders remain
the escape hatch for bespoke logic, not the default.

## Consequences

- Pattern-based seeders shed their `->key('…' . $i)` lines and thumbnail blocks;
  a keyed row is still possible by calling `key()` explicitly.
- Auto-keys are derived from the pattern name and one-based index, so they are
  deterministic and stable regardless of slug — resets and re-runs behave exactly
  as with hand-written keys.
- Later stages are additive: builder defaults, definitions, and the manifest layer
  on top without changing the meaning of an explicit call. Anything a stage cannot
  express falls back to the fluent builder it is sugar over.
- What stays load-bearing and is never removed: explicit determinism inputs
  (seed/epoch), logical-key identity (inferred only where the inference is stable),
  and plan/apply inspectability.

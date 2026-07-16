# ADR 0007: Muster's vocabulary is not the ORM factory vocabulary

- Status: Accepted
- Date: 2026-07-16

## Context

Muster's seeding surface has three layers. A **Muster** orchestrates what content
exists; a **Definition** is a reusable recipe for one resource shape; **Victuals**
is the seeded value source. These map neatly onto a mental model many developers
already carry from Laravel — **Seeder → Factory → Faker** — and it is tempting to
adopt those names directly: rename `Definition` to "Factory", expose a
`factory()` entry point. Recognition is real DX value.

But "Factory" is not a neutral word. In Laravel — and in Foundry, factory_boy,
ex_machina — a *factory* produces a hydrated **model** instance backed by an ORM.
Muster has neither, deliberately: builders write through `wp_insert_post()` and
friends, and a `Definition` yields a **Declaration** (write *intent*), not an
object mapped onto `wp_posts` / `wp_postmeta`. Importing "Factory" would
reintroduce — at the level of vocabulary — exactly the Model/ORM mental model
Muster refuses. Names teach: the next contributor who reads "Factory" will infer
a Model that isn't there, and reach for ORM habits that do not apply.

WordPress has no shared object model, so a fixture tool that pretends otherwise
sets a false expectation. Muster's value is being WordPress-native; its
vocabulary should say so.

## Decision

Muster keeps its own register and does **not** adopt the Seeder / Factory / Faker
names as API or as documented synonyms:

- **Muster** — assembles content (orchestration; *plays the role of* a Seeder).
- **Definition** — the reusable recipe for one resource shape (*plays the role of*
  a Factory, minus the Model).
- **Victuals** — the seeded value source (*plays the role of* Faker).

The Laravel triad may appear in docs strictly as an **analogy for newcomers**,
always with the disclaimer that a Definition produces a *declaration, not a
model*, and that Muster is *not an ORM*. "Factory" is not adopted as a class name,
method name, or first-class documented synonym.

Any future per-type recipe abstraction (with states and relationships) is named in
Muster's own register — never "Factory".

## Consequences

- The public surface stays **Muster / Definition / Victuals**. Docs may say: "if
  you know Laravel factories, `Definition` plays that role — but it is a resource
  definition, not a model factory."
- The prohibition is on the *vocabulary*, not the *concept*: the reusable-recipe
  idea is welcome and already exists as `Definition`; only the ORM-laden name is
  rejected.

## Candidate (not yet accepted): rename `Definition` → `Recipe`

`Definition` is safe and ORM-free but flat. The provisioning metaphor already in
the codebase suggests a more evocative, equally ORM-free name: a **Recipe** uses
**Victuals** (provisions) to produce a dish (a resource declaration). It reads
naturally (`$this->recipe('hit')`) and carries zero Model implication.

This is a **breaking public-API rename** (`Definition` → `Recipe`,
`definition()` → `recipe()`), so it is recorded here as a candidate to evaluate
against worked examples before adopting — not accepted. Muster is pre-1.0, so a
hard rename with a version bump is on the table (optionally keeping `definition()`
as a deprecated alias for one minor version). If adopted, a follow-up ADR
supersedes this candidate note with the accepted decision.

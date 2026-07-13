# Muster Agent Guide

## What Muster Is
Muster is a WordPress-native toolkit for deterministic content provisioning and development fixtures.

It provides an explicit orchestration layer for creating and updating WordPress data (posts, terms, users, options, meta) in a **repeatable, idempotent, WordPress-native** way. Muster is tooling, not a runtime abstraction, and is intended for development, testing, and controlled environment setup.

Muster favors clarity over cleverness, and predictability over automation.

---

## Design Rules (Non-Negotiables)

- Muster is **orchestration**, not persistence logic.
- Builders perform **idempotent upserts**, never blind inserts.
- No global state mutation.
- Do not add explicit `declare(strict_types=1);` headers; keep typed signatures/properties/returns instead.
- No hidden randomness. All fake data must be seedable and deterministic.
- Muster `run()` methods are declarative and side-effect free outside builders;
  CLI application executes them once for planning and once for application.
- WordPress remains the source of truth:
  - `wp_insert_post()`, `wp_update_post()`, `get_posts()`, etc.
- Avoid magic:
  - Magic methods are allowed **only** when guarded and unambiguous (for example, post types via `post_type_exists()`).
- Behavior must be inspectable and predictable by reading the code.
- Favour DRY and SOLID:
  - Keep classes focused (SRP), avoid hidden coupling, and extract repeated logic before it spreads.
  - Prefer explicit contracts and value objects over implicit state sharing.
- Doc blocks must be useful:
  - Describe identity/upsert rules and side effects.
  - Reference relevant WordPress internals with `See:` links where behaviour relies on core APIs.

If something is surprising, it is probably wrong.

---

## Mental Model

- **Muster**
  Orchestrates a named WordPress content provisioning or fixture run.
- **Groups**
  Named callback boundaries that make every declaration inside them selectable
  as one unit through `--only`.
- **Victuals**
  Curated wrapper around Faker that provides WordPress-shaped, locale-aware, deterministic fake data.
- **FixtureClock**
  Immutable reference epoch for relative dates, independent of Faker's seed.
- **Patterns**
  Repeatable specifications for generating multiple similar WordPress resources.
- **Definitions, States, and Sequences**
  Reusable explicit builder factories, named builder transformations, and immutable iteration-indexed values.
- **Builders**
  Fluent builders for posts, terms, users, options, comments, attachments, and menus. Builders do the minimum required work to upsert data.
- **Refs**
  Immutable values returned from `save()` plus logical-key `LazyRef` handles resolved by consuming builders at save-time.
- **RunReport**
  Ordered create/update/keep/prune/conflict outcomes for a plan or apply pass.

---

## Canonical Usage Patterns

### Simple post creation

```php
$this->group('pages', function (): void {
    $this->page()
        ->key('page:about')
        ->title('About')
        ->slug('about')
        ->content($this->victuals()->paragraphs(2))
        ->save();
});
```

### Deterministic batch creation via Pattern

```php
$this->group('events', function (): void {
    $this->pattern('event')
        ->count(12)
        ->seed(1978)
        ->build(function (int $i) {
            return $this->event()
                ->key("event:{$i}")
                ->title($this->victuals()->headline())
                ->slug("event-{$i}")
                ->meta([
                    'starts_at' => $this->victuals()
                        ->dateBetween('+1 week', '+6 months')
                        ->format('Y-m-d H:i:s'),
                ]);
        });
});
```

Rules:
- `--only` selects group names, not Pattern names.
- Skipped group callbacks must not execute.
- Groups must be named explicitly, unique within a pass, and non-nested.
- Partial runs must reject ungrouped declarations and whole-scope reset/prune.
- Patterns must be explicit.
- `count()` is required.
- `seed()` controls randomness only, not data selection.
- The closure must return `PersistableDeclaration`.
- Definition states must return a persistable declaration and never write.
- After-hooks may return persistable declarations only; the hook itself must not write.
- Sequences derive values from the one-based iteration index and hold no cursor.

---

## Determinism and Seeding

- Faker randomness is wrapped by Victuals.
- Seeds control random output, not execution order or persistence rules.
- The fixture epoch controls relative dates, not randomness.
- Muster `defaultEpoch()` is overridden by an explicit context clock or CLI
  `--epoch`; without either, one system instant is shared across plan/apply.
- Seed precedence:
  1. Pattern seed
  2. Per-pattern context override (programmatic)
  3. Context seed, including CLI `--seed`
  4. No seed, which means non-deterministic output

Each Pattern run receives its own Victuals instance.
Every Victuals instance in one run receives the same FixtureClock.
Victuals image URLs must remain self-contained; generation must not introduce
implicit HTTP requests or dependence on a third-party placeholder service.

---

## Builders (Critical Rules)

- Builders are write-only abstractions.
- Builders must:
  - Collect intent.
  - Upsert data on `save()`.
- Builders must not:
  - Query unrelated data.
  - Infer defaults from globals.
  - Mutate unrelated state.

Lazy refs use `Muster class + logical key` and resolve through the ownership
registry. On the first run, a target declaration must be saved before its
consumer is saved. Cross-Muster refs must name the target Muster scope.

### Idempotency

Every builder must have a clear identity rule:
- Public Muster builders: concrete Muster class + explicit logical `key()`
- Posts: WordPress locator `post_type + post_name` (slug)
- Terms: WordPress locator `taxonomy + slug`
- Users: WordPress locator `user_login`
- Options: WordPress locator `option_name`
- Comments: WordPress locator `post + parent + type + author identity + deterministic GMT date`

Logical keys are stable fixture identity; WordPress locators may be mutable.
Existing unowned locator matches require explicit `adopt()` and resources owned
by another Muster/key must never be stolen.

Running the same Muster twice must not create duplicates.
New users require an explicit `password()` for creation. It must not be
reapplied to existing users because WordPress password hashes are not
comparable with the declared plaintext.

### Planning

- Planning performs WordPress reads and no writes.
- Builders must report one structured operation for every scoped declaration.
- Planning conflicts prevent application.
- Application re-resolves WordPress state; never assume a prior read remains valid.
- Broad truncate and owned reset/prune operations must appear in the report too.

---

## Magic Method Guidance

Magic is allowed only when:
- Resolution is deterministic.
- Failure is loud.
- Behavior is obvious.

Example:

```php
$this->event(); // resolves only if post_type_exists('event')
```

If resolution fails, throw immediately.

No silent fallbacks.

---

## Extensibility Guidance

- New behavior should be added via:
  - New Builders
  - New Pattern capabilities
  - Adapters (for example, ACF)
- Do not overload Muster with domain logic.
- Do not introduce ORM-like abstractions.
- Keep vertical slices small and composable.

---

## 📝 Doc Blocks & Developer Guidance (Non-Negotiable)

Muster relies heavily on **clear, intentional doc blocks** to communicate behaviour,
constraints, and intent - especially for developers unfamiliar with PressGang.

Doc blocks are not decoration. They are part of the public API.

---

### General Principles

- Doc blocks should explain **what a developer needs to know to use this correctly**.
- Prefer **why and how** over restating the method name.
- Avoid noise:
  - No `@package` tags
  - No "Class ClassName" headers
- Assume the reader understands PHP, but **not** Muster's architecture yet.

If a method or class exists, its purpose should be clear from the doc block alone.

---

### Class Doc Blocks

Class doc blocks should answer:

1. What role does this class play in the system?
2. When would a developer reach for it?
3. What *does it not* do?

Example:

```php
/**
 * Orchestrates a single content provisioning run.
 *
 * A Muster coordinates Patterns and Builders to create or update WordPress
 * data in a deterministic, idempotent way. It contains no persistence logic
 * itself and should remain free of domain-specific behaviour.
 */
abstract class Muster
```

---

### Method Doc Blocks

Method doc blocks must include:
- A short behavioural description (1-2 sentences)
- Explicit `@param` and `@return` annotations
- Notes on determinism, idempotency, or side effects when relevant

Example:

```php
/**
 * Defines a repeatable specification for generating multiple similar items.
 *
 * Patterns are the factory analogue in Muster. They control repetition,
 * seeding, and execution order, but do not persist data themselves.
 *
 * @param string $name Human-readable pattern identifier (for debugging/logging).
 * @return \PressGang\Muster\Patterns\Pattern
 */
public function pattern(string $name): Pattern
```

---

### Builder Methods (Special Rules)

Builder doc blocks must be explicit about persistence.

Every builder method should make it clear whether it:
- only mutates internal state
- or performs WordPress writes

Example:

```php
/**
 * Persist the builder state to WordPress.
 *
 * This method performs an idempotent upsert based on the builder's identity
 * rules (e.g. post type + slug). Calling save() multiple times must not
 * create duplicate records.
 *
 * @return \PressGang\Muster\Refs\PostRef
 */
public function save(): PostRef
```

If a method does not persist data, say so.

---

### Pattern Closures & Callables

Where a callable is accepted, the doc block must document:
- Expected parameters
- Expected return type
- Behaviour if the contract is violated

Example:

```php
/**
 * Execute the pattern using the provided builder factory.
 *
 * The callable must return a PersistableDeclaration. The declaration will be
 * persisted automatically by the Pattern runner.
 *
 * @param callable(int $i): \PressGang\Muster\Contracts\PersistableDeclaration $factory
 * @return \PressGang\Muster\Patterns\PatternResult
 */
public function build(callable $factory): PatternResult
```

Fail fast if the contract is violated.

---

### Avoid Trivial Doc Blocks

Methods whose behaviour is fully obvious from their name and signature
may omit a doc block.

Examples:
- simple getters
- fluent setters that only assign a value and return self

Use judgement - but err on the side of clarity for public APIs.

---

### Documentation as API Stability

Doc blocks are considered part of Muster's API surface.

When changing behaviour:
- Update the doc block
- Treat undocumented behaviour as unstable
- Prefer documentation updates before refactors

If behaviour cannot be documented clearly, reconsider the design.

---

### Final Rule (Again)

If a class or method cannot be explained clearly to a developer
who has never used Muster before, it is not finished.

---

## Testing and Validation

- Prefer end-to-end vertical slice tests over exhaustive unit tests.
- Use the isolated WordPress integration harness for behavior that stubs cannot
  prove; never point it at a real site database.
- Validate:
  - Determinism (same seed means same output)
  - Idempotency (safe re-runs)
  - Minimal WordPress calls
- Avoid mocking WordPress unless unavoidable.

---

## Non-Goals

- No ORM or ActiveRecord layer.
- No runtime dependency for front-end code.
- No automatic inference of intent.
- No Laravel feature parity for its own sake.

Muster draws useful lessons from Laravel and other seeding systems, but remains
WordPress-native and is not a port of their Model or ORM abstractions.

---

## Where to Look

- `src/Muster.php`
- `src/MusterContext.php`
- `src/Patterns/*`
- `src/Builders/*`
- `src/Victuals/*`
- `src/Refs/*`
- `src/Adapters/*`
- `src/Testing/*`
- `README.md`

---

## Final Rule

If a feature cannot be explained clearly in one paragraph of documentation, it probably does not belong in Muster.

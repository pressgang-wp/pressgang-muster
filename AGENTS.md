# Muster Agent Guide

## What Muster Is
Muster is a deterministic, Laravel-style seeding and content provisioning tool for WordPress.

It provides an explicit orchestration layer for creating and updating WordPress data (posts, terms, users, options, meta) in a **repeatable, idempotent, WordPress-native** way. Muster is tooling, not a runtime abstraction, and is intended for development, testing, and controlled environment setup.

Muster favors clarity over cleverness, and predictability over automation.

---

## Design Rules (Non-Negotiables)

- Muster is **orchestration**, not persistence logic.
- Builders perform **idempotent upserts**, never blind inserts.
- No global state mutation.
- Do not add explicit `declare(strict_types=1);` headers; keep typed signatures/properties/returns instead.
- No hidden randomness. All fake data must be seedable and deterministic.
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
  Orchestrates a seeding run (Laravel Seeder analogue).
- **Victuals**
  Curated wrapper around Faker that provides WordPress-shaped, locale-aware, deterministic fake data.
- **Patterns**
  Repeatable specifications for generating multiple similar items (Laravel Factory analogue).
- **Builders**
  Fluent builders for posts, terms, users, and options. Builders do the minimum required work to upsert data.
- **Refs**
  Immutable value objects returned from `save()` calls, used for linking entities.

---

## Canonical Usage Patterns

### Simple post creation

```php
$this->page()
    ->title('About')
    ->slug('about')
    ->content($this->victuals()->paragraphs(2))
    ->save();
```

### Deterministic batch creation via Pattern

```php
$this->pattern('event')
    ->count(12)
    ->seed(1978)
    ->build(function (int $i) {
        return $this->event()
            ->title($this->victuals()->headline())
            ->slug("event-{$i}")
            ->meta([
                'starts_at' => $this->victuals()
                    ->dateBetween('+1 week', '+6 months')
                    ->format('Y-m-d H:i:s'),
            ]);
    });
```

Rules:
- Patterns must be explicit.
- `count()` is required.
- `seed()` controls randomness only, not data selection.
- The closure must return a Builder.

---

## Determinism and Seeding

- Faker randomness is wrapped by Victuals.
- Seeds control random output, not execution order or persistence rules.
- Seed precedence:
  1. Pattern seed
  2. Muster-level seed (if defined)
  3. CLI seed (when implemented)
  4. No seed, which means non-deterministic output

Each Pattern run receives its own Victuals instance.

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

### Idempotency

Every builder must have a clear identity rule:
- Posts: `post_type + post_name` (slug)
- Terms: `taxonomy + slug`
- Users: `user_login`
- Options: `option_name`

Running the same Muster twice must not create duplicates.

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
 * The callable must return a Builder instance. The builder will be
 * persisted automatically by the Pattern runner.
 *
 * @param callable(int $i): \PressGang\Muster\Builders\PostBuilder $factory
 * @return void
 */
public function build(callable $factory): void
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

Muster is inspired by Laravel's seeding model, not a port of it.

---

## Where to Look

- `src/Muster.php`
- `src/MusterContext.php`
- `src/Patterns/*`
- `src/Builders/*`
- `src/Victuals/*`
- `src/Refs/*`
- `src/Adapters/*`
- `README.md`

---

## Final Rule

If a feature cannot be explained clearly in one paragraph of documentation, it probably does not belong in Muster.

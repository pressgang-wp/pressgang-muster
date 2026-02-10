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

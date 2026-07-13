<img src="https://github.com/pressgang-wp/pressgang-muster/blob/main/assets/img/muster-banner.png" alt="Muster">

# 🍪Muster

[![Latest Stable Version](https://poser.pugx.org/pressgang-wp/muster/v/stable)](https://packagist.org/packages/pressgang-wp/muster)
[![Total Downloads](https://poser.pugx.org/pressgang-wp/muster/downloads)](https://packagist.org/packages/pressgang-wp/muster)
[![License](https://poser.pugx.org/pressgang-wp/muster/license)](https://packagist.org/packages/pressgang-wp/muster)

**Muster** is a WordPress-native API for deterministic content provisioning and development fixtures.

It keeps WordPress semantics explicit, persists through core APIs, and gives a
theme one conventional place to orchestrate repeatable data creation.

Muster is part of the [PressGang ecosystem](https://pressgang.dev/) but remains
WordPress-native: builders write through core WordPress APIs rather than an ORM
or direct table mapping.

## Installation

Muster is available on [Packagist](https://packagist.org/packages/pressgang-wp/muster):

```bash
composer require --dev pressgang-wp/muster
```

Installing as a development dependency is recommended for local setup, CI, and
disposable test environments. If a controlled non-production runtime must run
Muster after a `--no-dev` deployment, install it as a regular dependency instead.

Requirements:

- PHP 8.3+
- WordPress loaded when resources are persisted
- WP-CLI for `wp capstan seed` and `wp capstan muster`
- FakerPHP 1.24+ (installed automatically)

## What Muster Provides

- WordPress-native builders for posts, pages, terms, users, options, comments, menus, and attachments.
- Stable logical keys and Muster-scoped ownership independent of mutable slugs.
- Collision-safe adoption plus owned-only reset and pruning.
- Read-only planning followed by revalidated application with operation summaries.
- Machine-readable reconciliation output through `--format=json`.
- Named declaration groups for safe, complete `--only` runs.
- Merge-safe post, term, and user updates that preserve omitted fields.
- Seeded fake content through the curated `Victuals` Faker wrapper.
- Repeatable post Patterns with declared counts and per-pattern seed overrides.
- Immutable refs for parents, menu items, attachments, and featured images.
- ACF values derived from the active theme's `acf-json` definitions.
- Conventional `wp capstan seed` and low-level named Muster commands.
- Deterministic placeholder media for stable visual fixtures.
- A separate real-WordPress integration suite for core API and database behavior.

Full ecosystem documentation is available in the
[Muster GitBook guide](https://docs.pressgang.dev/ecosystem/muster).

## Mental Model

- `Muster`: orchestration entrypoint where your seed flow lives.
- `Victuals`: curated Faker wrapper with UK-leaning defaults.
- `FixtureClock`: immutable epoch used to resolve relative fixture dates.
- `Pattern`: repeatable batch runner with `count()` and optional per-pattern seed.
- `Group`: explicit callback boundary selected by `--only`; skipped callbacks are
  not evaluated.
- `Builders`: explicit WordPress resource writers. Posts, terms, users, and
  comments use merge-upsert behavior; menus rebuild their items, attachments
  are reused by slug, and `truncate()` is an explicitly destructive reset.
- `RunReport`: ordered `create`, `update`, `keep`, `prune`, and `conflict`
  operations for one planning or application pass.

The orchestration model is informed by seeders and factories in frameworks such
as Laravel, but Muster is not a Laravel port and does not introduce Models or an
ORM over WordPress data.

## Persistence Semantics

Every builder created through a Muster requires an explicit `key()`. The
concrete Muster class and logical key form stable fixture identity; WordPress
locators such as `post_type + slug`, `taxonomy + slug`, `user_login`, and
`option_name` remain the native lookup layer. Post and term slugs can therefore
change without creating duplicates.

Post, term, user, and comment builders use **merge-upsert** behaviour: only fields
explicitly set on the builder are updated. Omitted fields retain their existing
WordPress values; passing an empty value explicitly clears a field.

Comments have no slug, so their collision locator is the target post, parent,
comment type, author email or name, and deterministic GMT date. Content is not
part of identity and can change safely. Pin `date()` explicitly or define a
scenario epoch when the declaration must remain stable across invocations.

New users must declare `->password('initial-password')`. The value is sent only
to `wp_insert_user()`; reruns never reset an existing user's credentials because
WordPress stores a one-way hash that cannot be compared with the declaration.

Muster distinguishes this default merge behaviour from two future explicit
modes: `ensure` (create only) and `replace` (complete declared state). The
identity and ownership contract is recorded in
[`docs/adr/0001-resource-identity-ownership-and-persistence.md`](docs/adr/0001-resource-identity-ownership-and-persistence.md).

An existing natural-key match is a conflict unless it is already owned by the
same Muster key. Use `->adopt()` once to claim deliberately pre-existing data;
adoption never steals another key's resource. Ownership records live in the
non-autoloaded `pressgang_muster_registry` option.

## Plan and Apply

Every CLI invocation performs a read-only planning pass first. A normal command
prints that plan, re-runs the same declarative Muster against current WordPress
state, and applies the revalidated operations. `--dry-run` stops after planning.
Any planning conflict prevents the application pass.

Operations are reported as `create`, `update`, `keep`, `prune`, or `conflict`.
Core post, term, user, and option fields can produce `keep`; declarations with
ACF/meta/taxonomy side effects and authoritative menus are conservatively
reported as updates when the resource already exists.

```text
Plan:
  CREATE   post       [pages] page:about -> about-us
  Summary: create=1 update=0 keep=0 prune=0 conflict=0
Apply:
  CREATE   post       [pages] page:about -> about-us
  Summary: create=1 update=0 keep=0 prune=0 conflict=0
```

The apply pass calls `run()` a second time. Keep Muster classes declarative:
do not send mail, make remote API calls, or perform unrelated writes inside
`run()`. Builders remain the persistence boundary. Programmatic callers can
inspect `$context->report()->operations()`, `summary()`, or `toArray()`.

## Quick Example

```php
<?php

use PressGang\Muster\Muster;

final class DemoMuster extends Muster
{
    public static function defaultEpoch(): string
    {
        return '2026-01-01 09:00:00+00:00';
    }

    public function run(): void
    {
        $this->group('pages', function (): void {
            $this->page()
                ->key('page:about')
                ->title('About us')
                ->slug('about-us')
                ->status('publish')
                ->content($this->victuals()->paragraphs(3))
                ->save();
        });

        $this->group('events', function (): void {
            $this->pattern('event-fixtures')
                ->seed(1201)
                ->count(5)
                ->build(function (int $i) {
                    return $this->post('event')
                        ->key('event:' . $i)
                        ->title($this->victuals()->headline())
                        ->slug('event-' . $i)
                        ->status('publish');
                });
        });
    }
}
```

## Ownership and Cleanup

```php
// Explicitly claim an existing unowned page on the first managed run.
$this->page()
    ->key('page:about')
    ->adopt()
    ->title('About us')
    ->slug('about-us')
    ->save();

// Delete only resources owned by this concrete Muster class.
$this->resetOwned();

// At the end of a complete run, delete owned resources not touched this run.
$this->pruneOwned();

// Optionally preserve a conditional key that was not declared this run.
$this->pruneOwned(['page:seasonal']);
```

`pruneOwned()` is deliberately explicit and rejects partial `--only` runs,
because declarations in skipped groups cannot be treated as stale. Keys saved
in a complete run—including reserved `acf:*` support keys—are retained
automatically. The optional array means “also keep,” not “complete manifest.”
`truncate()` still exists for an intentionally broad development reset, but it
is not used by `wp capstan seed --fresh`.

## ACF-Derived Fixtures

Muster can generate field values from the active theme's `acf-json` exports:

```php
$this->post('event')
    ->key('event:example')
    ->title('Example event')
    ->slug('example-event')
    ->acf($this->acfFor('event'))
    ->save();
```

Use `$this->acfFor('event', 'minimal')` for required fields only. The default
`populated` variant fills every generatable field. Media and relational fields
need real WordPress IDs, so `acfFor()` may provision deterministic supporting
attachments, posts, or terms. Those support objects receive reserved `acf:*`
keys and are owned by the calling Muster.

## Determinism

Randomness and time are separate inputs. Use a seed for repeatable generated
values and an epoch for repeatable relative dates:

- Conventional theme seed: `wp capstan seed --seed=1234`
- Low-level named runner: `wp capstan muster <muster-class> --seed=1234`
- Pattern-level override: `->seed(9876)`
- One-off clock override: `wp capstan seed --epoch="2026-01-01 09:00:00+00:00"`
- Scenario default: override `public static function defaultEpoch()` on the Muster.

`$this->epoch()` returns the reference instant and `$this->at('+1 week')`
resolves from it. Victuals `date()`, `datetime()`, and `dateBetween()` use the
same clock, so `dateBetween('+1 week', '+6 months')` no longer consults the
machine clock. An explicit CLI epoch overrides the scenario default. If neither
is supplied, Muster captures the system clock once and shares it across plan and
apply; that keeps one invocation coherent but intentionally does not make
separate invocations repeatable.

The same seed, epoch, call order, locale, and inputs produce the same generated
sequence. Calls through `victuals()->raw()` are outside this clock contract.

## WP-CLI Usage

```bash
wp capstan seed --seed=1234
wp capstan seed --epoch="2026-01-01 09:00:00+00:00"
wp capstan seed --dry-run
wp capstan seed --fresh --seed=1234
wp capstan seed --dry-run --format=json

wp capstan muster App\\Muster\\DemoMuster --seed=1234
wp capstan muster App\\Muster\\DemoMuster --epoch="2026-01-01 09:00:00+00:00"
wp capstan muster App\\Muster\\DemoMuster --dry-run
wp capstan muster App\\Muster\\DemoMuster --only=events
wp capstan muster App\\Muster\\DemoMuster --format=json
```

Flags:
- `--seed=<int>` sets global seed.
- `--epoch=<datetime>` pins the fixture clock with an absolute ISO-style date
  or datetime; it overrides `defaultEpoch()`.
- `--dry-run` performs the complete read-only plan and skips application.
- `--format=json` emits one structured payload containing plan/apply operations
  and summaries, with no human log lines.
- `--only=<csv>` executes only matching declaration group names.
- `--fresh` is available on `wp capstan seed` and deletes only resources owned
  by that concrete Muster class before `run()`; no custom `fresh()` method is required.

Use `$this->group('name', function (): void { ... });` around every independently
selectable section. A skipped callback is never invoked, so direct builders,
Patterns, Victuals calls, and ACF fixture generation inside it perform no reads,
writes, or random draws. Group names must be non-empty, unique within a pass,
and cannot be nested. Unknown `--only` names fail instead of silently doing
nothing. During a partial run, declarations outside a group, `resetOwned()`,
and `pruneOwned()` also fail loudly.

Without `--only`, ungrouped declarations remain valid. Combining `--fresh` and
`--only` intentionally clears every resource owned by the Muster and then
rebuilds only the selected groups.

## Demo Scripts

Run with WordPress loaded:

```bash
wp eval-file bin/demo-muster.php
wp eval-file bin/demo-muster-extended.php
```

- `bin/demo-muster.php` covers deterministic post pattern upserts.
- `bin/demo-muster-extended.php` covers idempotent post, term, user, and option upserts.

## Running Tests

Preferred (online dependencies available):

```bash
composer install
vendor/bin/phpunit
```

Offline fallback in this repo:

```bash
php bin/run-tests.php
```

The fallback runner exists to keep the slice testable when Packagist access is unavailable.

The real-WordPress suite is intentionally separate because WordPress 7's test
harness uses PHPUnit 9 while Muster's unit suite uses PHPUnit 11. Provide a
disposable MySQL database; the runner downloads matching WordPress core and
uses its transaction-backed test installation:

```bash
export WP_TEST_DB_NAME=muster_test
export WP_TEST_DB_USER=root
export WP_TEST_DB_PASSWORD=secret
export WP_TEST_DB_HOST=127.0.0.1
bin/run-integration-tests.sh
```

GitHub Actions runs both PHP 8.3/8.4 unit jobs and the WordPress 7.0.1
integration job. Never point the integration configuration at a real site
database: the WordPress test harness installs and clears prefixed tables.

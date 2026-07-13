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

- WordPress-native builders for posts, pages, terms, users, options, menus, and attachments.
- Merge-safe post, term, and user updates that preserve omitted fields.
- Seeded fake content through the curated `Victuals` Faker wrapper.
- Repeatable post Patterns with declared counts and per-pattern seed overrides.
- Immutable refs for parents, menu items, attachments, and featured images.
- ACF values derived from the active theme's `acf-json` definitions.
- Conventional `wp capstan seed` and low-level named Muster commands.
- Deterministic placeholder media for stable visual fixtures.

Full ecosystem documentation is available in the
[Muster GitBook guide](https://docs.pressgang.dev/ecosystem/muster).

## Mental Model

- `Muster`: orchestration entrypoint where your seed flow lives.
- `Victuals`: curated Faker wrapper with UK-leaning defaults.
- `Pattern`: repeatable batch runner with `count()` and optional per-pattern seed.
- `Builders`: explicit WordPress resource writers. Posts, terms, and users use
  merge-upsert behavior; menus rebuild their items, attachments are reused by
  slug, and `truncate()` is an explicitly destructive reset.

The orchestration model is informed by seeders and factories in frameworks such
as Laravel, but Muster is not a Laravel port and does not introduce Models or an
ORM over WordPress data.

## Persistence Semantics

Post, term, and user builders use **merge-upsert** behaviour: an existing
resource is found by its documented natural key, and only fields explicitly set
on the builder are updated. Omitted fields retain their existing WordPress
values; passing an empty value explicitly clears a field.

Muster distinguishes this default merge behaviour from two future explicit
modes: `ensure` (create only) and `replace` (complete declared state). The
identity and ownership contract is recorded in
[`docs/adr/0001-resource-identity-ownership-and-persistence.md`](docs/adr/0001-resource-identity-ownership-and-persistence.md).

Natural-key lookup does not yet prove Muster ownership. Until ownership-aware
reset and pruning ship, treat `truncate()` and `wp capstan seed --fresh` as
destructive development tools: they remove all content of the selected post
types or taxonomies, including content created outside Muster.

## Quick Example

```php
<?php

use PressGang\Muster\Muster;

final class DemoMuster extends Muster
{
    public function run(): void
    {
        $this->page()
            ->title('About us')
            ->slug('about-us')
            ->status('publish')
            ->content($this->victuals()->paragraphs(3))
            ->save();

        $this->pattern('events')
            ->seed(1201)
            ->count(5)
            ->build(function (int $i) {
                return $this->post('event')
                    ->title($this->victuals()->headline())
                    ->slug('event-' . $i)
                    ->status('publish');
            });
    }
}
```

## ACF-Derived Fixtures

Muster can generate field values from the active theme's `acf-json` exports:

```php
$this->post('event')
    ->title('Example event')
    ->slug('example-event')
    ->acf($this->acfFor('event'))
    ->save();
```

Use `$this->acfFor('event', 'minimal')` for required fields only. The default
`populated` variant fills every generatable field. Media and relational fields
need real WordPress IDs, so `acfFor()` may provision deterministic supporting
attachments, posts, or terms.

## Determinism

Use an explicit shared seed to make generated content repeatable between local and CI runs.

- Conventional theme seed: `wp capstan seed --seed=1234`
- Low-level named runner: `wp capstan muster <muster-class> --seed=1234`
- Pattern-level override: `->seed(9876)`

The same seed and inputs produce the same seed-controlled Faker sequence. Date
helpers that use relative boundaries still depend on the current clock; a
separate deterministic fixture epoch is planned.

## WP-CLI Usage

```bash
wp capstan seed --seed=1234
wp capstan seed --dry-run
wp capstan seed --fresh --seed=1234

wp capstan muster App\\Muster\\DemoMuster --seed=1234
wp capstan muster App\\Muster\\DemoMuster --dry-run
wp capstan muster App\\Muster\\DemoMuster --only=events
```

Flags:
- `--seed=<int>` sets global seed.
- `--dry-run` emits current intent without writes.
- `--only=<csv>` executes only matching pattern names.
- `--fresh` is available on `wp capstan seed` and calls the theme Muster's
  `fresh()` method before `run()`.

`--only` currently filters Patterns only; direct builder calls still execute.
Do not combine `--fresh` and `--only` unless the Muster's `fresh()` method is
deliberately compatible with a partial seed.

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

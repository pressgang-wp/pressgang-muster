<img src="https://github.com/pressgang-wp/pressgang-muster/blob/main/assets/img/muster-banner.png" alt="Muster">

# 🍪Muster

[![Latest Stable Version](https://poser.pugx.org/pressgang-wp/muster/v/stable)](https://packagist.org/packages/pressgang-wp/muster)
[![Total Downloads](https://poser.pugx.org/pressgang-wp/muster/downloads)](https://packagist.org/packages/pressgang-wp/muster)
[![License](https://poser.pugx.org/pressgang-wp/muster/license)](https://packagist.org/packages/pressgang-wp/muster)

**Describe the content your WordPress site needs. Run it as often as you like. Get the same site every time.**

Muster turns development and test content into code you can review, re-run, and
trust — without an ORM, without a database dump, and without ever wondering what
a seed script is about to overwrite.

## The problem

Every WordPress project needs realistic content to build against, and the usual
options all bite:

- **A database dump** goes stale the moment it lands, can't be reviewed in a pull
  request, and slowly drifts away from the code it exists to support.
- **An ad-hoc seed script** works once. Run it twice and you have duplicate
  pages — or it deletes something it didn't create.
- **Unseeded fake content** changes on every run, so a visual regression or a CI
  failure can't be reproduced or bisected.

Muster fixes all three: fixture content becomes a declarative, deterministic,
re-runnable part of your codebase.

## Why Muster

**Re-run it forever, safely.** Every resource carries a stable logical key, so a
second run updates the page it made the first time instead of creating another
one. Rename a slug or retitle a post and it still resolves — WordPress locators
stay the native lookup layer, but identity doesn't depend on them.

**Same seed, same site.** Randomness and time are explicit inputs, not ambient
state. `--seed=1234` fixes generated values; an epoch fixes every relative date.
Two machines, two months apart, produce byte-identical content — which is what
makes visual regression testing and reproducible bug reports possible.

**See it before it happens.** Every run plans first and shows you exactly what it
will create, update, keep, or delete. `--dry-run` stops there. Any conflict stops
the write entirely, so a surprise is a report rather than a support ticket.

**It only touches what it made.** Muster records what it owns. `resetOwned()` and
`pruneOwned()` delete only that — never the client's real pages sitting in the
same database. Claiming pre-existing content takes a deliberate `->adopt()`, and
adoption can never steal a resource owned by someone else.

**WordPress-native, not an ORM.** Builders write through core APIs, so hooks
fire, caches invalidate, and ACF, menus, and attachments behave exactly as they
do in wp-admin. Nothing maps your posts to a foreign object model.

**Fixtures live in code review.** A colleague can read the diff of your content
in a PR — which is not a sentence anyone has said about a `.sql` file.

## Install

```bash
composer require --dev pressgang-wp/muster
```

Requires PHP 8.3+, WordPress loaded when resources persist, and WP-CLI for the
commands below. FakerPHP comes along automatically.

> **Pre-1.0.** The public API may still change between minor versions. Pin an
> exact version if that matters to you.

Install as a dev dependency for local work, CI, and disposable environments. If a
controlled non-production runtime must seed after a `--no-dev` deploy, make it a
regular dependency instead.

## Quick start

Muster classes live in a top-level `muster/` directory, mapped under your
composer **`autoload-dev`** — they are development and test fixtures, not shipped
code:

```json
"autoload-dev": { "psr-4": { "App\\Muster\\": "muster/" } }
```

Describe what the site should contain:

```php
<?php

namespace App\Muster;

use PressGang\Muster\Muster;

final class SiteMuster extends Muster
{
    public static function defaultEpoch(): string
    {
        return '2026-01-01 09:00:00+00:00';
    }

    public function run(): void
    {
        $this->page()
            ->key('page:about')
            ->title('About us')
            ->slug('about-us')
            ->content($this->victuals()->paragraphs(3))
            ->save();

        // Five events. Rows self-key (event:1…event:5); content() fills a
        // generated title, body, and ACF; status defaults to publish and the
        // date to the fixture epoch; withThumbnail() adds a placeholder image.
        $this->pattern('event')->count(5)->withThumbnail()->build(
            fn (int $i) => $this->content('event')->slug('event-' . $i)
        );
    }
}
```

Run it:

```bash
wp capstan seed --seed=1234
```

Muster plans, shows you the plan, revalidates it against live WordPress, then
applies it:

```text
Plan:
  CREATE   post   page:about -> about-us
  CREATE   post   event:1 -> event-1
  ...
  Summary: create=6 update=0 keep=0 prune=0 conflict=0
```

Run it again and every line becomes `keep` or `update`. No duplicates, no drift.

For the common whole-surface case — some terms, N of each post type, a page per
template, a menu per location — declare a **manifest** instead of writing each
builder:

```php
public function run(): void
{
    $this->assemble([
        'terms' => ['topic' => 3],
        'posts' => ['article' => ['count' => 5, 'thumbnail' => true, 'terms' => ['topic' => 'rotate']]],
        'pages' => 'templates',
        'menus' => 'locations',
    ]);
}
```

## Core concepts

- **`Muster`** — the orchestration entrypoint where your seed flow lives.
- **`Victuals`** — a curated Faker wrapper with UK-leaning defaults, plus
  network-free `imageUrl()`, `gutenbergBlocks()`, `richContent()`, and
  `repeaterRows()`.
- **`FixtureClock`** — the immutable epoch that resolves every relative date.
- **`Pattern`** — a repeatable batch runner: `count()`, an optional seed, and
  rows that self-key from the pattern name and index. `withThumbnail()` gives
  each a placeholder featured image.
- **`content($type)`** — a post pre-filled with a generated title, body, and the
  ACF values `acfFor($type)` derives: the "populated content" shape in one place.
- **`assemble($manifest)`** — a declarative config array for the whole-surface
  case (terms, posts, pages, menus); the terse default over hand-written builders.
- **`Recipe`, states, `Sequence`** — a reusable resource shape as a class (in
  `muster/Recipes/`) with named variations, and immutable per-iteration values.
- **`Group`** — an explicit callback boundary selected by `--only`; a skipped
  group is never evaluated, so it performs no reads, writes, or random draws.
- **Builders** — the persistence boundary for posts, pages, terms, users,
  options, comments, menus, and attachments.
- **`RunReport`** — the ordered `create`/`update`/`keep`/`prune`/`conflict`
  operations for one pass, readable programmatically or as `--format=json`.

The model is informed by seeders and factories in frameworks like Laravel, but
Muster is not a Laravel port and introduces no Models or ORM over WordPress data.

## Determinism

Randomness and time are separate inputs:

```bash
wp capstan seed --seed=1234
wp capstan seed --epoch="2026-01-01 09:00:00+00:00"
```

Or set a scenario default by overriding `defaultEpoch()`, and override per
pattern with `->seed(9876)`. `$this->epoch()` returns the reference instant and
`$this->at('+1 week')` resolves from it. Victuals' `date()`, `datetime()`, and
`dateBetween()` share the same clock, so `dateBetween('+1 week', '+6 months')`
never consults the machine clock.

The same seed, epoch, call order, locale, and inputs produce the same sequence.
An explicit CLI epoch beats the scenario default. Supply neither and Muster
captures the system clock once and shares it across plan and apply — coherent
within one invocation, intentionally not repeatable across separate ones. Calls
through `victuals()->raw()` sit outside this contract.

## Persistence and ownership

Every builder created through a Muster requires a `key()` — the Muster class plus
that key form stable fixture identity, so slugs stay free to change. Pattern rows
self-key from the pattern name and one-based index, so a `pattern()` recipe needs
no explicit key.

Post, term, user, and comment builders **merge-upsert**: only fields you set are
written. Omitted fields keep their existing WordPress values; an explicitly empty
value clears the field — a declaration is a partial statement of intent, never a
complete resource. ([ADR 0001](docs/adr/0001-resource-identity-ownership-and-persistence.md)
records why.)

A natural-key match Muster doesn't own is a **conflict**, not a silent takeover:

```php
// Deliberately claim an existing unowned page, once.
$this->page()->key('page:about')->adopt()->title('About us')->slug('about-us')->save();

$this->resetOwned();              // delete only what this Muster owns
$this->pruneOwned();              // delete owned resources not touched this run
$this->pruneOwned(['page:seasonal']); // ...but keep this one
```

Ownership records live in the non-autoloaded `pressgang_muster_registry` option.
`pruneOwned()` deliberately rejects partial `--only` runs, because declarations
in skipped groups can't be judged stale; its array means "also keep", not
"complete manifest". `truncate()` remains available for a broad development
reset and is *not* used by `--fresh`.

Two things worth knowing up front: new users must declare
`->password('initial-password')` (it reaches `wp_insert_user()` only — reruns
never reset credentials, because WordPress stores a one-way hash), and comments
locate on post, parent, type, author, and deterministic GMT date, so content can
change safely.

## Recipes, patterns, and references

A **Recipe** is a reusable resource shape as a class — the ORM-free equivalent of
a factory, reusable across a site seed and a test. It lives in `muster/Recipes/`:
implement `define()` with the shape, and add named variations as methods.

```php
// muster/Recipes/EventRecipe.php
final class EventRecipe extends \PressGang\Muster\Patterns\Recipe
{
    public function define(int $i): PostBuilder
    {
        return $this->content('event')->slug($this->slugFor($i));
    }

    public function featured(): static
    {
        return $this->state(fn (PostBuilder $b, int $i) => $b->meta(['featured' => true]));
    }
}
```

`count()->create()` seeds a self-keyed batch, states compose immutably, and
`named()` gives a distinct batch identity so a test scenario can sit alongside the
site seed without colliding:

```php
$this->recipe(EventRecipe::class)->count(6)->withThumbnail()->create();
$this->recipe(EventRecipe::class)->named('spotlight')->featured()->count(2)->create();

// or drive a Pattern directly — e.g. to attach an after-hook, or cycle values
// with a Sequence ($this->sequence('draft', 'publish')->at($i)):
$this->pattern('events')->count(6)
    ->after('welcome-comment', fn ($post, int $i) => $this->comment($post)
        ->key('comment:event:' . $i)->author('Fixture Editor')->content('Welcome'))
    ->using($this->recipe(EventRecipe::class));
```

After-hooks may return a declaration, an iterable of declarations, or `null`;
returned builders run in both plan and apply and appear as normal operations. The
hook itself must not write.

Reference content that doesn't exist yet, and chain Musters in declared order:

```php
$this->call(UserMuster::class, EventMuster::class);

$about = $this->ref('page:about');   // captured before the page exists
$menu = $this->menu('Main Menu')->key('menu:main')->postItem($about, 'About');

$this->page()->key('page:about')->title('About')->slug('about')->save();
$menu->save();                        // resolves page:about now
```

`call()` shares one clock, random source, registry, groups, and report. Recursive
graphs and duplicate calls fail loudly.

## ACF-derived fixtures

Generate field values straight from the active theme's `acf-json` exports:

```php
$this->post('event')
    ->key('event:example')
    ->title('Example event')
    ->slug('example-event')
    ->acf($this->acfFor('event'))
    ->save();
```

The default `populated` variant fills every generatable field; `acfFor('event',
'minimal')` covers required fields only — the sparsest state an editor can
legally publish, and where empty-link and missing-image bugs hide. Media and
relational fields need real IDs, so `acfFor()` may provision deterministic
supporting attachments, posts, or terms under reserved `acf:*` keys — owned by
the run's root Muster, and reused (not re-created) by a later run against the same
database, so a test setup can regenerate the same fields safely.

## CLI

```bash
wp capstan seed --seed=1234                 # conventional theme seed
wp capstan seed --fresh --seed=1234         # reset owned resources, then seed
wp capstan seed --dry-run --format=json     # plan only, machine-readable
wp capstan muster App\\Muster\\EventMuster --only=events   # low-level: run a named Muster
```

| Flag | Effect |
| --- | --- |
| `--seed=<int>` | Sets the global seed. |
| `--epoch=<datetime>` | Pins the fixture clock; overrides `defaultEpoch()`. |
| `--dry-run` | Full read-only plan, no application pass. |
| `--only=<csv>` | Runs only the named declaration groups. |
| `--fresh` | (`seed` only) Deletes resources owned by that Muster, then runs. |
| `--format=json` | One structured payload of plan/apply operations, no log lines. |
| `--verbose` | Declared field names and full operation identity — never values. |
| `--quiet` | Suppresses successful output; errors still surface. |

`wp capstan seed` refuses to run when `wp_get_environment_type()` reports
`production`. Unknown `--only` names fail loudly rather than doing nothing, as do
`resetOwned()`, `pruneOwned()`, and ungrouped declarations during a partial run.

Keep `run()` declarative — it executes twice, once to plan and once to apply. Do
not send mail, call remote APIs, or perform unrelated writes inside it. Builders
are the persistence boundary; programmatic callers can read
`$context->report()->operations()`, `summary()`, or `toArray()`.

## Documentation

Full ecosystem documentation lives in the
[Muster GitBook guide](https://docs.pressgang.dev/ecosystem/muster). Muster is
part of the [PressGang ecosystem](https://pressgang.dev/).

## Testing

```bash
composer test:unit        # fast WordPress API stubs
```

The real-WordPress suite is separate because WordPress 7's harness uses PHPUnit 9
while the unit suite uses PHPUnit 11. Give it a disposable MySQL database; the
runner downloads matching core and uses its transaction-backed installation:

```bash
export WP_TEST_DB_NAME=muster_test
export WP_TEST_DB_USER=root
export WP_TEST_DB_PASSWORD=secret
export WP_TEST_DB_HOST=127.0.0.1
bin/run-integration-tests.sh
```

> **Never** point the integration config at a real site database — the harness
> installs and clears prefixed tables.

GitHub Actions runs PHP 8.3/8.4 unit jobs plus the WordPress 7.0.1 integration
job. Integration tests can `use AssertsWordPressFixtures` for posts, terms,
users, options, and comments, while `MusterSnapshot::serialize()` and
`assertMatches()` produce versioned `RunReport` JSON (volatile IDs excluded by
default; snapshots only rewritten by an explicit `write()`).

Demo scripts, with WordPress loaded:

```bash
wp eval-file bin/demo-muster.php            # deterministic post pattern upserts
wp eval-file bin/demo-muster-extended.php   # post, term, user, option upserts
```

## License

MIT — see [LICENSE](LICENSE).

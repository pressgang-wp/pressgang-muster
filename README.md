# Muster

**Muster** is a deterministic, Laravel-seeding-style API for WordPress content setup.

It keeps WordPress semantics explicit while giving you a fluent way to orchestrate repeatable data creation.

## Mental Model

- `Muster`: orchestration entrypoint where your seed flow lives.
- `Victuals`: curated Faker wrapper with UK-leaning defaults.
- `Pattern`: repeatable batch runner with `count()` and optional per-pattern seed.
- `Builders`: explicit post/term/user/option builders with idempotent-upsert intent.

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

## Determinism

Use a shared seed to make generated content repeatable between local and CI runs.

- WP-CLI shape: `wp capstan muster <muster-class> --seed=1234`
- Pattern-level override: `->seed(9876)`

The same seed and inputs should produce the same generated values.

## WP-CLI Usage

```bash
wp capstan muster App\\Muster\\DemoMuster --seed=1234
wp capstan muster App\\Muster\\DemoMuster --dry-run
wp capstan muster App\\Muster\\DemoMuster --only=events,pages
```

Flags:
- `--seed=<int>` sets global seed.
- `--dry-run` logs intent without writes.
- `--only=<csv>` executes only matching pattern names.

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

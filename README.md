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
        $this->page('About us')
            ->slug('about-us')
            ->status('publish')
            ->content($this->victuals()->content(3))
            ->save();

        $this->pattern('events')
            ->seed(1201)
            ->count(5)
            ->build(function (int $i, Muster $muster) {
                return $muster->post('event')
                    ->title($muster->victuals()->headline())
                    ->slug('event-' . $i)
                    ->status('publish');
            });
    }
}
```

## Determinism

Use a shared seed to make generated content repeatable between local and CI runs.

- WP-CLI shape: `wp capstan muster --seed=1234`
- Pattern-level override: `->seed(9876)`

The same seed and inputs should produce the same generated values.

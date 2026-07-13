# ADR 0004: Deterministic fixture clock

## Status

Accepted.

## Context

A Faker seed makes random choices repeatable, but it does not define what
“now” means. Relative date declarations such as `+1 week` therefore drifted
between runs and could even differ between Muster's planning and application
passes. Pinning every post date manually did not cover ACF-generated dates or
other Victuals helpers.

## Decision

Muster gives every execution context one immutable `FixtureClock`. Its epoch is
resolved independently of the random seed and is shared by context-level and
Pattern-level Victuals instances.

A scenario can provide a stable default:

```php
public static function defaultEpoch(): string
{
    return '2026-01-01 09:00:00+00:00';
}
```

The CLI can override it with `--epoch=<datetime>`. Programmatic callers can pass
a `FixtureClock` to `MusterContext`. If no epoch is configured, Muster captures
the system clock once and shares that same instant across plan and apply.

`Muster::epoch()`, `Muster::at()`, and Victuals `date()`, `datetime()`, and
`dateBetween()` all use this clock. Relative boundaries are resolved to absolute
datetimes before Faker selects a value. Direct use of `Victuals::raw()` remains
outside the clock contract.

Epoch strings must begin with an absolute `YYYY-MM-DD` date. Missing timezone
information is interpreted as UTC so results do not depend on machine settings.

## Consequences

- A seed controls randomness; an epoch controls time.
- Plan and apply cannot drift across the wall clock.
- Generated ACF date values can be stable with the rest of a scenario.
- Capstan scaffolds a scenario-level default epoch which developers can edit.
- An unpinned scenario remains intentionally time-dependent across separate CLI
  invocations, while staying internally coherent within one invocation.

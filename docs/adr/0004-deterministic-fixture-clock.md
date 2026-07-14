# ADR 0004: Deterministic fixture clock

- Status: Accepted
- Date: 2026-07-13

## Context

A Faker seed makes random choices repeatable, but it does not define what "now"
means. Relative date declarations such as `+1 week` therefore resolve against the
wall clock: they drift between runs, and can even differ between Muster's planning
and application passes.

Pinning every post date manually did not cover ACF-generated dates or other
Victuals helpers, so the fix cannot live in individual declarations.

## Decision

Muster gives every execution context one immutable `FixtureClock`. Its epoch is
resolved independently of the random seed and is shared by context-level and
Pattern-level Victuals instances.

Precedence is explicit: `--epoch=<datetime>` overrides a scenario's
`defaultEpoch()`, which overrides the system clock. Programmatic callers can pass
a `FixtureClock` to `MusterContext`. When no epoch is configured, Muster captures
the system clock once and shares that same instant across plan and apply.

`Muster::epoch()`, `Muster::at()`, and Victuals `date()`, `datetime()`, and
`dateBetween()` all use this clock. Relative boundaries are resolved to absolute
datetimes before Faker selects a value. Direct use of `Victuals::raw()` is
deliberately outside the clock contract.

Epoch strings must begin with an absolute `YYYY-MM-DD` date. Missing timezone
information is interpreted as UTC, so results do not depend on machine settings.

## Consequences

- Plan and apply cannot drift across the wall clock.
- Generated ACF date values are stable with the rest of a scenario.
- An unpinned scenario is intentionally time-dependent across separate CLI
  invocations, while staying internally coherent within one invocation.

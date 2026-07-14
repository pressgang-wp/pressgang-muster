# ADR 0003: Named declaration groups

- Status: Accepted
- Date: 2026-07-13

## Context

A partial-run flag needs a unit of selection that describes a complete execution
boundary.

Selecting by Pattern name cannot be that boundary: direct builders and helper
calls in the same scenario would still run. Trying instead to make every builder
silently skippable would still evaluate method arguments, Victuals calls, ACF
support provisioning, and arbitrary PHP around the builder. That would make
partial runs difficult to reason about.

Only a callback can withhold *evaluation* rather than merely suppressing *effect*.

## Decision

A Muster may declare explicit callback boundaries:

```php
$this->group('events', function (): void {
    // Builders, Patterns, generated values, and ACF provisioning
    // are skipped as one unit when this group is not selected.
});
```

`--only=events` selects the group, not the Pattern. A skipped group's callback is
not invoked, so all work inside the boundary is consistently absent from the pass.

Group names are explicit, non-empty, unique within a pass, and cannot be nested.
Unknown requested names are conflicts. When `--only` is active, data declarations
outside groups are conflicts. `resetOwned()` and `pruneOwned()` declarations are
also refused, because they reconcile the complete ownership scope.

## Consequences

- Skipped declarations perform no WordPress reads or writes and consume no fake
  data.
- Pattern names serve diagnostics and seed overrides; they do not define CLI
  selection boundaries.
- `--fresh --only` is intentionally destructive: it resets the Muster's full
  ownership scope, then applies only the selected groups.

# ADR 0003: Named declaration groups

## Status

Accepted.

## Context

Muster originally applied `--only` to Pattern names. Direct builders and helper
calls in the same scenario still ran, so the flag did not describe a complete
execution boundary. Trying to make every builder silently skippable would still
evaluate method arguments, Victuals calls, ACF support provisioning, and arbitrary
PHP around the builder. That would make partial runs difficult to reason about.

## Decision

A Muster may declare explicit callback boundaries:

```php
$this->group('events', function (): void {
    $this->page()->key('page:events')->title('Events')->slug('events')->save();

    $this->pattern('event-fixtures')->count(5)->build(
        fn (int $i) => $this->event()
            ->key('event:' . $i)
            ->title($this->victuals()->headline())
            ->slug('event-' . $i)
    );
});
```

`--only=events` selects the group, not the Pattern. A skipped group's callback
is not invoked. This makes all work inside the boundary—including direct
builders, Patterns, generated values, and ACF support resources—consistently
absent from the pass.

Group names are explicit, non-empty, unique within a pass, and cannot be nested.
Unknown requested names are conflicts. When `--only` is active, data declarations
outside groups are conflicts. `resetOwned()` and `pruneOwned()` declarations are
also refused because they reconcile the complete ownership scope. CLI
`--fresh --only` remains an explicit lifecycle reset before selected groups run.

## Consequences

- Partial runs have a visible, inspectable unit of selection.
- Skipped declarations perform no WordPress reads or writes and consume no fake
  data.
- Patterns retain names for diagnostics and seed overrides, but no longer define
  CLI selection boundaries.
- Existing scenarios may remain ungrouped for complete runs. They must add groups
  before using `--only`.
- `--fresh --only` remains intentionally destructive: it resets the Muster's full
  ownership scope, then applies only the selected groups.

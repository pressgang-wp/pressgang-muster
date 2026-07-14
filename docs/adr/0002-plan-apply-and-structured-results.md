# ADR 0002: Plan/apply reconciliation and structured results

- Status: Accepted
- Date: 2026-07-13

## Context

A preview is only useful if it resolves current WordPress state. To be worth
trusting it must distinguish creation from update, prove a no-op, show owned
pruning, and surface collisions in machine-readable form. Describing authored
intent alone cannot do any of those things.

WordPress also prevents a conventional ORM unit-of-work approach. Beyond the
absence of a shared persistence contract (see [ADR 0001](0001-resource-identity-ownership-and-persistence.md)),
new IDs are needed immediately in order to wire later relationships, so writes
cannot be deferred to a single commit at the end of a run.

## Decision

CLI reconciliation uses two deterministic passes:

1. A read-only planning pass runs the Muster and resolves WordPress state.
2. Unless `--dry-run` was requested or planning found a conflict, an application
   pass re-runs the Muster, re-resolves state, and performs writes.

Both passes produce an ordered `RunReport` containing `create`, `update`, `keep`,
`prune`, and `conflict` operations. Human output prints both summaries;
`--format=json` emits one payload containing the plan and optional apply report.

The plan is advisory rather than a serialized transaction. The application pass
revalidates ownership and locators immediately before each write. This is
important because WordPress has no transaction spanning every supported API.

## Consequences

- `--dry-run` performs real reads and accurately previews owned reset/pruning.
- Fresh planning uses an in-memory deletion overlay, so it reports prune followed
  by create without deleting content.
- Muster `run()` executes twice during a normal CLI application and must remain
  declarative; unrelated I/O and writes do not belong there.
- Core comparable fields may report `keep`; adapter-owned payloads without a read
  contract are conservatively classified as updates.
- A conflict aborts the remaining pass and prevents application.

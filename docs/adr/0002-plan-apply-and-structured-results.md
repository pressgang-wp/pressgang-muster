# ADR 0002: Plan/apply reconciliation and structured results

- Status: Accepted
- Date: 2026-07-13

## Context

Dry-run logging previously described authored intent without resolving current
WordPress state. It could not distinguish creation from update, prove a no-op,
show owned pruning, or surface collisions in machine-readable form.

WordPress also prevents a conventional ORM unit-of-work approach: posts, terms,
users, options, menus, media, and plugin fields use different public APIs, and
new IDs are needed immediately to wire later relationships.

## Decision

CLI reconciliation uses two deterministic passes:

1. A read-only planning pass runs the Muster and resolves WordPress state.
2. Unless `--dry-run` was requested or planning found a conflict, an application
   pass re-runs the Muster, re-resolves state, and performs writes.

Both passes produce an ordered `RunReport` containing `create`, `update`,
`keep`, `prune`, and `conflict` operations. Human output prints both summaries;
`--format=json` emits one payload containing the plan and optional apply report.

The plan is advisory rather than a serialized transaction. The application
pass revalidates ownership and locators immediately before each write. This is
important because WordPress has no transaction spanning every supported API.

## Consequences

- `--dry-run` performs real reads and accurately previews owned reset/pruning.
- Fresh planning uses an in-memory deletion overlay, so it reports prune followed
  by create without deleting content.
- Muster `run()` executes twice during a normal CLI application and must remain
  declarative; unrelated I/O and writes do not belong there.
- Builders remain the persistence boundary and must report their outcome.
- Core comparable fields may report `keep`; adapter-owned payloads without a
  read contract are conservatively classified as updates.
- A conflict aborts the remaining pass and prevents application.

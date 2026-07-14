# Architecture decision records

These records exist to explain **why** Muster is built the way it is — the
forcing constraints it works around, and the alternatives it rejected. They are
not user documentation: for what Muster does and how to use it, see the
[README](../../README.md) and the
[Muster guide](https://docs.pressgang.dev/ecosystem/muster).

An ADR earns its place here by recording a decision that cannot be reconstructed
from the source. Behaviour that the code already states plainly does not need one.

| # | Decision | Why it exists |
| --- | --- | --- |
| [0001](0001-resource-identity-ownership-and-persistence.md) | Ownership-scoped resource identity and merge persistence | WordPress has no shared persistence contract, and a natural key locates a resource without proving Muster owns it. |
| [0002](0002-plan-apply-and-structured-results.md) | Plan/apply reconciliation and structured results | WordPress has no transaction spanning its APIs, so the plan is advisory and the apply pass revalidates. |
| [0003](0003-named-declaration-groups.md) | Named declaration groups | Only a callback boundary can withhold evaluation, rather than merely suppressing effect. |
| [0004](0004-deterministic-fixture-clock.md) | Deterministic fixture clock | A seed makes randomness repeatable but does not define what "now" means. |

Records are immutable once accepted. Supersede a decision with a new record
rather than rewriting history in an old one.

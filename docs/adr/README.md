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
| [0005](0005-seedable-location-params.md) | Seedable location params and the framework-agnostic boundary | One list of reachable ACF params keeps consumers in sync, and gates Muster against absorbing theme-framework assumptions. |
| [0006](0006-seeder-authoring-ergonomics.md) | Seeder authoring ergonomics | Cut accidental verbosity in stages while keeping keys stable and determinism/ownership/plan-apply intact. |
| [0007](0007-vocabulary-not-orm-factories.md) | Vocabulary is not the ORM factory vocabulary | "Factory" implies a Model and an ORM WordPress lacks; Muster keeps Muster/Definition/Victuals (Recipe noted as a candidate rename). |
| [0008](0008-definition-renamed-to-recipe.md) | `Definition` renamed to `Recipe` | Completes the Muster → Recipe → Victuals metaphor; legible, ORM-free, no "Factory". Supersedes the ADR 0007 candidate note. |
| [0009](0009-scaffolding-recipes-from-content-and-acf.md) | Scaffolding Recipes from content and ACF schema (`wp capstan make recipe`) | The field-type → value mapping is the crux; v1 (scalar fields) shipped in Capstan, relational/nested fields left as TODO stubs. |
| [0010](0010-array-fill-and-manifest-values.md) | Array `fill()` for builders, and explicit values in manifests | Neither the fluent builders nor the generative manifest could carry explicit field values as data; capture mode (ADR 0009) needs a WP-native shape to emit into. |

Records are immutable once accepted. Supersede a decision with a new record
rather than rewriting history in an old one.

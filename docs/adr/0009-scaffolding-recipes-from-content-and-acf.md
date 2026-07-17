# ADR 0009: Scaffolding Recipes from content and ACF schema (`wp capstan make recipe`)

- Status: Accepted — v1 (scalar fields) implemented in Capstan
- Date: 2026-07-17

## Context

Writing a [Recipe](0008-definition-renamed-to-recipe.md) by hand means reading a
post type or page template's ACF field groups and, for each field, choosing a
value shape — a sentence here, an attachment there, a reference to other seeded
content for a relationship. It is exactly the mechanical work Muster exists to
remove, yet the Recipes that *drive* Muster are still authored by hand.

Capstan already scaffolds the theme-wide seeder: `wp capstan make muster` reads
the theme's shape (post types, `get_page_templates()`, ACF JSON) and writes a
`SiteMuster`. What is missing is the **granular** counterpart — generate a single
`muster/Recipes/{Name}Recipe.php` for one page, template, or type.

The hard part is already solved. Muster's
[`AcfJson::targets()`](0005-seedable-location-params.md) maps a field group to the
surfaces it applies to via `SEEDABLE_PARAMS` (`post_type`, `page_template`,
`options_page`, …); `acf_get_fields()` yields a group's fields; `get_fields($id)`
yields a concrete entity's values. Scaffolding a Recipe is *assembling* these, not
new capability.

Laravel offers two distinct tools here, and the request conflates them: `make:factory`
(stub a factory from a model's columns) and `orangehill/iseed` (generate a seeder
from existing rows). Both are worth having, and they are genuinely different.

## Decision

Add **`wp capstan make recipe <Name>`** — a Capstan scaffolding command that writes
a Muster `Recipe` class, in the same preview-then-`--force` mould as
`make controller`/`make muster`. It has two modes:

| Mode | Flag | Reads | Analogue |
|---|---|---|---|
| **Schema** | `--post-type=<t>` / `--page-template=<slug>` / `--options-page=<slug>` | the applicable ACF groups (via `AcfJson::targets()` + `acf_get_fields()`), stubbed by field type | `make:factory` |
| **Capture** | `--from-post=<id\|slug>` | core fields + `get_fields($id)` — the *actual values* | `orangehill/iseed` |

Schema mode yields a reusable generic Recipe; capture mode reproduces one specific
entity. The command depends on Muster being installed (like `make muster`/`seed`),
and writes to `muster/Recipes/{Name}Recipe.php`.

### Shape of the output

`define()` returns a builder chain over the confirmed `PostBuilder` surface
(`title`/`template`/`content`/`acf`/…), values sourced from `victuals()`:

```php
final class LandingPageRecipe extends Recipe
{
    public function define(int $iteration): PersistableDeclaration
    {
        return $this->content('page')
            ->title($this->victuals()->sentence())
            ->template('templates/landing.php')
            ->acf([
                'hero_heading' => $this->victuals()->sentence(),   // text
                'hero_image'   => $this->attachment('landing-hero'), // image
                'show_sidebar' => true,                              // true_false
                // TODO relationship 'related_pages' → $this->ref('page:<key>')
                // TODO repeater 'features' → nested rows
            ]);
    }
}
```

### The crux: ACF field type → value

This mapping is the design substance. It is a gradient of difficulty, and v1 draws
the line honestly rather than pretending to solve the relational graph:

| ACF field type | Schema mode | Capture mode |
|---|---|---|
| text, textarea, wysiwyg, email, url, number, range | `victuals()` faker by kind | literal value |
| true_false | `true` | literal bool |
| select, radio, button_group, checkbox | first choice / choice array | literal selection |
| date / date_time / time picker | epoch-relative deterministic date | literal (normalised) |
| color_picker, google_map, link | fixed structured stub | literal structure |
| image, file | `attachment('<slug>')` placeholder | **decision**: re-seed placeholder *or* copy source file |
| gallery | array of `attachment()` | array of the above |
| **post_object, relationship, page_link** | **`TODO` stub** → `ref('post:<key>')` | map real IDs → logical keys **iff the target is also seeded**, else `TODO` |
| **taxonomy** | `TODO` → `ref('term:<key>')` / slugs | mapped terms |
| **user** | `TODO` → `ref('user:<key>')` | mapped user |
| **repeater, group, flexible_content, clone** | nested/recursive emission | nested literal (recurse) |

The **bold rows are the hard cases**: relational fields are references to *other
seeded content*, so their raw IDs are meaningless in a fixture — they must resolve
to a logical key via `ref()`, which requires that target to be seeded too. Nested
fields are recursive. v1 emits these as **commented `TODO`s** carrying the field
name and type, so the developer finishes the wiring; the scaffold does the tedious
80%, not a wrong 100%.

### Ownership

The command **and** the field mapper live in **Capstan** (it owns `make *` and the
Muster code templates such as `SiteMusterTemplate`): scaffolding emits authoring
*code* — `Victuals` expressions and captured literals — not runtime values, so it
belongs with Capstan's other generators (`AcfFieldMapper` + `RecipeTemplate`).
**Muster** owns the `Victuals` vocabulary that generated code targets, and the
mapper tracks it. Same split as everything else: Capstan scaffolds, Muster seeds.

> Note: an earlier draft placed the mapper in Muster. It moved to Capstan on
> implementation — the mapper produces *code strings*, a scaffolding concern,
> not runtime values.

### Phased scope

- **v1 (shipped)** — `wp capstan make recipe` with `--post-type` / `--page-template`
  (schema) and `--from-post` (capture), mapping the **scalar** fields (text,
  textarea, number, email, url, select, radio, true_false). Every other field —
  media, dates, relations, repeaters — is emitted as a `TODO` stub.
  Preview-then-`--force`; `slugFor()` keys; no-overwrite.
- **Next** — dates (epoch-relative) and images/attachments.
- **Later** — resolve relational fields to `ref()` when the target is (or can be)
  seeded; recurse into repeater/flexible/group; a `--with-refs` mode that also
  scaffolds Recipes for the referenced targets.

## Consequences

- The Recipes that drive Muster become scaffoldable, closing the last hand-authored
  gap between "theme shape" (`make muster`) and "runtime seeding".
- Capture mode turns a real, tricky page — the kind that reproduces a visual bug —
  into a deterministic fixture in one command.
- The field mapper is a single source of truth reused by any future ACF-aware
  tooling (not just this command).

## Open questions

- **Capture-mode media**: re-seed a deterministic placeholder (loses the real
  image, keeps determinism) or copy the source file (faithful, heavier, drifts)?
  Default to placeholder with a `--copy-media` opt-in.
- **Capture-mode fidelity vs. hygiene**: real content may carry PII or very large
  bodies. Capture verbatim, or route text through `victuals()` and keep only the
  structure? Likely a `--verbatim` flag, structure-preserving by default.
- **Field discovery source**: `acf_get_field_groups()` covers JSON-, PHP-, and
  DB-registered groups once ACF is loaded — confirm no theme registers groups too
  late for a `@when after_wp_load` command to see.

## Not chosen

- **A raw DB dump to a seeder.** Capturing rows without the ACF field-type lens
  produces meaningless IDs and unresolved relations — the opposite of a Recipe.
- **Auto-resolving the whole relational graph in v1.** Guessing which target a
  relationship should point at, and seeding it transitively, is where correctness
  goes to die; `TODO` stubs keep the developer in the loop where judgement is
  needed.
- **A new top-level command verb.** `make recipe` sits under the existing `make`
  family and Muster's Recipe vocabulary; no new surface to learn.

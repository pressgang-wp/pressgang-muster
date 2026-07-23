# ADR 0010: Array `fill()` for builders, and explicit values in manifests

- Status: Accepted
- Date: 2026-07-23

## Context

Muster had two ways to declare a resource, and neither could carry **explicit
field values as data**:

- **Fluent builders** (`->title()->slug()->acf([...])`) express any value, but
  only as chained PHP calls. You cannot hand a builder a captured array.
- **The manifest** (`assemble([...])`, ADR 0006) is data, but *generative*: its
  vocabulary is `count`, `thumbnail`, `terms: rotate`. It delegates the actual
  field values to `content()`/`acfFor()`. There is no way to say
  `hero_title => "…"` in a manifest.

That gap has a concrete consumer. ADR 0009's `wp capstan make recipe --from-post`
(**capture mode**) reproduces one specific entity's *actual values* — it needs a
data shape to emit those values into. Without one, capture has to generate PHP
setter chains by hand.

A second, smaller forcing function: ADR 0009 and the raw-meta-vs-ACF guard drew a
hard line between two write channels — plain post/term meta
(`update_post_meta`/`update_term_meta`) and ACF fields (`update_field`). Any
data shape for a resource has to keep those channels distinct, or it reintroduces
exactly the silent mis-write the guard exists to stop.

## Decision

Add `fill(array $attributes): self` to `PostBuilder` and `TermBuilder`: a nested
array that **dispatches each key to the matching fluent setter**. It is sugar
over the fluent surface, never a parallel implementation — ref resolution, the
meta-vs-ACF guard, and merge-upsert all apply unchanged because the setters do
the work.

```php
$this->post('event')->fill([
    'post_title'  => 'Launch',
    'post_name'   => 'launch',
    'post_status' => 'publish',
    'meta_input'  => ['legacy_id' => 42],   // raw meta channel
    'tax_input'   => ['topic' => ['design']],
    'acf'         => ['hero_title' => 'Hello'], // ACF channel
    'key'         => 'event:launch',
])->save();
```

### Nested arrays, not dot notation

The channels are nested sub-arrays (`acf`, `meta_input`, `tax_input`), not
flattened keys (`acf.hero_title`). Nested wins because:

- Each sub-array maps 1:1 to an existing setter, so the mapper reads like the
  code it replaces — no string-splitting mini-DSL, the kind of implicit
  behaviour the design rules reject.
- It has no ambiguity: an ACF or meta key that itself contains a dot is fine.
- It extends to the hard cases. ACF repeaters/groups are already nested arrays
  (`acf => ['features' => [[…],[…]]]`); `acf.features.0.title` is where dot
  notation collapses.

Flatness has one genuine merit — trivial to serialise, diff, and capture on one
line. If that is ever wanted, it is a flattened *view* of this same shape, not a
second code path.

### The vocabulary is WordPress's own

Keys are the ones `wp_insert_post()` / `wp_insert_term()` already accept —
`post_title`, `post_name`, `post_status`, `post_content`, `post_excerpt`,
`post_date`, `post_parent`, `post_author`, `page_template`, `meta_input`,
`tax_input` for posts; `name`, `slug`, `description`, `parent`, `meta_input` for
terms. A developer who knows WordPress learns no second vocabulary — `fill()` is
"the `wp_insert_post()` array you already write." The only additions are the two
things WordPress has no key for: `acf` (an `update_field()`-shaped map) and
Muster's logical identity `key`/`adopt`.

Terms have no native meta-array argument, so they borrow the post convention
`meta_input` rather than invent a term-only key — one meta vocabulary across both
builders. The fluent setters keep their friendlier names (`title()`, `slug()`);
`fill()` is the WP-native register, and the two coexist because a `fill()` key
simply calls the corresponding setter.

### Unrecognised keys throw

A key that is not a recognised field raises `LogicException` listing the accepted
set. A typo'd `titel`, or a `post_title` handed to a `TermBuilder`, fails loudly
rather than vanishing — the same "no silent drop" rule as the meta-vs-ACF guard.

### Manifests can carry explicit values

A manifest post spec gains an optional `fill` block, applied through
`PostBuilder::fill()` to every generated row **after** its generated content and
self-keyed slug, so the explicit values win:

```php
$this->assemble([
    'posts' => [
        'article' => ['count' => 3, 'thumbnail' => true, 'fill' => [
            'post_status' => 'draft',
            'acf'         => ['featured' => true],
        ]],
    ],
]);
```

Row identity stays self-keyed (`article-1`, `article-2`, …); `fill` layers shared
attributes on top. A `post_name` in a manifest `fill` with `count > 1` makes rows
share a locator and idempotently collapse to one — use `count: 1` or a Recipe for
explicit per-row identity. This closes the "manifest cannot express explicit
values" gap and gives capture mode a direct emission target.

### Recipes get it for nothing

`Recipe::define()` already returns a builder, and `fill()` is on the builder — so
a Recipe body writes `return $this->post('event')->fill([...])` with no change to
the Recipe base. Capture-mode scaffolding (ADR 0009) emits exactly that.

## Consequences

- Fixtures become expressible as data at every altitude: a single builder, a
  manifest spec, or a Recipe — all on one WP-native attribute vocabulary.
- Capture mode (ADR 0009 `--from-post`) has a shape to emit into; a captured page
  is a `fill([...])` array.
- The meta-vs-ACF guard covers the data path automatically: `meta_input` routes
  through `->meta()` and still throws on an ACF-field collision.
- `fill()` cannot express anything the fluent API cannot — by construction. When
  a new setter is added, decide whether it earns a `fill()` key.

## Not chosen

- **Dot notation as the primary shape.** Flatter to serialise, but a string DSL
  with dot-in-key ambiguity and no clean path to nested repeaters — against the
  "clarity over cleverness" rule.
- **A friendlier, non-WordPress vocabulary** (`title`, `slug` in `fill()` too).
  Rejected: the value of `fill()` is that it *is* the WordPress array; a third
  name for `post_title` is the vocabulary tax the decision set out to avoid.
- **`fill()` as a replacement for the fluent builders.** The fluent surface stays
  the typed, discoverable, doc-blocked source of truth; `fill()` is sugar over
  it. A `fill()` that diverged from the setters would be the magic the guide bans.
- **Per-row explicit identity in a manifest.** Giving each generated row a
  different explicit slug/title is a Recipe's job; the manifest stays terse and
  generative, with `fill` for shared overrides.
```

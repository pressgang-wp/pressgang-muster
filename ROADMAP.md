# Muster Roadmap

## Current State

Muster is a WordPress-native orchestrator for deterministic content
provisioning and development fixtures. Every currently charted milestone is
implemented: reconciliation, builders, generic factory ergonomics, ordered
orchestration, logical-key relationships, generated content, CLI output, and
testing utilities all have executable coverage.

### Implemented

- **PostBuilder** — merge-upsert posts & custom post types by `post_type + slug`
- **TermBuilder** — merge-upsert taxonomy terms by `taxonomy + slug`
- **UserBuilder** — merge-upsert WordPress users by `user_login`
- **OptionBuilder** — WordPress options (upsert by `option_name`)
- **CommentBuilder** — comments and threaded replies with deterministic native locators
- **Patterns** — batch factory runner with `count()`, per-pattern `seed()`, iteration index
- **Victuals** — seeded Faker wrapper (en_GB) with WordPress-friendly helpers
- **CLI** — conventional `wp capstan seed`, low-level `wp capstan muster`, production guard, owned `--fresh`, read-only planning, JSON reports, seed and declaration-group filters
- **ACF adapter interface** — pluggable ACF integration (NullAcfAdapter ships)
- **Persistence contract** — documented `ensure`, `merge`, and `replace` semantics; merge is the current default
- **Ownership contract** — required logical keys, explicit adoption, collision detection, and owned-only reset/prune
- **Reconciliation reports** — plan/apply passes with create/update/keep/prune/conflict operations and JSON output
- **Named groups** — explicit callback boundaries that make partial `--only` runs complete and side-effect free outside the selection
- **Fixture clock** — scenario or CLI epoch shared by plan/apply and relative Victuals date helpers
- **Test suites** — fast WordPress API stubs plus real WordPress 7 core/database integration coverage

---

## Pipeline

### High Priority

#### Trustworthy Reconciliation

- [x] **Merge-safe updates** — omitted post, term, and user fields retain their existing values
- [x] **Persistence ADR** — define ensure/merge/replace and separate logical identity from WordPress locators
- [x] **Logical keys and ownership** — stable Muster identity independent of mutable slugs
- [x] **Collision policy** — refuse unowned natural-key matches unless explicitly adopted
- [x] **Owned reset/prune** — delete only resources owned by the selected Muster scenario
- [x] **Plan/apply lifecycle** — inspect first, then report/create/update/keep/prune/conflict
- [x] **Structured result output** — operation summaries and `--format=json`
- [x] **Named groups** — make `--only` select every declaration in a scenario, not just Patterns
- [x] **Deterministic clock** — separate the fixture epoch from Faker's random seed
- [x] **WordPress integration suite** — verify core API behaviour against a real WordPress runtime

#### New Builders

- [x] **AttachmentBuilder** — register media (images, PDFs), set as featured image on posts, deterministic generated placeholders (`wp_insert_attachment()`, `wp_generate_attachment_metadata()`)
- [x] **MenuBuilder** — create nav menus and menu items with ordering, parent/child nesting, custom links, post/term/page targets (`wp_create_nav_menu()`, `wp_update_nav_menu_item()`)
- [x] **CommentBuilder** — create comments on posts with author, content, status (approved/pending/spam), threaded replies (`wp_insert_comment()`)

#### Factory Ergonomics

- [x] **Generic Patterns** — accept a common declaration contract instead of only `PostBuilder`
- [x] **States** — named state variants after persistence modes and ownership are stable
- [x] **Sequences** — cycling values across pattern iterations
- [x] **Default definitions** — reusable explicit resource definitions without introducing an ORM
- [x] **After-hooks** — explicit post-save side effects with inspectable plan output

#### Orchestration

- [x] **Muster chaining** — `$this->call(UserMuster::class, EventMuster::class)` to run multiple musters in sequence with dependency ordering

---

### Medium Priority

#### Relationships & Cross-References

- [x] **Ref registry** — named store backed by stable logical resource keys
- [x] **Lazy ref resolution** — resolve refs at save-time rather than creation-time, enabling forward references
- [x] **Explicit relationships** — WordPress-native reference wiring without ORM-style model inference

#### ACF Adapter

- [x] **LiveAcfAdapter** — real implementation calling `update_field()` (repeater/group values pass through as arrays; `update_sub_field()` granularity still open)
- [x] **Repeater fields** — nested array to ACF repeater row format
- [x] **Flexible content** — layout-based field groups
- [x] **Gallery fields** — array of attachment IDs
- [x] **Group fields** — nested key-value mapping

#### Data Generation (Victuals)

- [x] **Placeholder images** — `$victuals->imageUrl(width, height)` returns a self-contained seeded SVG data URL; attachment fixtures retain native `placeholder()` support
- [x] **Gutenberg blocks** — `$victuals->gutenbergBlocks()` to generate block editor content
- [x] **Rich HTML content** — `$victuals->richContent()` with headings, lists, links, blockquotes
- [x] **ACF repeater data** — `$victuals->repeaterRows(count, schema)` for structured field content

#### Reset / Teardown

- [x] **Truncate capability** — clean-slate reset before seeding (delete all posts of a type, truncate terms, etc.)

---

### Low Priority

#### Logging & Output

- [x] **WpCliLogger** — pipes visible intent to `WP_CLI::log()` and details to `WP_CLI::debug()`
- [x] **Progress reporting** — bounded per-pattern progress output during long runs
- [x] **Verbose/quiet modes** — `--verbose` per-field-name diagnostics and `--quiet` successful-output suppression

#### Testing Utilities

- [x] **Assertion helpers** — `assertPostExists('slug')`, `assertTermExists('taxonomy', 'slug')` for integration tests
- [x] **Snapshot testing** — serialize versioned Muster reports for regression comparison

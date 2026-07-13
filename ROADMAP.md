# Muster Roadmap

## Current State

Muster is a WordPress-native orchestrator for deterministic content
provisioning and development fixtures. Ownership-aware reconciliation and the
read-only plan/apply lifecycle are implemented; named declaration groups and a
deterministic clock are the next major stability work.

### Implemented

- **PostBuilder** — merge-upsert posts & custom post types by `post_type + slug`
- **TermBuilder** — merge-upsert taxonomy terms by `taxonomy + slug`
- **UserBuilder** — merge-upsert WordPress users by `user_login`
- **OptionBuilder** — WordPress options (upsert by `option_name`)
- **Patterns** — batch factory runner with `count()`, per-pattern `seed()`, iteration index
- **Victuals** — seeded Faker wrapper (en_GB) with WordPress-friendly helpers
- **CLI** — conventional `wp capstan seed`, low-level `wp capstan muster`, production guard, owned `--fresh`, read-only planning, JSON reports, seed and Pattern filters
- **ACF adapter interface** — pluggable ACF integration (NullAcfAdapter ships)
- **Persistence contract** — documented `ensure`, `merge`, and `replace` semantics; merge is the current default
- **Ownership contract** — required logical keys, explicit adoption, collision detection, and owned-only reset/prune
- **Reconciliation reports** — plan/apply passes with create/update/keep/prune/conflict operations and JSON output
- **Test suite** — WordPress API stubs and deterministic Faker coverage

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
- [ ] **Named groups** — make `--only` select every declaration in a scenario, not just Patterns
- [ ] **Deterministic clock** — separate the fixture epoch from Faker's random seed
- [ ] **WordPress integration suite** — verify core API behaviour against a real WordPress runtime

#### New Builders

- [x] **AttachmentBuilder** — register media (images, PDFs), set as featured image on posts, deterministic generated placeholders (`wp_insert_attachment()`, `wp_generate_attachment_metadata()`)
- [x] **MenuBuilder** — create nav menus and menu items with ordering, parent/child nesting, custom links, post/term/page targets (`wp_create_nav_menu()`, `wp_update_nav_menu_item()`)
- [ ] **CommentBuilder** — create comments on posts with author, content, status (approved/pending/spam), threaded replies (`wp_insert_comment()`)

#### Factory Ergonomics

- [ ] **Generic Patterns** — accept a common declaration contract instead of only `PostBuilder`
- [ ] **States** — named state variants after persistence modes and ownership are stable
- [ ] **Sequences** — cycling values across pattern iterations
- [ ] **Default definitions** — reusable explicit resource definitions without introducing an ORM
- [ ] **After-hooks** — explicit post-save side effects with inspectable plan output

#### Orchestration

- [ ] **Muster chaining** — `$this->call(UserMuster::class, EventMuster::class)` to run multiple musters in sequence with dependency ordering

---

### Medium Priority

#### Relationships & Cross-References

- [ ] **Ref registry** — named store backed by stable logical resource keys
- [ ] **Lazy ref resolution** — resolve refs at save-time rather than creation-time, enabling forward references
- [ ] **Explicit relationships** — WordPress-native reference wiring without ORM-style model inference

#### ACF Adapter

- [x] **LiveAcfAdapter** — real implementation calling `update_field()` (repeater/group values pass through as arrays; `update_sub_field()` granularity still open)
- [x] **Repeater fields** — nested array to ACF repeater row format
- [x] **Flexible content** — layout-based field groups
- [x] **Gallery fields** — array of attachment IDs
- [x] **Group fields** — nested key-value mapping

#### Data Generation (Victuals)

- [ ] **Placeholder images** — `$victuals->imageUrl(width, height)` for attachment builders
- [ ] **Gutenberg blocks** — `$victuals->gutenbergBlocks()` to generate block editor content
- [ ] **Rich HTML content** — `$victuals->richContent()` with headings, lists, links, blockquotes
- [ ] **ACF repeater data** — `$victuals->repeaterRows(count, schema)` for structured field content

#### Reset / Teardown

- [x] **Truncate capability** — clean-slate reset before seeding (delete all posts of a type, truncate terms, etc.)

---

### Low Priority

#### Logging & Output

- [x] **WpCliLogger** — pipes visible intent to `WP_CLI::log()` and details to `WP_CLI::debug()`
- [ ] **Progress reporting** — per-pattern progress output during long runs
- [ ] **Verbose/quiet modes** — `--verbose` flag for detailed per-field logging

#### Testing Utilities

- [ ] **Assertion helpers** — `assertPostExists('slug')`, `assertTermExists('taxonomy', 'slug')` for integration tests
- [ ] **Snapshot testing** — serialize muster output for regression comparison

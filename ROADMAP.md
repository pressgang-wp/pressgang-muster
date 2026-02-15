# Muster Roadmap

## Current State

Muster is a Laravel-seeding-style orchestrator for deterministic WordPress data setup. The core architecture is stable and tested (23 tests across 9 classes).

### Implemented

- **PostBuilder** — posts & custom post types (upsert by `post_type + slug`)
- **TermBuilder** — taxonomy terms (upsert by `taxonomy + slug`)
- **UserBuilder** — WordPress users (upsert by `user_login`)
- **OptionBuilder** — WordPress options (upsert by `option_name`)
- **Patterns** — batch factory runner with `count()`, per-pattern `seed()`, iteration index
- **Victuals** — seeded Faker wrapper (en_GB) with WordPress-friendly helpers
- **CLI** — `wp capstan muster <class> [--seed=N] [--dry-run] [--only=csv]`
- **ACF adapter interface** — pluggable ACF integration (NullAcfAdapter ships)
- **Offline test suite** — WordPress API stubs, deterministic Faker stub

---

## Pipeline

### High Priority

#### New Builders

- [ ] **AttachmentBuilder** — register media (images, PDFs), set as featured image on posts, placeholder image services (`wp_insert_attachment()`, `wp_generate_attachment_metadata()`)
- [ ] **MenuBuilder** — create nav menus and menu items with ordering, parent/child nesting, custom links, post/term/page targets (`wp_create_nav_menu()`, `wp_update_nav_menu_item()`)
- [ ] **CommentBuilder** — create comments on posts with author, content, status (approved/pending/spam), threaded replies (`wp_insert_comment()`)

#### Factory Ergonomics

- [ ] **States** — named state variants (e.g. `->state('draft')`, `->state('featured')`) that overlay default field values
- [ ] **Sequences** — cycling values across pattern iterations (rotating statuses, categories, templates)
- [ ] **Default definitions** — base field definitions per entity type so patterns don't repeat boilerplate
- [ ] **After-hooks** — `afterSave()` callbacks on patterns for setting featured images, assigning relationships, etc.

#### Orchestration

- [ ] **Muster chaining** — `$this->call(UserMuster::class, EventMuster::class)` to run multiple musters in sequence with dependency ordering

---

### Medium Priority

#### Relationships & Cross-References

- [ ] **Ref registry** — named store so patterns can retrieve refs created by earlier patterns (`$this->ref('homepage')`) instead of passing variables
- [ ] **Lazy ref resolution** — resolve refs at save-time rather than creation-time, enabling forward references
- [ ] **`has()` / `for()` relationships** — declarative relationship wiring: "this post *has* 3 comments", "this post is *for* this author"
- [ ] **`recycle()`** — reuse previously created refs across patterns (e.g. reuse the same set of users as authors)

#### ACF Adapter

- [ ] **LiveAcfAdapter** — real implementation calling `update_field()` / `update_sub_field()`
- [ ] **Repeater fields** — nested array to ACF repeater row format
- [ ] **Flexible content** — layout-based field groups
- [ ] **Gallery fields** — array of attachment IDs
- [ ] **Group fields** — nested key-value mapping

#### Data Generation (Victuals)

- [ ] **Placeholder images** — `$victuals->imageUrl(width, height)` for attachment builders
- [ ] **Gutenberg blocks** — `$victuals->gutenbergBlocks()` to generate block editor content
- [ ] **Rich HTML content** — `$victuals->richContent()` with headings, lists, links, blockquotes
- [ ] **ACF repeater data** — `$victuals->repeaterRows(count, schema)` for structured field content

#### Reset / Teardown

- [ ] **Truncate capability** — clean-slate reset before seeding (delete all posts of a type, truncate terms, etc.)

---

### Low Priority

#### Logging & Output

- [ ] **WpCliLogger** — pipes to `WP_CLI::log()` / `WP_CLI::debug()`
- [ ] **Progress reporting** — per-pattern progress output during long runs
- [ ] **Verbose/quiet modes** — `--verbose` flag for detailed per-field logging

#### Testing Utilities

- [ ] **Assertion helpers** — `assertPostExists('slug')`, `assertTermExists('taxonomy', 'slug')` for integration tests
- [ ] **Snapshot testing** — serialize muster output for regression comparison

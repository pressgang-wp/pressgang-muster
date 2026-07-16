# ADR 0005: Seedable location params and the framework-agnostic boundary

- Status: Accepted
- Date: 2026-07-16

## Context

Muster derives ACF values from a theme's `acf-json/` exports (see `ThemeAcf`),
matching a target string against each field group's location rules. Two places
independently decided which location params were reachable: `AcfJson::targets()`
and `ThemeAcf::groupTargets()`. They disagreed — `targets()` allowed
`options_page` while `groupTargets()` did not — and both omitted `post_template`,
`page_type`, and `nav_menu_item`. Groups located only on those params were
silently unseedable, so front pages, post-template fields, and nav-menu-item
fields never received values.

Widening the set raises a boundary question. Muster depends on nothing but
FakerPHP; it is a general WordPress toolkit, not a PressGang component (its only
WordPress touchpoint is `get_stylesheet_directory()/acf-json`). A theme-testing
tool such as Shakedown is the natural home for opinions about *which* surfaces a
theme should exercise. Muster must not acquire theme-framework assumptions by
absorbing that orchestration.

## Decision

The set of seedable location params is a single published constant,
`AcfJson::SEEDABLE_PARAMS`, and every consumer defers to it — `targets()` filters
on it and `ThemeAcf::groupTargets()` asks `targets()` rather than keeping a second
list. The two can no longer drift.

A param earns a place in that constant only if it is a **plain WordPress/ACF
location param** that maps to a concrete, framework-agnostic seed action:

- `post_type`, `page_template`, `post_template` — create a post/page carrying the
  fields.
- `options_page` — write the group's option values.
- `page_type` — seed the corresponding singular surface (e.g. the front page).
- `nav_menu_item` — attach values to a menu location's items.

Anything requiring knowledge of a specific theme framework — how a particular
theme composes menus, which page is "the" landing page, bespoke template
conventions — is **out of scope for Muster** and belongs in the consumer (the
theme's own Muster subclass, or Shakedown's orchestration).

## Consequences

- `acfFor('site-options')`, `acfFor('front_page')`, and
  `acfFor('location/primary')` now resolve values; previously they returned empty.
- Target strings remain matched by location *value*; callers address a group by
  the value its rule declares (`front_page`, `site-options`, `location/primary`).
- Adding a param to `SEEDABLE_PARAMS` is an API-visible decision gated by this
  record: it must be justifiable in pure WordPress/ACF terms. A param that would
  only make sense for one theme framework is grounds to reject the change and
  push the behaviour into the consumer instead.

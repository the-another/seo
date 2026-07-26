# The Another SEO — Template Token Chips — Design

**Plugin slug:** `the-another-seo`
**Date:** 2026-07-26
**Status:** Draft — pending review

## Overview

Template variables are typed and stored as `%%title%% %%sep%% %%sitename%%`. That is a good storage format and a poor reading format: the delimiters are noise, the slugs are machine names, and a template of any length becomes a wall of percent signs.

This replaces the visible editing surface of each template field with one that renders each `%%token%%` as an inline chip showing its human label, while the value submitted and stored stays exactly the text it is today.

**The constraint that determines the whole design:** styled chips cannot be rendered inside a plain `<input>`. An input's value is plain text and browsers offer no way to style substrings of it. Any solution therefore replaces the *visible* surface and keeps a real input carrying the canonical value.

## Decisions made during brainstorming

1. **`@wordpress/rich-text` with `wp-element`**, not a bespoke `contenteditable` widget and not a third-party library. Core already ships the model for editable text containing non-editable embedded objects; using it satisfies the project's standing rule that UI comes from established WordPress components.
2. **`FormTokenField` was rejected** despite being a core component: it models a flat *list* of tokens, so a template could no longer mix literal text between variables. That is a change to what a template can express, not to how it looks.
3. **The autocomplete ports to `@wordpress/components`.** The current jQuery UI autocomplete binds to a plain `<input>` and cannot attach to a rich-text surface. Same `%%` trigger, same per-row list.
4. **The chip shows the human label**, not the slug.

## Relationship to the Titles & Templates tab UI spec

[`2026-07-26-templates-tab-ui-design.md`](2026-07-26-templates-tab-ui-design.md) covers labels, section headings and human-readable type names on the same tab. **It must be implemented first**, for two reasons.

The first is a hard dependency. Today each template input explains itself only through a `placeholder`, and mounting a surface over the input loses that placeholder. The tab-UI spec replaces it with a real `<label for>` on every input — so without it, this feature leaves fields with no visible explanation at all.

The second is that the two specs make deliberately opposite demands on the same e2e files. The tab-UI spec requires `webmaster-admin.spec.ts` and `zz-admin-tour.spec.ts` to pass **unmodified**, because a change to labels and headings must not disturb the editing surface those specs drive. This spec requires both to be **rewritten**, because it replaces that surface. Both rules are correct for their own change; run in the wrong order they would appear to contradict each other, and the tab-UI spec would lose its own best evidence that it changed nothing it should not have.

## Architecture — the input stays, JavaScript upgrades it

The server renders each template `<input name="taseo_settings[title_templates][post:product]">` exactly as it does today. On mount the script sets the input's `hidden` attribute — hidden inputs still submit — and mounts a React root beside it.

The component reads the input's value, parses it, renders the editing surface, and writes the canonical `%%token%%` text back into that input on every change.

Three properties follow, and they are the reason for this shape rather than a self-contained React field:

- **The submitted field is unchanged.** `sanitize_settings()`, validation, storage, and every PHP test keep working against the same `name` and the same value format. No server-side change is required by this feature at all.
- **It degrades to today's behaviour.** With JavaScript disabled or the script failing to load, the plain input is still present, still visible, still editable. Nothing is lost but the chips.
- **There is one source of truth.** The input holds the value; the React surface is a view over it. No two-way sync between two editable widgets.

## Tokens as rich-text objects

`@wordpress/rich-text` represents a value as text plus a list of formats, and a format registered with `object: true` is a void, atomic, non-editable element. That is exactly what a token chip is — it cannot be edited character by character, and the caret treats it as one unit.

Parsing `%%title%% – %%sitename%% — Shop` yields text with three object segments and every literal character between them preserved, including the ` – ` and ` — Shop`.

Two functions own the conversion, and because every correctness risk in this feature lives in them, they are directly unit-tested rather than only exercised through the UI:

```
templateToValue( '%%title%% — Shop' )  →  rich-text value with one object segment
valueToTemplate( value )               →  '%%title%% — Shop'
```

`valueToTemplate( templateToValue( x ) ) === x` must hold for every template the plugin can store, including ones containing no tokens, adjacent tokens, an unpaired `%%`, and a token whose slug is not in the registry.

**Unknown tokens must survive a round trip.** A stored template can contain a variable that is no longer registered — a filter removed it, or a plugin was deactivated. Such a token renders as a chip marked unknown rather than being silently dropped, because dropping it would rewrite an admin's stored template just by opening the tab.

## The chip's appearance uses core classes, not CSS

The chip renders as `<span class="button button-small">` — core's own button styling. This gives the pill appearance with no stylesheet, and matches the clickable pills already rendered below each field.

The chip's text is the variable's **human label** — "Title", "Site title" — which finally uses the labels in `Meta/TemplateVariables`. A previous review flagged those labels as translated but never rendered; this is what they are for.

An unknown token shows its raw slug and is marked with core's `.form-invalid` class, so a variable that will not resolve is visibly different from one that will.

**Getting slug → label into JavaScript:** each pill gains `data-taseo-template-label` alongside its existing `data-taseo-template-var`. The pills remain the single serialisation channel for the variable list — there is still no `wp_localize_script` copy of the registry, for the same reason as before: a second serialisation can drift from what the pills show.

## Autocomplete

Ported to `@wordpress/components`' `Autocomplete` with a completer triggered on `%%`. Behaviour is unchanged: the option list is the row's variables, read from that row's pills, and selecting one inserts a chip.

`assets/js/settings.js`'s jQuery UI half is **deleted**, along with the `jquery-ui-autocomplete` dependency and the unit test asserting that dependency. The pill click-to-insert behaviour survives, now inserting a chip.

## Build and enqueue

A second `wp-scripts` entry joins the existing breadcrumbs one in `package.json`. The enqueue switches from hand-declared dependencies to reading the generated `.asset.php` for its dependency array and version — core's established pattern for built assets, and the only practical way to track what the bundle actually imports from `wp-element`, `wp-rich-text`, and `wp-components`.

`npm run lint:js` currently lints only `blocks`; its scope extends to the new source directory, since this is now non-trivial JavaScript.

## Error handling & edge cases

| Case | Behavior |
|---|---|
| JavaScript disabled or bundle fails to load | Plain input remains visible and editable; today's behaviour exactly |
| Stored template contains an unregistered variable | Renders as a chip marked `.form-invalid` showing its slug; round-trips unchanged |
| Template with no tokens | Renders as plain text in the surface |
| Adjacent tokens, no separator | Two chips, no text between; round-trips unchanged |
| Unpaired `%%` in stored text | Treated as literal text, matching `TemplateResolver` |
| Empty template | Empty surface; the input's `placeholder` is no longer visible, so the field's `<label>` carries the meaning — which is why the tab-UI spec is a prerequisite |
| Paste of raw `%%token%%` text | Parsed into chips on the next change |
| A filter removes a variable an existing template uses | The chip renders unknown; save-time validation rejects the row, as it already does |

## Testing

**JavaScript unit tests.** The repo has no JS test harness today; `@wordpress/scripts` provides `wp-scripts test-unit-js` (Jest). This feature is the first thing in the codebase that genuinely needs one — the serialisation functions are pure, total, and where the risk is. Coverage: the round-trip property above across tokenless, adjacent-token, unpaired-`%%`, unknown-token, and empty inputs.

**PHP unit tests.** Only one change: the pills now render `data-taseo-template-label`. Everything else server-side is untouched, and the existing `SettingsPageTest` coverage must stay green unmodified — that is the check that this really is a client-side change.

**E2E.** `tests/e2e/functional/specs/webmaster-admin.spec.ts` and `specs/zz-admin-tour.spec.ts` both call `fill()` on `input[name="…"]`. Once that input is `hidden`, `fill()` throws, so **both specs must be rewritten** to drive the rich surface. This is expected churn, not breakage to be worked around: the editing surface is what changed.

New e2e assertions: a stored template renders as chips showing human labels; typing `%%pri` offers the row's matching variables and selecting one inserts a chip; the value submitted after editing through the surface is the correct `%%token%%` text; and with the script blocked, the plain input is still editable and still saves.

That last one matters most — it is the assertion that proves the degradation path is real rather than assumed.

## Costs, stated plainly

- **It supersedes work merged hours ago.** The jQuery UI autocomplete and its test are deleted, not extended.
- **It rewrites two e2e specs**, one of which is the admin tour.
- **It introduces React and a JS test harness** to a plugin whose admin was, until recently, entirely server-rendered. That is a meaningful step up in the toolchain a future maintainer must know.

## Out of scope

- **Chips anywhere but the Titles & Templates tab.** No other field stores templates.
- **Editing a chip in place.** Chips are atomic: insert or delete, no partial edit.
- **Drag-to-reorder chips.**
- **Any change to storage, validation, or resolution.** The stored value format is unchanged, which is what makes this reversible.
- **Live client-side validation.** Unknown tokens are shown as such, but the save-time check remains the thing that decides.

# The Another SEO — Titles & Templates Tab UI — Design

**Plugin slug:** `the-another-seo`
**Date:** 2026-07-26
**Status:** Draft — pending review

## Overview

Four changes to the Titles & Templates tab, all addressing the same underlying problem: **the screen shows the data but not what any of it means.**

Today each row is headed by a machine slug (`post`, `post_tag`, `404`) and contains two unlabelled text inputs whose only explanation is a placeholder that disappears the moment either field has a value. Three groups of rows — post types, taxonomies, system pages — run together in one table with no headings between them. An administrator opening this tab cannot tell which input is the title and which is the description, why some rows have two inputs and others one, or what `post_tag` refers to.

Nothing here changes what is stored or how templates resolve. This is presentation only.

## Decisions made during brainstorming

1. **Human labels, with the slug retained in muted text.** Showing only "Products" would be cleaner, but the stored settings key, the `taseo_template_variables` filter's `$object_subtype`, and any code-level debugging all speak in slugs. Someone matching this screen to a filter, a database value, or a support thread needs both.
2. **Validation errors switch to the human label too**, resolved through the same helper as the heading, so the screen and its error messages cannot describe the same row differently.
3. **Everything uses established WordPress components.** No stylesheet, no custom CSS class.

## Component 1 — human-readable type names

`render_templates_tab()` iterates `Settings::get_enabled_post_types()` and `get_enabled_taxonomies()`, both of which return bare strings — which is why the tab prints slugs. Each row now resolves its display name:

| Row | Source of the label |
|---|---|
| `post:<type>` | `get_post_type_object( $type )->labels->name` |
| `term:<taxonomy>` | `get_taxonomy( $taxonomy )->labels->name` |
| `system_page:home` | `__( 'Home page', 'the-another-seo' )` |
| `system_page:search` | `__( 'Search results', 'the-another-seo' )` |
| `system_page:404` | `__( 'Not found (404)', 'the-another-seo' )` |

This is not a new pattern in this file: `render_types_tab()` already reads `$type->labels->name` and `$tax->labels->name` when rendering its checkboxes. The Templates tab simply never had the objects to hand.

The `<th>` carries the human label, with the slug beneath it:

```html
<th scope="row">
  Products
  <p class="description"><code>post:product</code></p>
</th>
```

**A deregistered type must not blank the row.** `get_post_type_object()` returns `null` for a post type whose plugin has been deactivated, and stored templates for it survive — the plan for the preceding feature notes exactly this case. When the object is missing, the row falls back to showing the slug as its own label, so an orphaned row stays identifiable and editable rather than rendering an empty heading.

A single private helper resolves the label for a given `$object_type`/`$object_subtype` pair, so the heading and the validation error cannot disagree.

## Component 2 — labelled inputs

Two inputs under one `<th>` is precisely what core's `<fieldset>` pattern exists for; `wp-admin/options-reading.php` and `options-discussion.php` both use it inside `form-table`:

```html
<td>
  <fieldset>
    <legend class="screen-reader-text"><span>Products</span></legend>
    <label for="taseo-title-post-product">Title template</label><br />
    <input type="text" id="taseo-title-post-product"
           name="taseo_settings[title_templates][post:product]"
           class="large-text" data-taseo-template-input /><br />
    <label for="taseo-desc-post-product">Meta description template</label><br />
    <input type="text" id="taseo-desc-post-product"
           name="taseo_settings[description_templates][post:product]"
           class="large-text" data-taseo-template-input />
  </fieldset>
  <p class="description"><!-- variable pills, unchanged --></p>
</td>
```

Every input gains a visible `<label for>` and a matching `id`; the screen-reader legend names the row so the labels are unambiguous out of context. The `<br />` separators are load-bearing rather than decorative: `<label>` and `<input>` are both inline, so without them the two pairs run together on one line. This is how core stacks label/input pairs inside a `form-table` fieldset — see the ping/pingback controls in `options-discussion.php`.

**One `<tr>` per type is preserved deliberately.** The admin tour and the e2e specs locate a row's pills with `tr:has(input[name="…"])`, and both target inputs by their `name` attribute. Splitting each type across two rows would break those selectors and force test churn for a purely cosmetic gain. The `name` attributes, the `data-taseo-template-input` markers, and the pills' position inside the `<td>` are all unchanged.

`id` values are built as `taseo-title-<type>-<subtype>` and `taseo-desc-<type>-<subtype>`. Subtypes are post type, taxonomy, and system-page keys — WordPress restricts these to lowercase alphanumerics, underscores and dashes, all valid in an HTML `id`.

## Component 3 — explaining the two inputs

An intro `<p class="description">` under the tab's first heading states what the fields do: the title template becomes the page's `<title>` element, and the description template becomes its `meta description`. It also names the fallbacks — leaving a field empty falls back to `%%title%% %%sep%% %%sitename%%` for titles and `%%excerpt%%` for descriptions, which is what `Settings::get_title_template()` and `get_description_template()` already return.

**System pages get a sentence of their own** explaining that they take a title template only. Their missing second input currently reads as a rendering bug; saying so converts it into an obvious deliberate choice.

## Component 4 — section separators

The three groups become three sections, each an `<h2>` followed by its own `<table class="form-table">`, separated by `<hr>`:

```
<h2>Post types</h2>       <table class="form-table"> … </table>
<hr />
<h2>Taxonomies</h2>       <table class="form-table"> … </table>
<hr />
<h2>System pages</h2>     <table class="form-table"> … </table>
```

Heading-plus-table-per-section is core's own structure — it is what `do_settings_sections()` emits — and the headings reuse the wording the Post Types tab already uses ("Post types", "Taxonomies") so the two tabs read consistently.

`<hr>` needs no styling from us: WordPress admin's `wp-admin/css/common.css` styles bare `hr` at line 880 (`border-top: 1px solid #dcdcde`), verified against the copy of core in the e2e image rather than assumed.

## Component 5 — validation errors use the human label

`sanitize_settings()` currently builds its error message with the raw row key:

> `post:product: %%discount%% is not available for this content type.`

It now uses the same helper as the heading:

> `Products: %%discount%% is not available for this content type.`

The settings-error **code** is untouched — it still embeds the settings key and row key, because `collect_invalid_rows()` matches on it to apply `.form-invalid`. Only the human-facing message changes.

## Error handling & edge cases

| Case | Behavior |
|---|---|
| Post type deregistered after templates were stored | Row falls back to the slug as its label; slug line still shown; row stays editable |
| Taxonomy deregistered | Same fallback |
| A type whose label happens to equal its slug | Renders normally; the slug line simply repeats it |
| Two types sharing a label | Distinguished by the slug line beneath each |
| No post types enabled | The Post types section renders its heading and an empty table, as today |
| Screen reader | Each input is named by its own `<label>`; the fieldset legend supplies the row context |

## Testing

**Unit** (`tests/Unit/Admin/SettingsPageTest.php`):
- A post-type row shows the registered human label, not the slug, and shows the slug beneath it.
- A taxonomy row does the same.
- System-page rows show their own translated labels rather than `home`/`search`/`404`.
- A post type with no registered object falls back to the slug as its label and does not render an empty heading.
- Each template input has an `id` matching its `<label for>`.
- The `name` attributes are unchanged, so stored data and existing selectors still resolve.
- A validation error names the human label rather than the row key, while the error **code** still carries the row key.

**E2E:** no new specs. The existing `webmaster-admin.spec.ts` and `zz-admin-tour.spec.ts` must pass **unmodified** — that is the check that proves the row structure and selectors survived. If either needs editing, the markup changed more than this design intends and that is a finding, not a fixup.

## Out of scope

- **The tab's other behaviour** — validation, the variable pills, the autocomplete, and what is stored all stay exactly as they are.
- **The other five tabs.** They have the same unlabelled-input pattern in places; changing them is a separate piece of work with its own risk to existing selectors.
- **A description template for system pages.** `MetaOutput` does consult `get_description_template( 'system_page', … )`, whose default `%%excerpt%%` cannot resolve there — a latent inconsistency noted in an earlier review. Adding the field, or removing that call, is its own decision and is not made here.
- **Reordering or regrouping rows.** Sections appear in the order they are rendered today.

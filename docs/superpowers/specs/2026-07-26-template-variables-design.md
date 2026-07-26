# The Another SEO — Template Variables Registry, Pills & Validation — Design

**Plugin slug:** `the-another-seo`
**Date:** 2026-07-26
**Status:** Draft — pending review

## Overview

Three changes to the Titles & Templates tab, all following from one root problem: **the plugin has two disagreeing sources of truth about which template variables exist.**

`Meta/CurrentContext` builds the actual variable values, and what it produces depends on context — `title`, `sitename`, `tagline`, `sep` and `page` everywhere; `excerpt` on posts and terms; `date` and `primary_category` on posts; `price` and `sku` only on WooCommerce products. Meanwhile `Admin/SettingsPage::render_templates_tab()` prints a single hardcoded line advertising all ten as if they were universal, and `Meta/TemplateResolver` silently deletes any token it cannot resolve.

So an admin can put `%%price%%` in a page title template today. The UI invites it, nothing objects, and it renders as nothing.

This spec introduces a registry as the single source of truth, surfaces it per row as clickable variable pills built from core components, and rejects templates containing variables that will not resolve.

## Decisions made during brainstorming

1. **The registry is context-aware**, not one flat list. Each row offers and accepts only the variables that actually resolve for its content type. A flat list would have blessed the existing mismatch rather than fixing it.
2. **A failed variable rejects that field only** — the field keeps its stored value and every other field on the tab saves normally. Rejecting the whole tab would discard unrelated edits over one typo; silently stripping the bad token would rewrite what the admin typed.
3. **UI uses established WordPress components exclusively.** No bespoke CSS, no invented widgets. Pills are core buttons, errors travel through the Settings API's own error mechanism, invalid fields use core's `.form-invalid`.
4. **The variables list is filterable**, with the object type and subtype passed, so an extension can add a variable to products only rather than to everything.

## Component 1 — `Meta/TemplateVariables`

A new class, the single source of truth for which variables exist where.

```php
public function get_for( string $object_type, string $object_subtype ): array<string, string>
```

Returns variable slug ⇒ human-readable label. `$object_type` is `post`, `term`, or `system_page`, matching the keys the templates tab already uses (`post:product`, `term:category`, `system_page:home`).

| Context | Variables |
|---|---|
| All | `title`, `sitename`, `tagline`, `sep`, `page` |
| `post:*` | + `excerpt`, `date`, `primary_category` |
| `post:product` (WooCommerce active) | + `price`, `sku` |
| `term:*` | + `excerpt` |
| `system_page:*` | base only |

This table is not a new decision — it is a transcription of what `CurrentContext::site_vars()`, `post_vars()` and `term_vars()` already produce. The registry's job is to state it in one place that both the admin UI and the validator can read.

`price` and `sku` appear only when WooCommerce is active, detected the same way `CurrentContext::post_vars()` already does it (`function_exists( 'wc_get_product' )`). A site without WooCommerce should not be offered variables that cannot resolve.

**The filter, applied last so extensions can both add and remove:**

```php
/**
 * @param array<string, string> $variables      Slug => label.
 * @param string                $object_type    'post' | 'term' | 'system_page'.
 * @param string                $object_subtype Post type, taxonomy, or system page key.
 */
apply_filters( 'taseo_template_variables', $variables, $object_type, $object_subtype );
```

Passing type and subtype is what makes this useful: a plugin adding `%%brand%%` can scope it to `post:product` instead of advertising it on 404 pages. A filter returning a non-array, or entries whose slug does not match `[a-z0-9_]+`, is ignored — the same character class `TemplateResolver` matches, so the registry cannot advertise a token the resolver could never expand.

## Component 2 — the drift guard

The registry and `CurrentContext` are two places describing one thing, which is how the current mismatch arose. A unit test pins them together: for every context, each key `CurrentContext` can produce must exist in the registry, and each registry key must be producible.

The conditional variables need care — `primary_category` requires the object to have terms, and `price`/`sku` require WooCommerce and a product. The test exercises those branches with fixtures that satisfy the conditions, so "producible" means genuinely reachable rather than merely mentioned.

This is the test that would have caught today's bug, and it is the reason the registry is worth introducing rather than simply fixing the help string.

## Component 3 — rendering the pills

Each row in `render_templates_tab()` gains its available variables below its inputs:

```html
<p class="description">
  <button type="button" class="button button-small" data-taseo-template-var="%%title%%">%%title%%</button>
  …
</p>
```

`button.button-small` is core's own component; `<p class="description">` is core's help-text convention. Nothing here introduces a stylesheet.

The single hardcoded "Available variables:" line is **deleted** — it is the second source of truth this spec exists to remove.

Clicking a pill inserts its token into the row's **most recently focused input**, at the cursor position. Post and term rows carry two inputs — title and description — so the target must be tracked rather than assumed; the row remembers the last of its own inputs to receive focus, and falls back to the title input when none has been focused yet. System-page rows have only a title input, so the question does not arise there.

Insertion respects the existing selection: it replaces the selected text when there is a selection, and the cursor lands immediately after the inserted token so a second click appends rather than overwrites.

**This is the plugin's first admin asset.** It currently registers no `admin_enqueue_scripts` callback at all. The script is vanilla JavaScript with no dependencies, enqueued only on this settings page (gated on the hook suffix returned by `add_options_page()`), versioned on `THE_ANOTHER_SEO_VERSION`, loaded in the footer. It attaches one delegated listener rather than one per button.

With JavaScript disabled the buttons still render and still read as an accurate list of the variables available for that row, so the informational value survives; only the click-to-insert convenience is lost. That is the reason for choosing buttons over a bespoke element.

## Component 4 — validation on save

`SettingsPage::sanitize_settings()` gains a validation pass over `title_templates` and `description_templates`.

For each submitted row, tokens are extracted with the same pattern `TemplateResolver::resolve()` uses — `/%%([a-z0-9_]+)%%/i` — and each is checked against `TemplateVariables::get_for()` for that row's context, parsed from the row key (`post:product` → type `post`, subtype `product`).

**On failure, only the offending row is rejected.** Because `title_templates` is a single settings key holding every row, the sanitizer merges valid rows over the currently stored array rather than replacing it wholesale. A bad `post:product` title leaves `post:page` and every other row saved normally, and leaves the previous `post:product` value intact.

Errors use the Settings API's own mechanism, which is also how core's `options.php` carries validation failures across a redirect:

- `add_settings_error()` records each failure, with a message naming the row and the offending variable.
- The errors are persisted into the `settings_errors` transient for the redirect.
- The settings page renders them, and marks each failed input with core's `.form-invalid` class.

The page reads the recorded errors once and uses that single read for both the notices and the field marking, since retrieving them clears the transient.

A template with no variables at all is valid — plain static text is a legitimate title.

## Error handling & edge cases

| Case | Behavior |
|---|---|
| Unknown variable (`%%discount%%`) | That row rejected, keeps stored value, `.form-invalid`, error notice naming row and variable |
| Known variable in the wrong context (`%%price%%` on a page) | Same as unknown — it is not available for that row, which is the entire point |
| Several bad rows in one save | One error notice per failing row; all valid rows still save |
| Template with no variables | Valid |
| Malformed token (`%%not closed`) | Not matched by the pattern, so treated as literal text — consistent with how `TemplateResolver` already behaves |
| Filter returns a non-array or bad slugs | Ignored; the built-in set stands |
| WooCommerce inactive | `price`/`sku` absent from pills and rejected if typed |
| Post type disabled after templates were saved | Its row disappears from the tab; the stored value is untouched and simply unused |
| JavaScript disabled | Pills render as an accurate variable list; click-to-insert unavailable |

## Testing

**Unit**

- `TemplateVariablesTest` — base set for every context; posts add `excerpt`/`date`/`primary_category`; products add `price`/`sku` only with WooCommerce active and not without it; terms add `excerpt`; system pages get the base set; the filter can add and remove; a non-array filter return and invalid slugs are ignored.
- **The drift test** — every key `CurrentContext` can produce exists in the registry, and every registry key is producible, with the conditional branches exercised.
- `SettingsPageTest` additions — a valid template saves; an unknown variable rejects only its own row and leaves siblings saved; the stored value survives a rejection; an error is recorded naming the row and variable; a context-wrong variable (`%%price%%` on `post:page`) is rejected; a variable-free template is accepted; pills render per row with only that row's variables; a system-page row's pills carry only the base set; a failed field carries `.form-invalid`.

**E2E** — extend the existing admin coverage: type `%%discount%%` into the product title template alongside a valid edit in another row, save, and assert the error notice appears, the product field kept its previous value and is marked invalid, and the sibling row saved. Then click a pill and assert the token lands in the input.

The admin tour writes `%%title%% %%sep%% Tour` to the `post:post` row; both variables are available there, so validation leaves the tour green.

## Out of scope

- **Live client-side validation as you type.** Validation happens on save. Adding an inline "unknown variable" warning while typing means duplicating the registry into JavaScript, and a second source of truth is what this spec exists to eliminate.
- **Autocomplete or a token-picker dropdown.** The pills are the affordance.
- **Migrating existing invalid templates.** Stored templates are not retro-validated on upgrade; a stored bad variable continues to resolve to nothing until someone edits and saves that row, at which point it is rejected.
- **Applying the registry to the per-post metabox overrides.** Those are literal strings, not templates, and expand no variables today.
- **Variables beyond the current set.** Adding new ones is what the filter is for.

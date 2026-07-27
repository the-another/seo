# The Another SEO — Custom Pages and Section Navigation — Design

**Plugin slug:** `the-another-seo`
**Date:** 2026-07-27
**Status:** Draft — pending review

## Overview

Two changes to the Titles & Templates tab.

**Custom pages.** Another plugin can register a page of its own — a checkout screen, an account area, a virtual landing page — and get a title and meta description template row for it, plus front-end resolution so those templates actually render.

**Section navigation.** The tab renders three sections today and will render four. A short anchor nav at the top jumps to each.

## Decisions made during brainstorming

1. **Registration *and* resolution, not registration alone.** `CurrentContext::resolve()` currently returns `null` for anything outside `is_home()` / `is_search()` / `is_404()` / `is_post_type_archive()`, and `MetaOutput` bails on `null`. Without an extension point there, a registered row would save and redisplay correctly and never render — a setting that does nothing. Both halves ship together or the feature is decorative.
2. **A new `custom_page` object type**, not a reuse of `system_page`. System pages are a fixed built-in list of three; this one is open-ended. Sharing the namespace would make `system_page:checkout` indistinguishable from a built-in.
3. **Both fields.** Custom pages take a title *and* a meta description template, like Post types and Taxonomies. System pages are title-only for a real reason — their `%%excerpt%%` default cannot resolve there — but a custom page supplies its own variables, so a description is meaningful.
4. **A flat `key => label` filter.** Registering a page is one line. A plugin that wants no description simply leaves the field empty, which is cheaper than a per-page opt-in flag.
5. **Core's `.subsubsub` for the nav.** It is WordPress's own secondary-navigation pattern, styled at `wp-admin/css/common.css:428` — verified against the copy of core in the e2e image rather than assumed. No stylesheet of ours.

## The registry

One small class owns the list, mirroring `Meta\TemplateVariables`:

```php
namespace TheAnother\Plugin\SEO\Meta;

final class CustomPages {
    /** @return array<string, string> Sanitized key => label. */
    public function all(): array;

    public function has( string $key ): bool;
}
```

`all()` applies the filter and sanitizes:

```php
apply_filters( 'taseo_custom_pages', array() );
```

Both the settings screen and `CurrentContext` read the registry through this class rather than each calling `apply_filters` directly. Two call sites would be two places to drift, and key sanitization has to happen identically on both sides or a page registers under one key and resolves under another.

`CustomPages` is registered in the container and injected into both consumers. `CurrentContext::__construct()` currently takes `IndexableRepository` and `Settings`; it gains a third parameter, so its factory in `Plugin::register_services()` (`Plugin.php:123`) changes with it. `SettingsPage`'s constructor takes six dependencies today and gains a seventh — worth noting because every existing `new SettingsPage( … )` in the unit tests must be updated in step, or they fatal on an argument-count error rather than failing with a useful message.

**Keys are constrained to `[a-z0-9_-]`.** A key becomes both a settings array key (`custom_page:checkout`) and part of an HTML `id`. A key containing anything else is **skipped, not rewritten** — silently rewriting it would leave the plugin's own resolution filter referring to a key that no longer exists, which is worse than the page not appearing.

**A non-array filter return is treated as empty.** An empty label falls back to the key, matching how a deregistered post type falls back to its slug.

## The Custom pages section

A fourth section, below System pages, with the same row structure as Post types and Taxonomies — a `<fieldset>` with a screen-reader legend, a labelled title input, a labelled meta description input, and the variable pills:

```
Custom pages
  Checkout                  Title template            [ … ]
  custom_page:checkout      Meta description template [ … ]
                            Available variables — these apply to both fields above.
                            [ Title ] [ Site title ] [ Tagline ] [ Separator ] [ Page number ]
```

`template_row_label()` gains a `custom_page` branch that reads the registry. It must sit **before** the existing system-page lookup, whose `?? $object_subtype` fallthrough would otherwise return the raw key for every custom page.

`TemplateVariables::get_for( 'custom_page', … )` needs no change. It matches neither the `post` nor the `term` branch, so it returns the base set — Title, Site title, Tagline, Separator, Page number — which is right for a page whose variables the registering plugin supplies. That plugin extends its own page's list through the existing `taseo_template_variables` filter, which already receives `$object_type` and `$object_subtype`.

## Front-end resolution

`CurrentContext::resolve()` gains one extension point, at the final fallthrough where it currently returns `null`:

```php
apply_filters( 'taseo_custom_page_context', null );
```

Three deliberate constraints, each of which rules out an easier and worse alternative:

**It fires only when nothing else matched.** Applying it to every resolve would let one careless filter break title output for every post and term on the site. "Claim an otherwise-unhandled request" is what a custom page needs and all it needs.

**It returns a declaration, not a context.** The filter returns `null` or:

```php
array(
    'subtype'   => 'checkout',
    'vars'      => array( 'title' => 'Checkout' ),
    'permalink' => '',
)
```

`CurrentContext` merges `vars` over its own `site_vars()` — the plugin's values win on a key collision — and calls its private `build( 'custom_page', $subtype, 0, $vars, $permalink )`. Letting plugins assemble the internal context array directly would freeze its shape — it currently carries `row`, `title_template`, `description_template` and more, all of which are ours to change.

`subtype` is required. `vars` and `permalink` are optional, defaulting to `array()` and `''`; a page with no permalink is the normal case for a virtual screen, and omitting `vars` simply leaves the site-level values in place.

**Only registered subtypes resolve.** A `subtype` absent from `CustomPages::all()` is ignored. This keeps the two filters a matched pair: a typo in one produces no output change rather than a page silently rendering the default template.

A malformed return — not an array, no `subtype`, `vars` not an array — is ignored, and resolution falls through to `null` exactly as today.

`build()` calls `$this->repository->find( 'custom_page', $subtype, 0 )`, which returns `null` when no indexable row exists. That is already the normal path for a context with no per-object overrides, so custom pages need no table changes.

## Empty state

With nothing registered, the section still renders its heading, followed by a `<p class="description">` and a `<pre><code>` block showing **both** filters — because registering a row without claiming a request produces a template that never renders, and that is precisely the mistake the empty state exists to prevent:

```php
add_filter( 'taseo_custom_pages', function ( $pages ) {
    $pages['checkout'] = __( 'Checkout', 'my-plugin' );
    return $pages;
} );

add_filter( 'taseo_custom_page_context', function ( $context ) {
    if ( function_exists( 'is_checkout' ) && is_checkout() ) {
        return array(
            'subtype' => 'checkout',
            'vars'    => array( 'title' => __( 'Checkout', 'my-plugin' ) ),
        );
    }
    return $context;
} );
```

`<pre>` and `<code>` need no styling from us; wp-admin styles both.

## Section navigation

Above the first heading:

```html
<ul class="subsubsub">
  <li><a href="#taseo-post-types">Post types</a> |</li>
  <li><a href="#taseo-taxonomies">Taxonomies</a> |</li>
  <li><a href="#taseo-system-pages">System pages</a> |</li>
  <li><a href="#taseo-custom-pages">Custom pages</a></li>
</ul>
<div class="clear"></div>
```

Each `<h2>` gains the matching `id`.

**The `<div class="clear">` is load-bearing.** `.subsubsub` is `float: left` (`common.css:428`); without a clear, the first `<h2>` wraps alongside the nav instead of below it. `.clear` is core's own utility (`common.css:109`).

The nav carries no `current` state. Core's list tables use `.current` for the active filter view; these are in-page anchors with no active one, and adding scroll tracking would mean JavaScript and a behaviour core does not have here.

The section headings keep their existing wording, so the nav labels and the headings match exactly.

## Error handling & edge cases

| Case | Behavior |
|---|---|
| No pages registered | Section renders its heading and the empty-state explanation with both filter examples |
| `taseo_custom_pages` returns a non-array | Treated as empty; section shows the empty state |
| Key contains characters outside `[a-z0-9_-]` | That entry is skipped, not rewritten — a rewritten key would break the plugin's own resolution filter |
| Registered label is empty | Falls back to the key, as a deregistered post type falls back to its slug |
| `taseo_custom_page_context` returns an unregistered subtype | Ignored; resolution falls through to `null` |
| Filter returns a malformed array | Ignored; resolution falls through to `null` |
| A plugin registering pages is deactivated | Its rows disappear from the screen; stored templates stay in the option and reappear if it is reactivated |
| Two plugins register the same key | Last filter to run wins, as with any WordPress filter |
| Anchor for a section with no rows | The heading still renders, so the anchor still resolves |

## Testing

**Unit** (`tests/Unit/Meta/CustomPagesTest.php`):
- `all()` returns the filtered map; a key with invalid characters is skipped; a non-array return yields an empty array; an empty label falls back to the key.
- `has()` is true only for a registered key.

**Unit** (`tests/Unit/Meta/CurrentContextTest.php`):
- With nothing else matching, a well-formed `taseo_custom_page_context` return produces a context whose `object_type` is `custom_page` and whose `vars` merge over `site_vars()`.
- An unregistered subtype resolves to `null`.
- A malformed return resolves to `null`.
- **The filter does not fire when an earlier branch already matched** — the check that it cannot hijack post or term output.

**Unit** (`tests/Unit/Admin/SettingsPageTest.php`):
- A registered page renders a row with `name="taseo_settings[title_templates][custom_page:checkout]"` and the matching description input.
- The row heading shows the registered label, with `custom_page:checkout` beneath it.
- With nothing registered, the section renders the empty state and both filter names appear in it.
- The subnav renders four links whose `href`s match the four `<h2>` `id`s — asserted as a pair, so a renamed heading id without a matching nav change fails.
- `template_row_label( 'custom_page', … )` returns the registered label, not the raw key.

**E2E:** a spec registering a custom page through a `mu-plugin` in the test environment, asserting its row appears under Custom pages, that a template typed there saves and survives a reload, and that clicking a subnav link moves the viewport to that section.

## Out of scope

- **Per-object overrides for custom pages.** The metabox covers posts and terms; a custom page has no edit screen to hang one on.
- **Registering custom pages through the admin UI.** This is an extension point for plugins, not a page builder.
- **Scroll-spy or `current` state on the subnav.**
- **Applying the resolution filter to matched contexts.** Overriding an already-resolved post or term context is a different feature with a much wider blast radius.
- **The other tabs' navigation.** Only Titles & Templates has enough sections to need it.

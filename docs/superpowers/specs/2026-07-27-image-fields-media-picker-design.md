# The Another SEO — Image Fields: Media Picker and Override Filters — Design

**Plugin slug:** `the-another-seo`
**Date:** 2026-07-27
**Status:** Draft — pending review

## Overview

Four fields in this plugin identify an image, and all four ask the administrator to type a number:

| Slot | Stored as | Rendered as | Where |
|---|---|---|---|
| Site default social image | `default_social_image_id` | `<input type="number">` + "(attachment ID)" | Social Networks tab |
| Organization logo | `site_logo_id` | `<input type="number">` + "(attachment ID)" | Schema & Breadcrumbs tab |
| Per-object OG image | `og_image_id` | plain text input, labelled "Og Image Id" | Post metabox and term edit |
| Per-object Twitter image | `twitter_image_id` | plain text input, labelled "Twitter Image Id" | Post metabox and term edit |

An attachment ID is not a thing an administrator has. Getting one means opening the media library in another tab, selecting the image, and reading a number out of the URL. The per-object pair is worse than the sitewide pair: they fall through `render_fields()`'s `else` branch, so they render as bare text boxes labelled from their column name by `ucwords( str_replace( '_', ' ', $field ) )` — "Og Image Id" — with nothing saying a number is expected at all.

This replaces all four with core's media modal, adds a URL override beside each, and makes every resolved image URL filterable.

## Decisions made during brainstorming

1. **Keep the attachment ID and add a URL override**, rather than replacing the ID with a URL. The ID is what lets WordPress resolve sizes later; the URL override is what makes an off-site or CDN image possible without a developer.
2. **One filter per image slot, applied to the final URL** — matching the plugin's existing per-concern filter naming (`taseo_verification_tags`, `taseo_meta_pixel_ids`, `taseo_template_variables`) rather than one shared hook with a `$slot` discriminator.
3. **Core's `wp.media` modal**, via `wp_enqueue_media()`. The modal's own Upload tab is how a new file gets uploaded, so "upload" and "choose" are one control rather than two. A bespoke `<input type="file">` on a settings screen would mean writing upload, validation, and attachment handling that core already ships — a custom solution, which this project does not do.
4. **Both controls are always visible.** Hiding the URL override behind a disclosure would mean building a disclosure widget.

## Storage

Each slot gains a URL sibling:

| Slot | ID (existing) | URL override (new) |
|---|---|---|
| Site default social image | `default_social_image_id` | `default_social_image_url` |
| Organization logo | `site_logo_id` | `site_logo_url` |
| Per-object OG image | `og_image_id` | `og_image_url` |
| Per-object Twitter image | `twitter_image_id` | `twitter_image_url` |

The two sitewide ones are keys in the existing `taseo_settings` option and need no schema work.

The two per-object ones become columns on `wp_taseo_indexables`:

```sql
og_image_url TEXT NULL,
twitter_image_url TEXT NULL,
```

`TEXT NULL` rather than a sized `VARCHAR`, because that is what every other URL column in this table already is — `permalink TEXT NULL`, `canonical_url TEXT NULL`. Matching the existing convention also keeps `dbDelta()`, which is notoriously exacting about type spelling, on the path it already handles here.

`IndexablesTable::DB_VERSION` goes `1.1.0` → `1.2.0`; `maybe_upgrade()` already runs `dbDelta()` whenever the stored version is behind, so the columns are added on the next admin request after an upgrade.

**No backfill is needed, and none must be added.** `Plugin::maybe_flag_upgrade_backfill()` sets `NEEDS_BACKFILL_OPTION` for the `1.1.0` upgrade because that release added columns whose values had to be *derived* for existing rows. These columns have no derived value: absent means "not set", which is already what `NULL` means here. That method's `version_compare( $installed, '1.1.0', '<' )` condition stays exactly as it is — widening it to `1.2.0` would trigger a full-catalog resync for nothing.

`IndexableRepository::OVERRIDE_COLUMNS` must gain both names, or the repository will silently drop them on write.

## The control

Each of the four fields renders the same structure:

```html
<div class="taseo-image-field" data-taseo-image-field>
  <input type="hidden" name="…[og_image_id]" value="42" data-taseo-image-id />
  <img src="…" alt="" data-taseo-image-preview />          <!-- omitted when unset -->
  <button type="button" class="button" data-taseo-image-select>Select image</button>
  <button type="button" class="button" data-taseo-image-remove>Remove</button>
  <label for="taseo-og-image-url">Image URL</label>
  <input type="url" id="taseo-og-image-url" name="…[og_image_url]" value="" class="large-text" />
</div>
```

Core classes only — `button`, `large-text`, `p.description` — consistent with the rest of the admin. The hidden input is what carries the ID, so the form still submits the same field name with the same meaning and every existing sanitizer keeps working unchanged.

Clicking **Select image** opens `wp.media( { title, button: { text }, multiple: false, library: { type: 'image' } } )`. On select, the ID goes into the hidden input and the thumbnail into the preview. **Remove** clears both.

**Degradation matters here for the same reason it did for the template chips.** With JavaScript off or the bundle failing to load, the hidden input still holds and submits the stored ID, and the URL override field is a plain text input that works normally. The administrator loses the picker, not the field — nothing is silently discarded on save.

### Where the script lives

A new `assets/src/media-picker/` entry in the existing `wp-scripts` build. No React — `wp.media` is the whole dependency — but keeping it inside the build means one pipeline, and `npm run lint:js` already covers `assets/src`.

It is deliberately **not** folded into the `taseo-settings` bundle, which imports `@wordpress/rich-text` and `@wordpress/components` for the template chips. A post edit screen has no reason to load those.

It enqueues on three screens, because `render_term_fields()` calls the same `render_fields()` as the post metabox:

- the plugin's settings page (already has an `admin_enqueue_scripts` hook in `SettingsPage`)
- the post edit screen
- term edit screens

`Metabox` has no `admin_enqueue_scripts` registration today and gains one. Both enqueues call `wp_enqueue_media()` — without it `wp.media` is undefined — and read the generated `.asset.php` for dependencies and version, the pattern the settings bundle already uses.

## Resolution and the filters

Within a level the explicit URL wins; a more specific level beats a less specific one.

**OG image** — `SocialOutput::resolve_image_url()`:

1. `row.og_image_url`
2. `row.og_image_id`
3. `settings.default_social_image_url`
4. `settings.default_social_image_id`
5. `''`

then `apply_filters( 'taseo_og_image_url', $url, $ctx )`.

**Twitter image** — `SocialOutput::print_twitter()`:

1. `row.twitter_image_url`
2. `row.twitter_image_id`
3. the already-resolved OG URL

then `apply_filters( 'taseo_twitter_image_url', $url, $ctx )`.

**Logo** — `SchemaGraph`:

1. `settings.site_logo_url`
2. `settings.site_logo_id`
3. `''`

then `apply_filters( 'taseo_logo_url', $url )`.

Each filter is applied **last**, after the chain resolves, so `add_filter` is always the final word and can point a slot at any URL — including one this plugin could never have produced.

**The Twitter fallback inherits the filtered OG URL.** Twitter already falls back to the OG image (`print_twitter()` receives `$image_url` from `resolve_image_url()`), and that value has been through `taseo_og_image_url` by then. So a plugin that rewrites the OG image moves the Twitter image with it unless Twitter is set separately. That is the intended behaviour — a site owner overriding "the social image" means both — but it is a real ordering rule and gets its own test rather than being left to inference.

**An empty filter return removes the tag.** `print_twitter()` and `resolve_image_url()` already treat `''` as "no image" and skip the meta tag; `SchemaGraph` already omits `logo` when there is no URL. Returning `''` from a filter is therefore a supported way to suppress an image, not an error case.

## Sanitization

The URL overrides are stored with `esc_url_raw()`, matching what `Metabox::sanitize()` already does for its `'url'` field type. The sitewide pair joins `sanitize_settings()` alongside the existing `absint()` loop over `default_social_image_id` / `site_logo_id`.

`Metabox::FIELDS` gains `'og_image_url' => 'url'` and `'twitter_image_url' => 'url'`, which routes them through the existing `'url' => esc_url_raw( (string) $value )` match arm — no new sanitizer.

`Metabox::render_fields()` currently branches checkbox / textarea / else-text. It gains an `image_id` branch so those fields stop rendering as bare text boxes. The `url` type continues to use the text branch; the picker markup pairs the two by placing the URL input inside the same wrapper.

**A URL override is not validated against being an image.** `esc_url_raw()` makes it a safe URL and nothing more. Pointing it at a PDF produces a bad `og:image`, and that is the administrator's call — the same latitude `same_as_urls` already gets.

## Error handling & edge cases

| Case | Behavior |
|---|---|
| JavaScript disabled or bundle fails to load | Hidden input still submits the stored ID; URL field still works; picker absent |
| Attachment deleted after being selected | `wp_get_attachment_image_url()` returns `false`; treated as unset and the chain falls through to the next level |
| URL override set and attachment also set | URL wins at that level; the ID is retained, not cleared, so clearing the URL restores it |
| URL override set on a row, ID set sitewide | Row URL wins — more specific level beats less specific |
| Filter returns `''` | The image is suppressed: no `og:image` / `twitter:image` tag, no `logo` in the graph |
| Filter returns a non-string | Coerced with `(string)`; a filter returning `null` yields `''`, suppressing the image |
| Preview for an attachment with no thumbnail size | `wp_get_attachment_image_url( $id, 'thumbnail' )` falls back to full; if that fails too, no preview renders and the field still works |
| Upgrade from 1.1.0 | `dbDelta` adds two NULL columns; every existing row keeps its ID-based behaviour unchanged |

## Testing

**Unit**

- `SocialOutput`: each step of the OG chain wins over the next, including URL-beats-ID at both the row and settings level.
- `SocialOutput`: Twitter falls back to the OG URL, and that fallback is the *filtered* OG URL.
- `SocialOutput`: `taseo_og_image_url` and `taseo_twitter_image_url` are applied last and receive the context; returning `''` emits no tag.
- `SchemaGraph`: `site_logo_url` beats `site_logo_id`; `taseo_logo_url` is applied last; `''` omits the `logo` key entirely.
- A deleted attachment (`wp_get_attachment_image_url()` returning `false`) falls through rather than emitting an empty `content=""`.
- `SettingsPage::sanitize_settings()` stores both URL keys through `esc_url_raw()` and leaves the existing `absint()` behaviour intact.
- `Metabox::sanitize()` routes both new fields through the `url` arm.
- `IndexableRepository::OVERRIDE_COLUMNS` contains both new columns — the guard against the repository silently dropping them.
- `IndexablesTable::get_schema()` declares both columns and `DB_VERSION` is `1.2.0`.
- `Plugin::maybe_flag_upgrade_backfill()` does **not** flag a backfill for a `1.1.0` → `1.2.0` upgrade.

**E2E**

- The settings page renders a Select image button, not a number input, for both sitewide slots.
- Selecting an image through the media modal stores its ID and shows a preview.
- Remove clears the ID and the preview, and the cleared state survives a save.
- A URL override typed into the settings field is emitted as `og:image`.
- With the picker script blocked, the field still submits its stored ID unchanged — the same degradation assertion the template-chip work uses, and for the same reason.

## Out of scope

- **Per-object logo.** The logo is an Organization-level property; there is no per-post equivalent.
- **Image size selection.** Everything resolves at `full`, as it does today.
- **Validating that a URL override points at an image.**
- **Replacing the ID with the URL.** The ID stays primary; this only adds an override.
- **The `same_as_urls` textarea and other non-image fields** on the same tabs.
- **A media picker anywhere else.** These four fields are the only attachment references in the plugin.

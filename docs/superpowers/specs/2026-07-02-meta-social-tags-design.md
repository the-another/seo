# The Another SEO — Meta Tags, Social Sharing & Structured Data — Design

**Plugin slug:** `the-another-seo`
**Date:** 2026-07-02
**Status:** Draft — pending review

## Overview

A standalone WordPress plugin that lets admins control the `<title>`, meta description, canonical URL, robots directives, social sharing tags (Open Graph, Twitter Card), Schema.org/JSON-LD structured data, and breadcrumbs for any post, page, product, other registered public post type, or taxonomy term — without hand-editing every single one. Because the target catalog can run to millions of products, per-object values are generated from admin-defined templates (`%%title%% %%sep%% %%sitename%%`) rather than requiring a manual entry per object, with an optional per-object override for the cases that warrant hand-tuning.

This is **sub-project 1 of 2**. A separate design will cover the XML sitemap generator for large WooCommerce catalogs; it will build on the indexable table introduced here (shared `is_indexable`, `last_modified`, and `permalink` columns).

## Assumptions made during design (please confirm or correct)

Some of these were decided by best judgment when you were unavailable to confirm live. Flag any that are wrong during spec review:

1. **Generic, reusable plugin** — no hard dependency on Aucteeno, Dokan, or Nexus, matching the pattern of `the-another-blocks-for-dokan` and `the-another-multi-domain-global-styles`. WooCommerce is an optional soft dependency: product-specific behavior (price/availability in Open Graph and Product schema, product template variables) is only active when WooCommerce is present.
2. **Fresh start, no migration** — no import from Yoast/RankMath/AIOSEO in v1. If another SEO plugin appears to be outputting its own title/meta/schema output, an admin notice recommends disabling that plugin's output to avoid duplicates.
3. **v1 feature boundary**: title, meta description, canonical URL, robots (`noindex`/`nofollow`/`noarchive`), Open Graph tags, Twitter Card tags, Schema.org/JSON-LD structured data (`@graph`: WebSite, Organization/Person, WebPage, BreadcrumbList, Article, Product), and breadcrumbs (visual trail + shared schema data).
4. **"Instagram" support is covered by Open Graph, not built separately** — Instagram does not crawl arbitrary URLs for link previews the way Facebook/Twitter do, and there is no `instagram:*` meta tag standard. Whatever preview behavior exists when sharing a link into Instagram (e.g., via a Story sticker from another app) relies on the same Open Graph tags Facebook consumes. Flag if you had a specific different behavior in mind (e.g., a dedicated square image crop).
5. **Breadcrumbs ship as a template tag, a shortcode, and a Gutenberg block** — per your choice, this pulls `@wordpress/scripts` build tooling into the plugin (matching `the-another-blocks-for-dokan`'s `blocks/` structure), rather than staying pure-PHP/zero-build.
6. **Schema type per post type/taxonomy is admin-configurable**, defaulting to a sensible mapping (post → `Article`, page → `WebPage`, product → `Product` when WooCommerce is active, other custom post types → `WebPage`) — consistent with how title templates are already configurable per type rather than hardcoded.

## Data model — indexable table (High-Performance Storage pattern)

Reuses the same architecture as Aucteeno's existing HPS system (`wp_aucteeno_auctions`/`wp_aucteeno_items`): a denormalized custom table synced from `wp_posts`/`wp_terms` via hooks, so reads never need EAV-style meta joins and stay fast regardless of catalog size.

**Table:** `{$wpdb->prefix}taseo_indexables` — one row per indexable object: a post, a term, or a "system page" (homepage, search results, 404, a post type archive).

| Column | Purpose |
| --- | --- |
| `id` BIGINT UNSIGNED PK | surrogate key |
| `object_type` VARCHAR(20) | `post`, `term`, or `system_page` |
| `object_subtype` VARCHAR(32) | post type slug / taxonomy slug / system page key (`home`, `search`, `404`, `archive:{post_type}`) |
| `object_id` BIGINT UNSIGNED | post ID or term ID; `0` for system pages (sentinel, not NULL — keeps the unique key well-defined) |
| `permalink` TEXT | cached permalink; avoids calling `get_permalink()`/`get_term_link()` per row at scale |
| `title`, `description` | admin **overrides** only; NULL means "resolve from template" |
| `canonical_url` | override |
| `robots_noindex`, `robots_nofollow`, `robots_noarchive` | TINYINT(1) overrides |
| `og_title`, `og_description`, `og_image_id` | overrides |
| `twitter_title`, `twitter_description`, `twitter_image_id` | overrides |
| `breadcrumb_title` | override for the label used in breadcrumb trails; falls back to the object's title |
| `schema_disabled` TINYINT(1) | suppress JSON-LD output for this specific object even if its post type/taxonomy has schema enabled — a separate axis from `robots_noindex` (an object can be indexable with schema off, or vice versa) |
| `is_indexable` TINYINT(1) | precomputed: published + publicly viewable + not noindexed. Drives frontend robots output now, and will drive sitemap inclusion in the next design |
| `last_modified` DATETIME | reused by the sitemap module for `<lastmod>` |
| `created_at`, `updated_at` | bookkeeping |

Unique key on `(object_type, object_subtype, object_id)`.

**Why overrides-only, not pre-resolved strings:** templates are global settings. If final resolved title/description strings were stored per row, changing one template (or the site name) would require rewriting every affected row — untenable at millions of rows. Storing only the override columns means a template change takes effect instantly with zero backfill; the render-time cost is one indexed row read plus cheap string substitution, no different in practice from a single `get_post_meta()` call.

**Why one table instead of split post/term tables:** the column set is identical for posts and terms (title/description/canonical/robots/social overrides). A discriminator column is simpler than parallel tables with no structural difference; revisit only if posts and terms need to diverge later.

## Sync strategy

`wp_posts`/`wp_terms` remain the source of truth. The indexable table is a derived index kept current via hooks, following the same shape as Aucteeon's `HPS_Sync_Handler`:

- `Indexable_Sync` (registered through `Hook_Manager`, not raw `add_action`) listens to `save_post`, `transition_post_status`, `wp_trash_post`, `before_delete_post`, `create_term`, `edited_term`, `delete_term`.
- `save_post`, `transition_post_status`, `wp_trash_post`, `create_term`, and `edited_term` recompute `is_indexable`, `permalink`, and `last_modified`, then **upsert** the row via `Indexable_Repository`. A trashed post's row is kept (with `is_indexable` set to `false`) so a later restore-from-trash simply re-syncs it, rather than needing to be reindexed from scratch.
- `before_delete_post` and `delete_term` — permanent deletion — **delete** the indexable row outright. There's no reason to keep a row once the underlying object is gone for good.
- Override columns are untouched by any sync event — they only change when an admin edits them directly through the metabox.

## Backfill for existing content

The table doesn't exist retroactively, so activation (or first run after upgrade) needs to index everything already in the database. Reuses Aucteeno's `Lot_Sort_Backfill` pattern rather than introducing a new dependency (e.g., Action Scheduler), since this plugin has no hard WooCommerce dependency to rely on:

- `Indexable_Backfill::process_batch()` selects the next batch by ID range (`WHERE ID > %d ORDER BY ID ASC LIMIT %d` via `$wpdb`, not `WP_Query` offset pagination, which degrades badly past a few hundred thousand rows) and upserts indexable rows for that batch.
- Last-processed ID is tracked in an option, so batches are resumable and idempotent.
- Driven by a WP-Cron recurring event (e.g., every minute) until exhausted, with a `get_progress()` method (total / processed / remaining / percentage) surfaced as a progress indicator in the plugin's settings screen.

## Title/description generation — templates

Global, variable-based templates per post type and per taxonomy (e.g. `%%title%% %%sep%% %%sitename%%` for Products), because with millions of untouched products nobody is hand-writing individual titles. Per-object overrides (stored in the indexable row) take precedence when set.

**Resolution order:** per-object override → post type/taxonomy template → hardcoded fallback (post title / trimmed excerpt) → `Template_Resolver` expands `%%variables%%` against the object's context → `Output` escapes and prints.

**Available variables:** `%%title%%`, `%%sitename%%`, `%%tagline%%`, `%%sep%%`, `%%excerpt%%`, `%%primary_category%%` (or taxonomy term name in a term context), `%%date%%`, `%%page%%` (for paginated archives), plus WooCommerce-only variables (`%%price%%`, `%%sku%%`) that are silently omitted, not broken, when WooCommerce is inactive.

**Coverage:** every public post type and taxonomy is selectable via a settings checklist (WooCommerce products included automatically when active), plus special pages — homepage, search results, 404, post type archives — each with their own template slot, stored as `system_page` rows in the indexable table.

## Breadcrumbs

A single `Breadcrumbs` class builds one plain trail array — `[ ['title' => ..., 'url' => ...], ... ]` — by walking the current object's ancestry: parent pages (`get_ancestors()`), taxonomy term ancestors for hierarchical taxonomies, the post type archive, and home. This is the **single source of truth** consumed by three renderers, so the visual trail and the structured-data trail can never drift apart:

1. **Template tag** — `taseo_breadcrumbs()`, for theme developers to call directly.
2. **Shortcode** — `[taseo_breadcrumbs]`, for classic-editor content and widgets.
3. **Gutenberg block** — `the-another/seo-breadcrumbs`, a dynamic block whose `render.php` calls the same renderer, for block themes and the Site Editor. This is the one piece of the plugin that needs JS build tooling (`@wordpress/scripts`), matching the `blocks/` structure already established in `the-another-blocks-for-dokan`.

Per-object `breadcrumb_title` overrides (from the indexable table) replace the default title in the trail when set (e.g., a long product title shortened for the breadcrumb). Settings (in the "Schema & Breadcrumbs" tab): separator character, home label, whether to include taxonomy ancestors, whether the current (final) item renders as text or a link.

## Structured data (Schema.org / JSON-LD)

A single `@graph`-style JSON-LD `<script>` per page (the same pattern Yoast/RankMath use), built by a new `Schema_Graph` class and printed by `Output` in `wp_head`. Nodes are interlinked via `@id` rather than duplicated:

- **`WebSite`** — site-wide, printed on every page.
- **`Organization` or `Person`** — the site's represented identity (admin choice, like Yoast's "represents" setting): name, logo, `sameAs` social profile URLs.
- **`WebPage`** (or a more specific subtype) — represents the current URL; `isPartOf` references the `WebSite` node.
- **`BreadcrumbList`** — built directly from the same trail array `Breadcrumbs` produces, so it always matches the visible breadcrumbs.
- **Content-type node**, merged into the current page's node according to the post type/taxonomy's configured schema type: `Article` (headline, datePublished, dateModified, author, image) or `Product` (name, image, sku, `offers` with price/currency/availability, `aggregateRating` when WooCommerce reviews are enabled) when WooCommerce is active, or a plain `WebPage` when no specific type is configured.

**Settings** (new "Schema & Breadcrumbs" tab): site identity (Organization/Person, name, logo, `sameAs` URLs); per-post-type/taxonomy schema type dropdown (extends the existing Post Types & Taxonomies tab — None / WebPage / Article / Product); breadcrumb settings as described above.

**Per-object control**: the metabox gains a "Disable structured data for this item" checkbox, backed by the `schema_disabled` column — independent from the `robots_noindex` override.

**Performance**: like title/description resolution, this runs once per single-object page render against data already loaded for that request (the current post, its WooCommerce product object if applicable, its ancestry) — no bulk queries, no new scaling concern beyond what a normal single-page-view already does.

## Admin UI

- **Settings page** (tabs): General; Post Types & Taxonomies (which are indexed/selectable, plus each type's schema.org type mapping); Titles & Templates (per-type/taxonomy template strings + variable reference); Social Networks (Open Graph on/off, Twitter Card on/off, default social image, Facebook App ID, X/Twitter username); Schema & Breadcrumbs (site identity, breadcrumb display settings).
- **Metabox** on post-edit and term-edit screens: SEO title override, meta description override, canonical override, robots checkboxes, social title/description/image overrides, breadcrumb title override, disable-structured-data checkbox (all overrides fall back to the generated/default value when left blank).

## Frontend output

`Output` hooks `pre_get_document_title` for `<title>`, and `wp_head` at an early priority for meta description, canonical, robots, Open Graph, Twitter Card, and the JSON-LD `@graph` script — reading the single indexable row for the current object. WordPress core's default `rel_canonical` output is unhooked to avoid emitting a duplicate canonical tag.

When WooCommerce is active and the current object is a product, Open Graph output upgrades to `og:type=product` with `og:price:amount`, `og:price:currency`, and `og:availability`, and the JSON-LD content node uses `Product` (per its configured schema type).

## Code conventions

Matches the established house style (`aucteeno`, `aucteeno-nexus`, `the-another-multi-domain-global-styles`):

- PHP 8.3+, WordPress 6.9+
- Namespace: `The_Another\Plugin\SEO`; text domain `the-another-seo`; prefix `taseo`
- Container-based DI (`Container` singleton + `Hook_Manager`) — no scattered `add_action()` calls in container-managed classes
- Repository pattern for the indexable table (`Indexable_Repository`), mirroring `Database_Auctions`/`Database_Items`
- Composer-managed, PSR-4 autoload (`includes/`)
- `@wordpress/scripts`-based build (`blocks/`, `dist/`, `package.json`) for the breadcrumbs block, matching `the-another-blocks-for-dokan` — the only part of the plugin with a JS build step
- Standalone plugin — no dependency on the other Aucteeno/Dokan/Nexus plugins

## Error handling & edge cases

- Nonce + capability checks on every metabox and settings save.
- All output escaped (`esc_html()`, `esc_url()`, `esc_attr()`).
- A selected post type/taxonomy that's later unregistered (e.g., a deactivated plugin) is skipped gracefully rather than causing a fatal error or orphaned settings UI.
- WooCommerce-only template variables and product-specific Open Graph fields are omitted (not left broken/empty) when WooCommerce is inactive.
- Duplicate canonical tags are prevented by unhooking core's default output rather than emitting both.
- If another active plugin appears to also control document title/meta output, an admin notice flags the likely conflict.

## Testing

PHPUnit with Brain Monkey, matching the existing `composer test` pattern used across these plugins:

- `Template_Resolver` variable expansion (all variables, missing variables, WooCommerce-variables-when-inactive)
- `Indexable_Repository` CRUD and upsert-by-unique-key behavior
- `Indexable_Sync` hook coverage: save/trash/delete for posts, create/edit/delete for terms, each producing the correct `is_indexable`/`permalink`/`last_modified` state
- `Indexable_Backfill` batch resumability and idempotency (interrupted mid-run, restarted, no duplicate/missed rows)
- Frontend output correctness: override-present vs. override-absent, Open Graph toggle on/off, Twitter Card toggle on/off, product vs. non-product Open Graph shape
- `Breadcrumbs` trail correctness: hierarchical pages, taxonomy term ancestors, custom post types with/without archives, `breadcrumb_title` overrides
- Breadcrumb output parity: template tag, shortcode, and block all render the same trail for the same object
- `Schema_Graph` node composition per configured schema type (`Article`, `Product`, `WebPage`), `BreadcrumbList` node matches the `Breadcrumbs` trail, `schema_disabled` suppresses output

## Out of scope for v1

- Import/migration from Yoast, RankMath, or AIOSEO
- The XML sitemap generator (separate, upcoming design — will consume `is_indexable`/`last_modified`/`permalink` from this same table)
- Multisite-specific handling
- Instagram-specific meta tags (not a real protocol — see assumption 4 above)
- Schema types beyond `WebSite`/`Organization`/`Person`/`WebPage`/`Article`/`Product`/`BreadcrumbList` (e.g. `FAQPage`, `Review`, `LocalBusiness`, `Recipe`) — can be added later without restructuring `Schema_Graph`

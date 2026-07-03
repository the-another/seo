# The Another SEO — XML Sitemap Generator — Design

**Plugin slug:** `the-another-seo`
**Date:** 2026-07-02
**Status:** Draft — pending review

## Overview

An XML sitemap generator built to handle catalogs up to millions of products without ever generating a sitemap on the fly, and without the classic failure mode of naive chunked sitemaps: removing one object from a 1000-link chunk file cascading into recomputing every downstream chunk. This is **sub-project 2 of 2**, building directly on the indexable table from the [meta tags & social sharing design](2026-07-02-meta-social-tags-design.md) — specifically its `is_indexable`, `permalink`, and `last_modified` columns.

**The core mechanism**: each indexable object is assigned, once, to a specific sitemap chunk file. That assignment is tracked in a small registry table (`taseo_sitemap_files`) rather than derived from position/offset. Removing an object only ever touches the one chunk it belonged to — it shrinks by one link, nothing else regenerates, and once a chunk reaches zero links it's deleted and disappears from the root index.

## Assumptions made during design (please confirm or correct)

Two of the clarifying questions here went unanswered (you may have been away) — I proceeded with the recommended option in both cases, flagged below:

1. **Chunk size is admin-configurable, default and max 1000** — matches your stated ceiling while allowing it to be lowered later without a code change.
2. **Regeneration is dirty-flag + asynchronous sweep, not synchronous** — a save/delete hook only flips a cheap `is_dirty` flag; a recurring background job rebuilds dirty chunk files in small batches via Action Scheduler (bundled — see the [Background jobs section of the meta tags design](2026-07-02-meta-social-tags-design.md#background-jobs--action-scheduler-bundled)). This keeps admin saves and bulk imports/deletes fast even under heavy churn.
3. **Physical `.xml` files under `wp-content/uploads/taseo-sitemaps/`, served directly by the webserver** — per your choice. This means WordPress never loads to serve a sitemap request, which is the fastest possible path, but it assumes a single-server setup or shared/synced storage across servers; a multi-server, non-shared-filesystem host would need an out-of-band sync step this design doesn't cover.
4. **Any under-capacity chunk can receive new objects, not just the newest one** — a refinement over what I described in chat. A brand-new object claims the lowest-numbered chunk (for its subtype) with room, falling back to a new chunk only when none has room. This keeps sitemap files packed over time without reintroducing any cascade: claiming a free slot only ever touches the one target chunk, exactly like removal does.
5. **System pages (home/search/404) are never included in the sitemap** — only `post` and `term` indexable rows participate. Search result pages and 404s should never be indexed, and the homepage is either the front page (already a normal `post`/`page` object) or excluded entirely if using the posts-page default.
6. **No separate "include in sitemap" toggle** — reuses the existing Post Types & Taxonomies registry (from the meta tags design) and each object's `is_indexable` flag. If you want a post type indexable (has meta control, shows in search) but explicitly excluded from the sitemap, that's a gap in v1 — flag if you need it.
7. **`robots.txt` gets an auto-added `Sitemap:` line** pointing at `/sitemap.xml`, matching standard SEO plugin behavior.
8. **No `<priority>`/`<changefreq>` tags** — Google has explicitly ignored both for years; omitting them keeps generated files smaller with no practical downside.

## Data model — sitemap file registry

**Table:** `{$wpdb->prefix}taseo_sitemap_files` — one row per chunk file.

| Column | Purpose |
| --- | --- |
| `id` BIGINT UNSIGNED PK | surrogate key |
| `object_subtype` VARCHAR(32) | post type slug or taxonomy slug (same vocabulary as the indexable table) |
| `chunk_number` | sequential per subtype — produces the filename `{object_subtype}-sitemap-{chunk_number}.xml` |
| `link_count` | cached count of indexable objects currently assigned to this chunk |
| `is_dirty` TINYINT(1) | set whenever a contained object changes/is added/is removed; cleared once the file is rebuilt |
| `last_modified` DATETIME | `MAX(last_modified)` across the chunk's contained objects — used for `<lastmod>` in the root index |
| `generated_at` DATETIME | when the physical file was last successfully written |
| `created_at` | bookkeeping |

Unique key on `(object_subtype, chunk_number)`. Index on `(object_subtype, link_count)` to make "find an under-capacity chunk" cheap.

**Indexable table gets one new column**: `sitemap_file_id` BIGINT NULL, indexed — set once an object is assigned to a chunk, cleared if the object later becomes non-indexable (so re-entry gets a fresh assignment rather than assuming stale membership).

This table stays small regardless of catalog size — at 4 million products and a 1000-link cap, that's ~4,000 rows, trivially fast to query in full.

## How objects link to sitemap files — the stored pointer

The linkage lives on the **object's row in the indexable table**, not in the registry: each indexable row's `sitemap_file_id` points at one registry row. A registry row doesn't store a list of its members — membership is always answered by the reverse lookup, "which rows point at me."

```
taseo_indexables (4M rows)                taseo_sitemap_files (~4,000 rows)
┌────────┬─────────┬──────────────────┐   ┌────┬──────────┬───────┬────────────┐
│ obj_id │ subtype │ sitemap_file_id ─┼─▶ │ id │ subtype  │ chunk │ link_count │
├────────┼─────────┼──────────────────┤   ├────┼──────────┼───────┼────────────┤
│ 88123  │ product │ 3               ─┼─▶ │ 3  │ product  │  3    │ 1000       │
│ 88124  │ product │ 3               ─┼─▶ │    │ → product-sitemap-3.xml       │
│ 88125  │ product │ 7               ─┼─▶ │ 7  │ product  │  7    │ 412        │
│ 512    │ page    │ 41              ─┼─▶ │ 41 │ page     │  1    │ 87         │
└────────┴─────────┴──────────────────┘   └────┴──────────┴───────┴────────────┘
```

One product through its life:

1. **Created** → its indexable row is inserted with `sitemap_file_id = NULL`. Assignment finds the lowest product chunk with room — say chunk #7 at 412/1000 — and writes `sitemap_file_id = 7` on the product's row, bumps chunk 7's `link_count` to 413, flags it dirty. That column write is the entire "add to sitemap" operation.
2. **File rebuild** (background sweep) → `SELECT permalink, last_modified FROM taseo_indexables WHERE sitemap_file_id = 7` returns exactly the ≤1000 rows pointing at chunk 7, and `product-sitemap-7.xml` is written from that result. The file is just a rendering of "everyone currently pointing at me."
3. **Edited** → its chunk is flagged dirty; the next sweep re-renders that one file with the fresh `<lastmod>`.
4. **Deleted** → read its `sitemap_file_id` (7), decrement chunk 7 to 412, flag dirty, remove the indexable row. The next sweep re-renders `product-sitemap-7.xml` — now 412 links, because one fewer row points at it. Chunks 1–6 and 8+ are untouched: nothing ever pointed their members anywhere else, so there is nothing to recompute.
5. **Chunk empties** → when a chunk's `link_count` reaches 0 (no rows point at it anymore), its registry row is deleted, its physical file unlinked, and `/sitemap.xml` stops listing it — the root index is generated from whatever registry rows exist.

The contrast that makes this scale: offset-based generators (the Yoast approach) *compute* membership positionally — "sitemap page 3 = products 2001–3000 via `ORDER BY id LIMIT 1000 OFFSET 2000`" — so deleting product #1500 shifts every later product back one slot and changes the contents of every subsequent page. Here membership is **stored, never computed from position**. Deleting a product doesn't move anyone; it just leaves its old chunk one link lighter.

## Assignment algorithm

Runs as part of `IndexableSync` (from the meta tags design), triggered whenever an object's `is_indexable` flag changes — **only for rows where `object_type` is `post` or `term`**. `system_page` rows (home/search/404/archive templates from the meta tags module) never participate, regardless of their `is_indexable` value; they exist solely to hold title/description templates for those special pages, not to represent sitemap-eligible content (see assumption 5).

**Becomes indexable** (new object, or re-entering eligibility) **and has no `sitemap_file_id`:**
1. Find the lowest-numbered chunk for this `object_subtype` with `link_count < max` (the configured cap).
2. If found: claim a slot **atomically** — `UPDATE taseo_sitemap_files SET link_count = link_count + 1, is_dirty = 1 WHERE id = %d AND link_count < %d`. A read-then-write here would let two concurrent saves both land in a chunk's last slot and overshoot the cap; the conditional update makes the claim safe — if it affects zero rows (another process just took the last slot), re-run the search.
3. If no chunk has room (or none exists): create a new chunk (`chunk_number` = current max + 1, or `1` if none exist), assign, `link_count = 1`, mark dirty.

**Already assigned and stays indexable** (content edit, permalink change, `last_modified` bump):
1. Mark its chunk dirty — nothing else. This is the path that gets updated `<lastmod>` and `<loc>` values into the physical file via the next background sweep; without it, edits to existing objects would never reach their sitemap file.

**Becomes non-indexable, or is permanently deleted:**
1. If it has a `sitemap_file_id`: decrement that chunk's `link_count`, mark it dirty, clear the object's `sitemap_file_id`.
2. If the chunk's `link_count` reaches `0`: delete the chunk row (which drops it from the root index the next time that's read) and delete its physical file immediately — no need to wait for the sweep for a deletion, it's a cheap unlink.

No object is ever moved between chunks after its initial assignment. A chunk's `link_count` can sit below the cap indefinitely; that's an accepted trade-off (see assumption 4) — it costs nothing SEO-wise and avoids ever touching more than one chunk per add/remove.

**Initial population**: the [indexable backfill](2026-07-02-meta-social-tags-design.md#backfill-for-existing-content) performs chunk assignment inline as it indexes each batch (chunks fill sequentially during backfill, so the "find a chunk with room" lookup is effectively free — it's always the current tail chunk). Without this, the sitemap would only ever cover objects created after plugin activation.

## Regeneration

`SitemapFileWriter::rebuild( $chunk_id )`:
1. Query `SELECT permalink, last_modified FROM taseo_indexables WHERE sitemap_file_id = %d AND is_indexable = 1 ORDER BY id` — bounded to at most the chunk cap (≤1000) rows, so this is always cheap regardless of total catalog size.
2. Build a `<urlset>` document: one `<url>` per row with `<loc>` and `<lastmod>` only.
3. Write to `wp-content/uploads/taseo-sitemaps/{object_subtype}-sitemap-{chunk_number}.xml` via the WP Filesystem API.
4. Update the chunk row: `is_dirty = 0`, `generated_at = now()`, `last_modified` = the max `last_modified` just queried.

A recurring Action Scheduler action (e.g. every 5 minutes) selects a bounded batch of dirty chunks (e.g. 20 per run) and rebuilds each — bounding execution time per run regardless of how much churn happened. When the sweep finds more dirty chunks than its batch size (e.g. after a permalink rebuild marks all ~4,000 dirty), it immediately schedules a follow-up action rather than waiting for the next 5-minute tick — the backlog drains as a chain of short jobs, per the mass-operation rule in the meta tags design. Two processes racing to rebuild the same dirty chunk is harmless: rebuild is idempotent (it always reflects current DB state), so a race just means a redundant write, not corruption — no locking needed.

## Root index (`/sitemap.xml`)

Generated **live**, on each request, by a WP rewrite endpoint that queries `taseo_sitemap_files` (small — thousands of rows even at catalog-scale) and outputs a `<sitemapindex>` with one `<sitemap>` entry per row, `<loc>` pointing at that chunk's uploads URL, `<lastmod>` from the row.

This is a deliberate asymmetry, not an inconsistency: "no on-the-fly generation" refers to the expensive part — building a 1000-URL list from a 4-million-row catalog. The root index is a query over a few thousand small rows, cheap enough on every request that adding a "regenerate the root index" step would only add complexity for no benefit (it's always current, with no dirty-tracking needed for it specifically).

**Serving the chunk files — root-level URLs, not uploads URLs**: the sitemaps.org protocol scopes a sitemap file to URLs at or below its own directory — a `<urlset>` served from `/wp-content/uploads/taseo-sitemaps/` cannot legitimately list site URLs like `/product/foo/`. Google relaxes this rule for sitemaps submitted via robots.txt, but Bing and strict validators enforce it. So chunk files are **referenced at root-level URLs** (`/product-sitemap-3.xml`) in the root index, and served from the physical uploads path via an internal rewrite:

- **Preferred**: a webserver-level static rewrite (`^([a-z0-9_-]+)-sitemap-(\d+)\.xml$` → the uploads path) — added to `.htaccess` on Apache, documented as a copy-paste snippet for Nginx. Still a pure static-file serve; WordPress never loads.
- **Fallback**: a WP rewrite rule matching the same pattern that streams the physical file via `readfile()` with the correct content type. Slower (WordPress boots) but generation is still never on-the-fly — it only reads the pre-built file. Used automatically on hosts where the webserver config can't be modified.

## Admin UI

New **Sitemap** settings tab:
- Enable/disable the sitemap feature entirely.
- Links per file (default/max 1000, per assumption 1).
- Status panel: per-subtype chunk count, total dirty-chunk count, most recent regeneration time — operational visibility given regeneration happens asynchronously via Action Scheduler.
- Manual "regenerate now" action, for clearing a backlog or forcing an immediate rebuild.

No per-post-type sitemap toggle beyond what already exists (Post Types & Taxonomies registry) and `is_indexable` — see assumption 6.

## Error handling & edge cases

- **Uploads directory not writable**: sitemap generation is disabled with a clear admin notice, rather than silently failing or fataling. This is the same class of environment problem that would already break WordPress media uploads, so it's surfaced the same way — not solved uniquely by this plugin.
- **Sweep falling behind**: if churn produces dirty chunks faster than the background sweep drains them, the status panel's dirty-chunk count makes this visible rather than it silently going stale.
- **Disabling a previously-enabled post type** (in the meta tags module's registry): its objects' `is_indexable` flips false, which flows through the same decrement/delete-at-zero path as any other removal — no special-case code needed, module 1 and module 2 compose correctly through `is_indexable`.
  - **KNOWN LIMITATION (v1, found in final review):** this flow does not actually trigger today. `IndexableSync` bails for non-enabled types *before* upserting, so existing rows of a just-disabled type keep `is_indexable = 1` and their sitemap slots; a rescan doesn't fix it either, because the backfill queries filter by the enabled-type list. Their URLs remain in the chunk files until each object is individually trashed/deleted. Fixing this needs a small dedicated design (a "release disabled subtypes" AS job that walks removed subtypes' rows and flips them non-indexable, firing the normal release path) — tracked as a follow-up to both modules.
- **Permalink structure changes**: the meta tags module fires `taseo_permalinks_rebuilt` after re-backfilling the cached permalink column (see its sync strategy section); the sitemap module listens and marks **all** chunks dirty, so every file regenerates with the new URLs via the normal background sweep. This is the one legitimate "everything regenerates" event — triggered by an explicit admin action, not by content churn.
- **Full-page caching**: if a page-cache plugin caches `/sitemap.xml` (the live-generated root index), newly added/removed chunk entries won't reflect until that cache entry expires. Worth excluding `/sitemap.xml` from full-page cache rules; the chunk files themselves are already static and cache-friendly by nature.
- **Concurrent rebuild races**: accepted as harmless (see Regeneration above) — no locking mechanism needed.

## Testing

PHPUnit with Brain Monkey, matching the existing `composer test` pattern:

- Assignment: new object claims the lowest-numbered under-capacity chunk; claims a gap left by a prior removal; seals a chunk and opens a new one once all existing chunks are full; decrements and deletes a chunk at zero; the atomic claim re-runs the search when the conditional update affects zero rows (simulated lost race); an edit to an already-assigned object marks its chunk dirty without changing assignment.
- Permalink rebuild: `taseo_permalinks_rebuilt` marks every chunk dirty.
- `SitemapFileWriter`: valid `<urlset>` output, correct `<loc>`/`<lastmod>`, respects `is_indexable` filtering, correct file path/naming.
- Root index: reflects current `taseo_sitemap_files` rows immediately after a chunk is created or deleted (no caching lag on our side).
- Dirty-flag lifecycle: set on the relevant mutation events, cleared only after a successful rebuild, sweep batch size is respected and a follow-up action is scheduled when a backlog remains.
- Uploads-not-writable path surfaces the admin notice and does not fatal or partially write.

## Out of scope for v1

- Image sitemap extensions (`image:image` tags)
- News sitemap
- `<priority>`/`<changefreq>` tags (intentionally omitted — see assumption 8)
- Multi-server/shared-storage synchronization for the physical chunk files (explicitly accepted trade-off — see assumption 3)
- A separate "include in sitemap" toggle distinct from the existing post type registry / `is_indexable` (see assumption 6)
- Automatic compaction/repacking of sparse chunks beyond opportunistic gap-filling for new objects

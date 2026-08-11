# Changelog

All notable changes to The Another SEO are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> How releases are cut: add notes under **[Unreleased]** as you work. Running `make version-patch|version-minor|version-major` promotes the `[Unreleased]` section here into a dated release entry, opens a fresh empty `[Unreleased]`, and retargets the comparison links below. (It separately appends a `* Version bump` stub to [`readme.txt`](readme.txt), the WordPress.org listing — replace that stub with the same notes when curating a release.)

## [Unreleased]

### Added
- Post subtypes: one post type can be split into several SEO subtypes via the `taseo_post_subtypes` (declare) and `taseo_post_subtype` (resolve) filters. Each subtype gets its own title/description templates, schema type, and sitemap family — so a marketplace storing auctions, catalogue items, and merchandise in a single `product` post type can treat them as three things instead of one. Anything a resolver does not claim stays in the post type's own bucket.
- `taseo_schema_graph` filter over the finished `@graph` node list, applied last, for adding images, vendor `Organization` nodes, or any other node an integration owns.
- `taseo_sync_post()`: public entry point for integrations whose writes bypass `save_post` (direct `$wpdb` importers), running the same code path as the `save_post` handler.
- WooCommerce structured-data de-duplication: WooCommerce emits its own JSON-LD from the footer, so a product page carried two Product nodes and two BreadcrumbLists (and a theme rendering the summary twice produced two *identical* Products in one script). Its copies are now suppressed — but only for the nodes this plugin actually emitted on that request, so switching schema off for a subtype leaves WooCommerce's markup in place rather than stripping structured data and putting nothing in its place.
- Sitemap include/exclude toggles now cover post subtypes and taxonomies alongside external URL families. Excluding one keeps its indexable rows and per-object overrides, so re-including restores the URLs.

### Fixed
- JSON-LD values are no longer HTML-encoded. WordPress's `the_title` filter runs `wptexturize()`, so titles reached the graph as `Jack Daniel&#8217;s` — correct inside `<title>` and a meta attribute, wrong in JSON-LD, where consumers render the entity literally. Decoding is applied after `taseo_schema_graph`, so the invariant holds for integrator-contributed nodes too. HTML output is unchanged.

### Changed
- `get_schema_type()` takes an optional owning post type: only post types have schema-type defaults, so a subtype split out of one inherits its owner's. Without this, splitting `product` into auctions and items silently downgraded both from `Product` to `WebPage`.
- The resolved context array carries `post_type` alongside `object_subtype`. Social and schema output probe WooCommerce through it, since a subtype no longer implies its post type.

## [0.3.0] - 2026-08-07

### Added
- Public sitemap push API: other plugins register URL families via the `taseo_sitemap_families` filter and push URLs with `taseo_sitemap_sync_url()` / `taseo_sitemap_delete_url()` / `taseo_sitemap_delete_family()`.
- Per-family include/exclude toggles on the Sitemap settings tab, with safe disable (files removed, membership kept) and background re-enable reconciliation.
- Image sitemap tags: featured images for posts by default, arbitrary images via the `taseo_sitemap_images` filter and the push API, rendered under the Google image namespace.
- Emptied sitemap chunks are tombstoned: removed from the sitemap index and answering `410 Gone` on direct requests (URLs that never existed keep answering 404); a tombstoned chunk is reused and resurrected when its subtype gains URLs again.

### Changed
- All sitemap file I/O now flows through a single storage seam that resolves the uploads location per call, so stream-wrapper offloads (e.g. S3) relocate sitemap files transparently; Apache static-serve rules are suppressed when uploads are stream-wrapped.

## [0.2.0] - 2026-07-27

### Added
- Developer documentation: `README.md`, `CONTRIBUTORS.md`, and this `CHANGELOG.md`.
- Portable CI/CD pipeline: shared `scripts/setup/*` (toolchain) and `scripts/tests/*` (one suite each) shell scripts that run identically inside the local Docker images (now `ubuntu:24.04`-based) and natively on GitHub's `ubuntu-24.04` runners; a five-job PR gate (`.github/workflows/ci.yml` — PHPCS, PHPUnit, JS Unit, Functional E2E, Plugin Check); and a GitHub release pipeline (`.github/workflows/release.yml`) that, on every push to `master`, re-runs the full gate, builds the release zip, tags `v<version>` from `package.json`, and publishes a GitHub Release.
- `/deploy-plugin` project skill: preps a versioned release on the PR branch (full local gate, version bump, changelog curation, lock-file validation, push, CI monitoring).
- Site verification: `google-site-verification`, `msvalidate.01`, `yandex-verification`, `y_key`, and `facebook-domain-verification` meta tags on the front page, plus virtually-served verification files (`google<token>.html`, `BingSiteAuth.xml`, `yandex_<token>.html`) with byte-exact bodies and no file written to disk.
- Tracking snippets: GA4 (`gtag.js`), Google Tag Manager, and Meta Pixel, configured on a new **Webmaster Tools** settings tab.
- Titles & Templates tab: a context-aware `%%variable%%` registry replaces the old hardcoded help line, rendered per row as clickable variable pills and a `%%`-triggered autocomplete (core's `@wordpress/components` `Autocomplete`, no bespoke widget or stylesheet). A row only offers and accepts the variables that actually resolve for its content type — a page row no longer offers `%%price%%`, or `%%primary_category%%` unless its post type is registered for the category taxonomy — and saving a template with a variable that cannot resolve there rejects that field only, keeping its previous value and leaving sibling rows saved.
- Titles & Templates tab: every `%%variable%%` in a title or meta description field is now shown as an inline chip carrying that variable's human label instead of raw token text, typed or pasted variables become chips as you write them, and a variable the row cannot resolve is marked with core's `.form-invalid`. The stored value is unchanged — the surface writes the same `%%token%%` text back into the same field, casing included — and with the bundle blocked or JavaScript off the plain input is still there and still saves.
- Filters for programmatic extension: `taseo_verification_tags`, `taseo_verification_files`, `taseo_verification_should_print`, `taseo_analytics_ga4_ids`, `taseo_analytics_gtm_ids`, `taseo_analytics_gtag_config`, `taseo_meta_pixel_ids`, three consent gates — `taseo_tracking_should_print`, `taseo_analytics_should_print`, `taseo_meta_pixel_should_print` — and `taseo_template_variables`, which scopes or extends the variables offered/accepted per object type and subtype.
- Image fields (default social image, Organization logo, and the per-post/term OG and Twitter images) are now chosen through WordPress's own media library — with its Upload tab — instead of requiring a hand-typed attachment ID, and each one gains an optional image URL that overrides the chosen attachment so an off-site or CDN image needs no developer.
- Filters for programmatic image overrides: `taseo_og_image_url`, `taseo_twitter_image_url`, and `taseo_logo_url`, each applied after the stored values resolve and each able to suppress its image entirely by returning an empty string.
- Custom pages on the Titles & Templates tab: another plugin can register a page of its own — a checkout screen, an account area, any virtual page — with `add_filter( 'taseo_custom_pages', … )`, giving it title and meta description template rows, and claim the request it appears on with `add_filter( 'taseo_custom_page_context', … )` so those templates actually render. The context filter runs before the built-in checks, so a custom page backed by a real WordPress page (as WooCommerce's checkout is) can still claim it; only a subtype registered through the first filter resolves, so the two are a matched pair. With none registered, the section explains both steps.
- Titles & Templates tab: a section navigation across Post types, Taxonomies, System pages and Custom pages, using core's own `subsubsub` styling.

### Changed
- Titles & Templates tab: each row is now named for its registered post type, taxonomy, or system page (e.g. "Products", "Home page") instead of its raw `post:product`-style key, with that key still shown beneath in `<code>` for reference. Every title/meta description input has its own visible label bound to it by `id`, with multi-input rows grouped in a `<fieldset>` carrying a screen-reader legend that names the row. The tab is split into sectioned tables, each under its own heading. Validation errors now name the row in plain language too (e.g. "Products: %%price%% is not available for this content type…") instead of printing its internal `post:product` key.
- Titles & Templates tab: the variable pills under each row now read as the variable's name ("Publish date") rather than its raw `%%date%%` token, matching the chip that clicking one inserts; the token itself is still what gets stored. The pills sit under a heading naming them and saying which fields they serve, since they render below the last input in the row and previously read as belonging to the meta description alone — which was backwards, as a click lands in whichever field was last focused and defaults to the title. Variable names are now short labels rather than sentence-long descriptions, so a chip no longer crowds out the template it sits in.
- Both Docker base images moved Alpine 3.24 → `ubuntu:24.04`; the musl-Chromium, ffmpeg-symlink, and `memory_limit` workarounds are removed in favour of Playwright's own Chromium. The Playwright sandbox toggle is now `THE_ANOTHER_SEO_CHROMIUM_NO_SANDBOX`.

### Fixed
- Saving a title or meta description template containing `%%date%%` silently corrupted it, storing `%te%%` instead. WordPress's `sanitize_text_field()` strips anything matching `/%[a-f0-9]{2}/i` as a stray percent-encoded byte, and `%%date%%` contains `%da`; because the mangled text no longer looked like a token, validation never caught it either. Templates are now sanitized without that step. This affected `%%date%%` in 0.1.0 and would have affected any future variable whose name starts with two hex characters.
- Saving settings now returns you to the tab you were on instead of bouncing to General.

## [0.1.0] - 2026-07-02

### Added
- Initial release.
- Indexable content table built at catalog scale, with templated titles and meta descriptions.
- Open Graph and Twitter Card meta output.
- Schema.org JSON-LD structured data.
- Breadcrumbs block.
- Chunked static XML sitemaps.

[Unreleased]: https://github.com/the-another/seo/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/the-another/seo/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/the-another/seo/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/the-another/seo/releases/tag/v0.1.0

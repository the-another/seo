# Changelog

All notable changes to The Another SEO are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> How releases are cut: add notes under **[Unreleased]** as you work. Running `make version-patch|version-minor|version-major` promotes the `[Unreleased]` section here into a dated release entry, opens a fresh empty `[Unreleased]`, and retargets the comparison links below. (It separately appends a `* Version bump` stub to [`readme.txt`](readme.txt), the WordPress.org listing — replace that stub with the same notes when curating a release.)

## [Unreleased]

### Added
- `taseo_sitemap_xml` filter: every sitemap document served through PHP — the live root index and every chunk served through the WP fallback — passes through it just before echo, so a multi-domain plugin can transform the XML per request (The Another Multi-Brand Global Styles rewrites canonical-host URLs to the Brand host being browsed). The plugin itself still always renders canonical-host URLs; with no subscribers, chunks keep streaming from disk exactly as before. A subscriber returning a non-string is ignored rather than corrupting the document.

### Fixed
- Sitemap requests on a non-canonical host could be served the raw chunk file by the Apache static-serve rules, bypassing PHP — and therefore any `taseo_sitemap_xml` subscriber — entirely. The static block now carries a canonical-host `RewriteCond` (www and apex forms, any port), so only canonical-host requests are served statically; every other host this install answers on (a Brand domain) falls through to the WP fallback where the filter runs. Cross-host URLs in a sitemap violate the sitemaps.org same-host rule and are ignored by crawlers, so a Brand domain's sitemap was previously invisible to search engines.

## [1.1.0] - 2026-08-14

### Added
- WP-CLI commands for the plugin's operational surface: `wp taseo rescan`, `wp taseo regenerate`, `wp taseo status`, and `wp taseo cleanup`. The first two dispatch the same Action Scheduler chains the admin buttons do, and take `--wait` to drive the queue and block until it drains. `wp taseo rescan --mode=permalink` runs the chain that fires `taseo_permalinks_rebuilt` on completion, which the admin button does not — that is the one that re-triggers integrations after a store base or permalink structure moves. `--wait` reports what actually finished: a chain that stops early on a failed action leaves the queue quiet but the work incomplete, and both commands warn with the remaining backfill percentage or dirty-chunk count rather than claiming success.
- `wp taseo cleanup` removes indexable rows and sitemap files that no longer correspond to anything: rows for deleted posts and terms, for post types and taxonomies no longer enabled, and for sitemap families no longer registered; objects holding rows under two subtypes at once, which publishes one URL from two sitemap files; and XML files left behind by a tombstoned or suspended chunk, which otherwise keep answering 200 forever. It deletes by default — `--dry-run` reports the same counts without touching anything, and `--only=<rows|duplicates|files>` scopes a run. It refuses to run when a plugin that owns existing rows looks inactive: no sitemap families registered while pushed URL rows exist, or no post subtypes registered while rows carry a subtype that is neither a post type nor a taxonomy. Either means a provider is deactivated rather than that its rows became garbage. Sitemap files written in the last 15 minutes are also left alone, because a chunk being rebuilt is briefly indistinguishable from a suspended family's leftover.

### Changed
- `IndexableRepository::purge_stale_subtypes()` is now public, so maintenance tooling drives the same purge the sync path does instead of carrying a second copy of it.

## [1.0.0] - 2026-08-13

### Added
- Per-domain site verification and tracking. A multi-brand site whose brands live on separate domains can now hold its own Google Search Console, Bing Webmaster Tools, Yandex Webmaster, Yahoo and Meta verification codes, its own verification files, and its own GA4 / Tag Manager / Meta Pixel IDs for each domain. The Webmaster Tools tab gains a domain switcher; the site's own host is always the default and always first.
- `taseo_verification_domains` filter: push a host to give it its own codes. Values are normalized (lowercase, scheme/port/path and leading `www.` stripped) and de-duplicated, and the site's own host is always present and always first, so a filter cannot remove or reorder the default. The Another Multi-Brand Global Styles pushes every host from its published Brands' URL rules; with no subscribers the list is the site's own host and behaviour is unchanged.
- Verification codes and verification files are per-domain with no inheritance — a webmaster property is verified on its own, and inheriting would guarantee a silently failed verification instead of an obviously empty field. Tracking IDs do inherit: a blank GA4 / Tag Manager / Meta Pixel field on a brand domain falls back to the default domain's, so brands sharing one analytics property need it typed once.
- Requests arriving on an unrecognised host — a staging alias, a bare IP, a load balancer — resolve to the default domain, which is exactly what every host received before. Existing single-domain sites need no migration and see no output change: the default domain keeps using the settings keys it already had.
- Verification method selection. Google Search Console, Bing Webmaster Tools and Yandex Webmaster each verify by **either** a meta tag or a file, chosen per service and per domain. Each service now has one input instead of two: paste the code, the file name, or the whole meta tag, and the plugin stores the bare token. The file name is derived from it — `google{token}.html`, `yandex_{token}.html`, and Bing's fixed `BingSiteAuth.xml` — so there is nothing to copy twice and nothing to keep in sync. Yahoo and Meta are unchanged; neither publishes a file method.

### Changed
- Verification settings collapsed from two keys per service to one token plus a method. A one-time migration converts existing settings, including every per-domain record, and runs before any output. Bing and Yandex are lossless — both keys held the same token. Google is the one service whose two methods use unrelated credentials, so a site that had **both** a Google meta code and a Google verification file keeps the file and loses the meta code; its `<meta>` tag stops printing after the upgrade. A dismissible admin notice names every service and domain this happened to, so nothing is discarded silently. Re-add a code from Search Console to switch back to the meta tag.
- Switching a service's method and saving clears a stored value that does not fit the new method's shape — a Google meta code is not a Google file token, and vice versa. The save now says so: the field names the service whose value was discarded instead of reporting success over an empty box, and the input's placeholder shows which shape the selected method expects (`google1a2b3c.html` and `yandex_9f8e7d.html` in file mode, the token from `BingSiteAuth.xml` for Bing, the plain code in meta mode).

## [0.4.0] - 2026-08-11

### Added
- Post subtypes: one post type can be split into several SEO subtypes via the `taseo_post_subtypes` (declare) and `taseo_post_subtype` (resolve) filters. Each subtype gets its own title/description templates, schema type, and sitemap family — so a marketplace storing auctions, catalogue items, and merchandise in a single `product` post type can treat them as three things instead of one. Anything a resolver does not claim stays in the post type's own bucket.
- `taseo_schema_graph` filter over the finished `@graph` node list, applied last, for adding images, vendor `Organization` nodes, or any other node an integration owns.
- `taseo_sync_post()`: public entry point for integrations whose writes bypass `save_post` (direct `$wpdb` importers), running the same code path as the `save_post` handler.
- WooCommerce structured-data de-duplication: WooCommerce emits its own JSON-LD from the footer, so a product page carried two Product nodes and two BreadcrumbLists (and a theme rendering the summary twice produced two *identical* Products in one script). Its copies are now suppressed — but only for the nodes this plugin actually emitted on that request, so switching schema off for a subtype leaves WooCommerce's markup in place rather than stripping structured data and putting nothing in its place.
- `taseo_template_variable_values` filter: the counterpart to `taseo_template_variables`, which only declares which tokens a context *offers*. Post, term and system-page contexts previously had no way for a plugin to supply a value, so a declared token expanded to nothing. Custom pages already had this through `taseo_custom_page_context`.
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

[Unreleased]: https://github.com/the-another/seo/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/the-another/seo/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/the-another/seo/compare/v0.4.0...v1.0.0
[0.4.0]: https://github.com/the-another/seo/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/the-another/seo/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/the-another/seo/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/the-another/seo/releases/tag/v0.1.0

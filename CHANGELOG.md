# Changelog

All notable changes to The Another SEO are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> How releases are cut: add notes under **[Unreleased]** as you work. Running `make version-patch|version-minor|version-major` promotes the `[Unreleased]` section here into a dated release entry, opens a fresh empty `[Unreleased]`, and retargets the comparison links below. (It separately appends a `* Version bump` stub to [`readme.txt`](readme.txt), the WordPress.org listing — replace that stub with the same notes when curating a release.)

## [Unreleased]

### Added

- Tracking now waits to be allowed. A consent gate asks before any analytics or marketing tag runs, remembers the answer, and lets a visitor change or withdraw it later. It is **off** on an existing site — updating a plugin is not the moment to silently stop a site's analytics — and **on** for a fresh install, which has no established behaviour to preserve. A new Consent tab carries the three things a site actually has to decide: whether the gate is on, the privacy policy the banner links to (per domain, inheriting from the default domain like the tracking IDs already do), and how long an answer lasts before it is asked again. ([#18](https://github.com/the-another/seo/issues/18))
- **The decision is made in the browser, and that is the design rather than a shortcut.** These sites cache whole pages across catalogues of millions of URLs, and a server-rendered answer to "did this visitor accept?" is wrong the moment the response is stored and replayed: whoever warmed the cache would have decided for everyone behind them. So the server never asserts that anyone accepted. While the gate is on, every snippet is written into the page **inert** — `type="text/plain"` carrying a `data-taseo-consent` attribute, executing nothing and fetching nothing, because a script whose type is not JavaScript is neither run nor downloaded — and a small inline script activates only the categories the visitor accepted. The bytes the server sends are identical for every visitor, so there is no variant for a cache to store and no cache to teach: nothing about page caching needs configuring, on any host.
- Analytics and marketing are answered separately, and refusing is one button beside accepting rather than a path through a settings panel. The banner renders entirely in JavaScript inside a Shadow DOM, so a theme's CSS cannot reach into it and its own styles cannot leak out; every colour, radius and font is a `--taseo-consent-*` custom property with a fallback, so a brand theme restyles it from its own stylesheet without touching PHP. A theme that wants its own consent UI can render one against `window.taseoConsent` (`get`, `set`, `open`, `on`) and keep the blocking and storage underneath. Any element marked `data-taseo-consent-open` opens the preferences; a page carrying none gets a small control of its own, so a decision is always reachable.
- The answer is kept in `localStorage`, not a cookie: it never travels over HTTP, so it cannot enter a cache key, cannot be varied on by a host's cache rules by accident, and adds nothing to every request on a site serving millions of pages. It is re-asked when it expires, and also when the site starts offering a category the stored answer never mentioned — which is what stops a tracking vendor added later from inheriting an agreement nobody gave for it.
- `taseo_consent_enabled` turns the gate off for one request, for a site running its own consent platform. `taseo_consent_config` filters everything handed to the browser — the categories offered, the lifetime, the policy URL, the crawler pattern and every string the banner renders — as one filter rather than five, because a site replacing the copy usually replaces the policy link with it.

### Changed

- A `<noscript>` half is no longer emitted while the gate is on. The Tag Manager iframe and the Meta Pixel image exist for visitors with JavaScript disabled, and such a visitor has no way to have answered and no way to be asked — emitting one would leave exactly one ungated tag on the page. With the gate off they are emitted exactly as before.
- GA4 and Google Ads still share one `gtag.js`, but under the gate the loader is emitted once per consent category and grouped, so that at most one of them runs. The loader's URL embeds an ID: a single loader offered to both categories would have fetched a URL naming the GA4 property for a visitor who accepted only marketing, which is the precise leak this feature exists to prevent. Accepting both still loads one copy of the library, with a `config` line per property.
- `Analytics\Transport\TagTransport` now receives each vendor's declaration alongside its IDs rather than a bare `key => IDs` map, because a transport has to know which consent category an ID belongs to before it can block it. The interface is internal, was introduced in 1.5.0, and nothing outside this plugin implements it.
- The published gates — `taseo_tracking_should_print`, `taseo_analytics_should_print`, `taseo_marketing_should_print`, `taseo_meta_pixel_should_print` — keep their names, arguments and `true` defaults, and keep meaning **site policy**: a `false` answer still emits nothing at all, not even inert. They are deliberately not where a visitor's refusal is enforced. A gate that returned `false` would delete the snippet and leave the browser nothing to activate, so refusal is enforced one step later, by the snippet being inert until the visitor's own choice activates it.

## [1.5.0] - 2026-09-13

### Added

- A typed registry of supported tracking vendors, replacing the two bespoke output classes. Each vendor is one entry declaring a stable key, the pattern its ID must match, a consent category, a placement and hook priority, and the transport that emits it — GA4, Tag Manager and Meta Pixel declare exactly what they already did, and **Google Ads** (`AW-…`) and **Bing UET** (numeric tag ID) join them with fields on the Webmaster tab, per-domain and inheriting like the rest. Google Ads needed no rendering code at all: it is gtag.js, so it shares GA4's transport instance and a site running both loads one `gtag.js` and gets a `config` line per ID rather than two copies of the same library. The two stay separate entries so their consent categories can differ — a marketing refusal drops the `AW-` IDs and leaves the `G-` IDs rendering in the same block. The settings screen's own copy of the ID patterns is gone with the duplication it invited: a field that saved what the output layer then rejected had nothing to catch it. ([#17](https://github.com/the-another/seo/issues/17))
- `taseo_tracking_tag_ids`, a filter over the whole resolved tag collection for one request, applied after settings and the per-vendor ID filters and before any output. Add a vendor, replace the collection, or return an empty array to emit nothing at all — which is what a host site needs when a page belongs to one party, or spans several, neither of which the plugin can decide for itself. It receives `key => IDs` carrying only vendors that would otherwise render, plus the normalized host the request arrived on — the argument the older per-vendor filters lack. Identifiers only: everything it returns is re-validated against its vendor's pattern and unknown keys are dropped, so markup cannot reach `<head>` through it, and key order is ignored because rendering order is the registry's. Consent gates run *after* it, so an override can say "use this party's IDs here" but never "ignore the visitor's refusal". Programmatic only — there is no per-post UI.
- `taseo_marketing_should_print`, the marketing category's consent gate, covering Meta Pixel, Google Ads and Bing UET. Defaults to `true`, like the gates beside it. `taseo_meta_pixel_should_print` is retained, still honoured for the pixel specifically — its new firing conditions are noted under Changed below; new vendors get no per-vendor gate, because dropping one vendor for one request is what `taseo_tracking_tag_ids` is for.
- `Settings::get_tracking_id( string $settings_key, string $host = '' )`, one getter keyed by settings key, so a vendor added to the registry needs no method here. `get_ga4_id()`, `get_gtm_id()` and `get_meta_pixel_id()` remain and now delegate to it.

### Changed

- Tracking IDs are resolved once per request rather than per output hook. A container loader and its `<noscript>` iframe previously read their filters separately, so a non-deterministic subscriber could make the two disagree about which container to load; one resolution makes that impossible. Emitted output is unchanged for any deterministic subscriber — only the number of filter invocations differs.
- A consent category's gate is applied only when that category has something to gate, so a site running no marketing tags never calls a marketing consent callback.
- `taseo_meta_pixel_should_print`, published since 1.0.0, moves onto the same terms as the category gates beside it: it now runs only when the pixel has at least one candidate ID, and is skipped entirely once the marketing category gate has already refused, rather than on every request regardless of either. A subscriber with side effects in its callback will notice it firing less often — never more.
- `taseo_tracking_should_print` returning `false` now also skips `taseo_tracking_tag_ids`. The global gate means "emit nothing", so nothing may add tags back through it; per-vendor ID filters were already skipped and still are.

### Removed

- `Analytics\AnalyticsOutput` and `Analytics\MetaPixelOutput`, and the container services `analytics_output` and `meta_pixel_output`, replaced by `tag_registry`, `tag_resolver` and `tag_output`. Both classes were internal — every published hook they carried survives, unchanged, with the same arguments and defaults, and the bytes emitted for GA4, Tag Manager and Meta Pixel are identical when nothing subscribes to the new filter.

## [1.4.0] - 2026-09-12

### Added

- `uninstall.php`: deleting the plugin now removes everything it wrote — the `taseo_indexables` and `taseo_sitemap_files` tables, all eight `taseo_` options, and the `uploads/taseo-sitemaps/` directory. Previously every one of them survived deletion; the sitemap chunks in particular stayed fetchable at their own uploads path, so anything holding those direct URLs kept being served stale XML by the webserver with no plugin involved. Teardown lives in `Uninstaller`, the inverse of `Installer::activate()`, with each artifact removed by the class that owns it (`IndexablesTable::drop_table()`, `SitemapFilesTable::drop_table()`, `SitemapStorage::delete_directory()`). Deactivation is unchanged and still keeps all data. Runs per site on multisite. ([#14](https://github.com/the-another/seo/issues/14))

## [1.3.0] - 2026-09-12

### Added
- Sitemap responses now carry `Cache-Control: public, max-age=…`, defaulting to 24 hours, with a per-type override on the Sitemap settings tab. `public` is stated explicitly because a sitemap is a public document by definition and a shared cache is otherwise free to treat the response as private and store nothing — which matters most where the Apache static-serve block cannot apply (offloaded uploads), since every miss there is a WordPress boot plus a bucket round trip. One override map keyed by subtype covers post types, taxonomies and external URL families alike: all three share the subtype namespace, and the chunk registry and its files are keyed by subtype alone, so a subtype is exactly the granularity at which a sitemap file exists. An empty field inherits the sitewide value, `0` makes caches revalidate every time (which the `304` path answers cheaply), and the root index — the one response with no subtype of its own — always uses the sitewide value. The header is sent on `304` responses too, as RFC 9110 asks.
- `taseo_delete_post_indexable( int $post_id )`, for plugins that delete posts with raw SQL to skip the expensive WordPress/WooCommerce delete hooks — a bulk importer retiring expired listings, for instance. `before_delete_post` does not fire for those, so this plugin never learned the post was gone: the indexable row outlived it, the sitemap kept publishing a URL that now 404s, and the chunk slot was never given back, so the chunk could never drain to zero and retire. Call it while the post row is still readable; afterwards the subtype cannot be resolved and the call is a no-op. It removes one row and one slot, so retiring a whole catalogue belongs in a bounded job chain rather than a loop.
- Chunk URLs served through PHP now carry a `Last-Modified` header and answer a conditional request with `304 Not Modified` — decided from the chunk's registry row, before any storage call. This matters most where uploads are offloaded to a stream wrapper (`s3://…`): there the plugin's Apache static-serve block is deliberately suppressed, because a rewrite target that cannot exist on local disk is dead configuration, so *every* chunk request reaches PHP and each `exists()`/`stream()` is a round trip to the bucket for a body that can reach a megabyte. A crawler revalidating a settled chunk now costs one indexed lookup on a registry table that stays in the low thousands of rows, and no bucket traffic or body at all. A tombstoned chunk still answers `410` rather than `304`, since it stopped being a document rather than merely not changing.

### Changed
- Tested against WordPress 7.1. The e2e and Plugin Check suites provision the version pinned in `scripts/setup/e2e.sh`, so the pin and the `Tested up to` header move together — the header is never a claim the suite has not actually run against.
- A re-sync that changes nothing no longer dirties the chunk it belongs to. `taseo_indexable_synced` now carries a fourth `$changed` argument, taken from the affected-row count of the upsert — MySQL reports zero when `ON DUPLICATE KEY UPDATE` finds every column already equal, and every column in that statement is one a chunk file renders or takes membership from. Assignment and release still run either way, because those describe membership rather than content and an unchanged row can still be wrong about it; only the mark-dirty branch reads the flag. A provider that re-pushes its catalogue on a schedule previously dirtied a chunk per row per pass, and each of those rebuilds re-rendered a file to the same bytes while moving the `<lastmod>` the root index publishes — which is exactly the value a crawler uses to decide whether to fetch that sub-sitemap again. Existing three-argument subscribers are unaffected.
- Sitemap chunk packing is now append-only: a URL is assigned to its subtype's newest chunk, or to a fresh chunk appended after it, and never to an earlier chunk that has room. Slots freed further down the range — a listing expired, was unpublished, or was deleted — are deliberately left as holes. Packing previously took the *lowest* chunk with room, which meant every freed slot anywhere in the range was refilled by the next new URL: the oldest files were rewritten whenever anything new arrived, moving their `<lastmod>` and forcing crawlers to re-fetch a file whose other entries had not changed, and no chunk could ever drain. On a catalogue of expiring listings the two policies differ sharply — append-only lets an early chunk shrink monotonically until it is tombstoned and its file removed, so a sub-sitemap retires whole. The cost is the intended trade: partly-filled chunks accumulate below the tail rather than being compacted away. Existing chunk membership is not rewritten; the new policy governs assignments from here on.

## [1.2.2] - 2026-08-17

### Fixed
- A product with no price emitted an Offer stating an empty one. `WC_Product::get_price()` returns `''` for anything with neither a regular nor a sale price — catalogue-only listings, "call for price" items, external and quote-driven products — and the Product node passed that empty string straight through as `offers.price`, beside a real `priceCurrency` and a real `availability`. A blank price is not read as "no price known": Search Console reads it as a malformed price and reports the page. The whole `offers` key is now omitted when there is no price to state, since a currency and a stock status with nothing to buy at are not an offer either. A priced product is unchanged.

## [1.2.1] - 2026-08-16

### Fixed
- On a site with a static front page, the home request resolved as `post:page` instead of `system_page:home`: a static front page is a real WordPress page, so it satisfies `is_singular()`, and that branch ran first — leaving the `system_page:home` title and description templates unreachable. The home title silently rendered through the `post:page` fallback (`%%title%% %%sep%% %%sitename%%`) as the front page's post title. The front-page/home branch now resolves before the singular one, the same priority custom pages already have over the singular branch and for the same reason.

## [1.2.0] - 2026-08-16

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

[Unreleased]: https://github.com/the-another/seo/compare/v1.5.0...HEAD
[1.5.0]: https://github.com/the-another/seo/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/the-another/seo/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/the-another/seo/compare/v1.2.2...v1.3.0
[1.2.2]: https://github.com/the-another/seo/compare/v1.2.1...v1.2.2
[1.2.1]: https://github.com/the-another/seo/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/the-another/seo/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/the-another/seo/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/the-another/seo/compare/v0.4.0...v1.0.0
[0.4.0]: https://github.com/the-another/seo/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/the-another/seo/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/the-another/seo/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/the-another/seo/releases/tag/v0.1.0

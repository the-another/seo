=== The Another SEO ===
Contributors: theanother, ziontrooper
Tags: seo, open graph, schema, sitemap, breadcrumbs
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Performance-first SEO for WordPress at catalog scale: templated titles/meta, Open Graph, Twitter Cards, Schema.org JSON-LD, breadcrumbs, sitemaps.

== Description ==

The Another SEO is built for WordPress installs with very large content catalogs. Instead of computing SEO output on every request, it maintains an indexable table — one row per public post, page, or term — and serves titles, meta tags, and sitemaps from it.

* **Template-driven titles and descriptions** — per-post-type templates with tokens like `%%title%%`, `%%excerpt%%`, `%%sep%%`, and `%%sitename%%`, with per-post overrides in the editor.
* **Open Graph and Twitter Cards** — social tags emitted on every managed page; WooCommerce products upgrade `og:type` to `product` with price and availability.
* **Schema.org JSON-LD** — a connected graph (WebSite, WebPage, Article, Product, BreadcrumbList) emitted as a single `application/ld+json` block.
* **Breadcrumbs** — a `the-another/seo-breadcrumbs` block plus a PHP template tag, backed by Schema.org BreadcrumbList markup.
* **Sitemaps at catalog scale** — chunked XML sitemap files written to disk and served statically, with a live root index at `/sitemap.xml`; core's `/wp-sitemap.xml` is disabled while the plugin serves its own tree.
* **Site verification** — Google Search Console, Bing, Yandex, Yahoo, and Meta domain verification by meta tag, plus virtually-served verification files for Google, Bing, and Yandex (no FTP, nothing written to disk).
* **Tracking snippets** — GA4, Google Tag Manager, and Meta Pixel, with filters for per-page secondary properties and consent gating.

The initial index backfill runs in background batches via Action Scheduler, so activation stays instant even on sites with millions of objects.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/the-another-seo` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Review templates and enabled post types under Settings → The Another SEO. The initial background index builds automatically after activation.

== External services ==

This plugin can load third-party scripts, but only when you configure them. With no IDs entered, the plugin contacts no external service.

**Google Analytics (GA4) and Google Tag Manager**

Loaded only when you enter a GA4 Measurement ID or a Tag Manager Container ID on the Webmaster Tools settings tab. These scripts are served from `googletagmanager.com` (`/gtag/js`, `/gtm.js`, `/ns.html`) and send your visitors' IP address, user agent, and the URL being viewed to Google, which uses them for analytics measurement.

Terms of service: https://policies.google.com/terms — Privacy policy: https://policies.google.com/privacy

**Meta Pixel**

Loaded only when you enter a Meta Pixel ID on the Webmaster Tools settings tab. The script is served from `connect.facebook.net` and sends your visitors' IP address, user agent, the URL being viewed, and any Meta cookies already present in the browser to Meta, which uses them for advertising measurement and ad targeting. A tracking image is also requested from `facebook.com/tr`.

Terms of service: https://www.facebook.com/legal/terms/businesstools — Privacy policy: https://www.facebook.com/privacy/policy/

Site verification meta tags and verification files contact no external service; a search engine fetches them from your site.

== Frequently Asked Questions ==

= Does this work alongside other SEO plugins? =

Running two SEO plugins that both emit titles, meta tags, and sitemaps is not recommended. Deactivate other SEO plugins first.

= Where do the sitemap files live? =

Chunked sitemap XML files are written to `wp-content/uploads/taseo-sitemaps/` and served statically; `/sitemap.xml` is the live root index.

= Does it require WooCommerce? =

No. WooCommerce is optional — when present, products get `og:type=product`, price/availability tags, and Product schema.

== Changelog ==


= 1.2.0 - 2026-08-16 =
* Add: `taseo_sitemap_xml` filter — every sitemap document served through PHP (the live root index and every chunk served through the WordPress fallback) passes through it just before echo, so a multi-domain plugin can transform the XML per request. The Another Multi-Brand Global Styles subscribes to rewrite canonical-host URLs to the Brand domain being browsed. With no subscribers, chunks keep streaming from disk exactly as before, and a subscriber returning a non-string is ignored rather than corrupting the document.
* Fix: Sitemap requests on a non-canonical host could be served the raw chunk file by the Apache static-serve rules, bypassing PHP — and therefore any `taseo_sitemap_xml` subscriber — entirely. The static block now only serves the canonical home host (www and apex forms, any port); every other domain the install answers on falls through to the WordPress fallback where the filter runs. Cross-host URLs in a sitemap violate the sitemaps.org same-host rule and are ignored by crawlers, so a Brand domain's sitemap was previously invisible to search engines.

= 1.1.0 - 2026-08-14 =
* Add: WP-CLI commands — `wp taseo rescan`, `wp taseo regenerate`, `wp taseo status`, and `wp taseo cleanup`. Rescan and regenerate dispatch the same background jobs the admin buttons do, and take `--wait` to drive the queue and block until it drains.
* Add: `wp taseo rescan --mode=permalink` runs the rebuild that fires `taseo_permalinks_rebuilt` when it finishes, which the admin button does not. That is the one that re-seeds integrations after a store base or permalink structure moves.
* Add: `wp taseo status` reports index progress and per-content-type sitemap file and link counts — the fastest way to see whether a content type is in the sitemap at all, and how much of it.
* Add: `wp taseo cleanup` removes indexable rows and sitemap files with nothing behind them: rows for deleted posts and terms, for post types and taxonomies no longer enabled, and for sitemap families no longer registered; objects indexed under two subtypes at once, which publishes one URL from two sitemap files; and XML files left by a removed or suspended chunk, which otherwise keep answering 200 forever. It deletes by default — `--dry-run` reports the same counts without touching anything, and `--only=<rows|duplicates|files>` scopes a run.
* Add: Cleanup refuses to run when a plugin that owns existing rows looks inactive — no sitemap families registered while pushed URLs exist, or no post subtypes registered while rows carry one. Either means a provider was deactivated, not that thousands of rows became garbage. Sitemap files written in the last 15 minutes are also left alone, since a chunk being rebuilt is briefly indistinguishable from a leftover.
* Add: `--wait` reports what actually finished. A job chain that stops early on a failure leaves the queue quiet but the work incomplete, so both commands warn with the remaining progress or dirty-file count instead of claiming success.
* Refactor: The rule for whether a sitemap file is live now has a single owner shared by the sitemap index and cleanup. The two had drifted into independently written opposites of the same rule, which could have left a stale file served indefinitely.
* Refactor: The `{type}-sitemap-{n}.xml` naming pattern is defined once and referenced by the URL rewrite, the Apache rules, and the file reader, instead of being spelled out in three places.

= 1.0.0 - 2026-08-13 =
* Add: Per-domain site verification and tracking — a multi-brand site whose brands live on separate domains holds its own Google Search Console, Bing Webmaster Tools, Yandex, Yahoo, and Meta codes, its own verification files, and its own GA4 / Tag Manager / Meta Pixel IDs for each domain. Previously one set of codes was emitted on every domain, so only one could be verified at all. The Webmaster Tools tab gains a domain switcher; the site's own host is always the default and always first.
* Add: `taseo_verification_domains` filter — push a host to give it its own codes. Values are normalised and de-duplicated, and the site's own host can be neither removed nor reordered. The Another Multi-Brand Global Styles pushes every host from its published Brands' URL rules; with no subscribers the list is the site's own host and behaviour is unchanged.
* Add: Verification method selection — Google, Bing, and Yandex each verify by either a meta tag or a file, chosen per service and per domain. Each service has one input instead of two: paste the code, the file name, or the whole meta tag, and the plugin stores the bare token and derives the file name from it. Yahoo and Meta are unchanged; neither publishes a file method.
* Add: A verification value that does not fit the selected method's shape is reported rather than silently discarded — the save names the service whose value was dropped instead of reporting success over an emptied field, and each input's placeholder shows which shape its method expects.
* Add: Tracking IDs inherit the default domain when left blank, so brands sharing one analytics property need it entered once. Verification codes and files never inherit: a property is verified on its own, and inheriting would guarantee a silently failed verification instead of an obviously empty field.
* Refactor: Verification settings collapsed from two keys per service to one token plus a method. A one-time migration converts existing settings, including every per-domain record, and runs before any output. Bing and Yandex are lossless — both keys held the same token. Google is the exception, because its file method issues a credential unrelated to its meta tag: a site that had both keeps the file and loses the meta code, so its meta tag stops printing after the upgrade. A dismissible notice names every service and domain this happened to; re-add a code from Search Console to switch back to the meta tag.
* Refactor: Requests arriving on an unrecognised host — a staging alias, a bare IP, a load balancer — resolve to the default domain, which is what every host received before. Single-domain sites need no migration and see no output change.

= 0.4.0 - 2026-08-11 =
* Add: Post subtypes — one post type can be split into several SEO subtypes via the `taseo_post_subtypes` and `taseo_post_subtype` filters, each with its own templates, schema type, and sitemap family. A store keeping auctions, catalogue items, and merchandise in a single `product` post type can now treat them as three things.
* Add: `taseo_schema_graph` filter over the finished JSON-LD `@graph`, applied last, for contributing images, extra nodes, or corrections.
* Add: `taseo_template_variable_values` filter, the counterpart to `taseo_template_variables` — declaring a `%%token%%` offered it, but nothing could supply its value outside custom pages.
* Add: `taseo_sync_post()` — a public entry point for importers that write posts with direct database queries and so never fire `save_post`.
* Add: Sitemap include/exclude toggles now cover post subtypes and taxonomies, not just external URL families. Excluding one keeps its rows and per-object overrides, so re-including restores the URLs.
* Fix: A post that changes subtype no longer leaves its old row behind. The row was keyed by subtype, so the stale one kept its sitemap slot and the URL was published from two files at once.
* Fix: WooCommerce's own JSON-LD is suppressed for nodes this plugin emits, ending duplicate `Product` and `BreadcrumbList` markup on product pages. Its copies survive when schema is switched off, rather than leaving the page with nothing.
* Fix: A subtype inherits its owning post type's schema-type default, so splitting `product` no longer silently downgrades its subtypes from `Product` to `WebPage`.
* Fix: JSON-LD values are no longer HTML-encoded — titles reached the graph as `Jack Daniel&#8217;s`, which consumers render literally. HTML output is unchanged.

= 0.3.0 - 2026-08-07 =
* Add: Public sitemap push API — other plugins register URL families via the `taseo_sitemap_families` filter and push URLs with `taseo_sitemap_sync_url()`, `taseo_sitemap_delete_url()`, and `taseo_sitemap_delete_family()`.
* Add: Per-family include/exclude toggles on the Sitemap settings tab, with safe disable (files removed, membership kept) and background re-enable reconciliation.
* Add: Image sitemap tags — featured images for posts by default, arbitrary images via the `taseo_sitemap_images` filter and the push API, rendered under the Google image namespace.
* Add: Emptied sitemap chunks are tombstoned — removed from the sitemap index and answering `410 Gone` on direct requests, while URLs that never existed keep answering 404. A tombstoned chunk is reused and resurrected when its subtype gains URLs again.
* Refactor: All sitemap file I/O flows through a single storage seam that resolves the uploads location per call, so stream-wrapper offloads (e.g. S3) relocate sitemap files transparently; Apache static-serve rules are suppressed when uploads are stream-wrapped.

= 0.2.0 - 2026-07-27 =
* Add: Site verification meta tags for Google Search Console, Bing Webmaster Tools, Yandex, Yahoo, and Meta domain verification, printed on the front page only.
* Add: Verification files (`google<token>.html`, `BingSiteAuth.xml`, `yandex_<token>.html`) served with byte-exact bodies and nothing written to disk.
* Add: GA4, Google Tag Manager, and Meta Pixel snippets, all configured on a new Webmaster Tools settings tab.
* Add: Titles & Templates now offers only the variables that actually resolve for each content type, as clickable pills and a `%%` autocomplete, and rejects a template using one that cannot resolve there.
* Add: Variables show as labelled chips inside the template fields instead of raw `%%token%%` text; the stored value is unchanged.
* Add: Image fields are chosen through the WordPress media library instead of a hand-typed attachment ID, each with an optional image URL that overrides the chosen attachment.
* Add: Other plugins can register pages of their own for templating, and claim the request those pages appear on, using `add_filter()`.
* Add: Section navigation across the Titles & Templates tab.
* Add: Filters for verification tags, tracking IDs, consent gates, template variables, and image overrides.
* Fix: Saving a title or meta description template containing %%date%% silently corrupted it, storing %te%% instead.
* Fix: Saving settings returned you to the General tab instead of the tab you were on.

= 0.1.0 =
* Initial release: indexable table with background backfill, templated titles/meta, Open Graph and Twitter Cards, Schema.org JSON-LD graph, breadcrumbs block, chunked static sitemaps with live root index.

=== The Another SEO ===
Contributors: theanother, ziontrooper
Tags: seo, open graph, schema, sitemap, breadcrumbs
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 0.4.0
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

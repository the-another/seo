=== The Another SEO ===
Contributors: theanother, ziontrooper
Tags: seo, open graph, schema, sitemap, breadcrumbs
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 0.1.0
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

The initial index backfill runs in background batches via Action Scheduler, so activation stays instant even on sites with millions of objects.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/the-another-seo` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Review templates and enabled post types under Settings → The Another SEO. The initial background index builds automatically after activation.

== Frequently Asked Questions ==

= Does this work alongside other SEO plugins? =

Running two SEO plugins that both emit titles, meta tags, and sitemaps is not recommended. Deactivate other SEO plugins first.

= Where do the sitemap files live? =

Chunked sitemap XML files are written to `wp-content/uploads/taseo-sitemaps/` and served statically; `/sitemap.xml` is the live root index.

= Does it require WooCommerce? =

No. WooCommerce is optional — when present, products get `og:type=product`, price/availability tags, and Product schema.

== Changelog ==

= 0.1.0 =
* Initial release: indexable table with background backfill, templated titles/meta, Open Graph and Twitter Cards, Schema.org JSON-LD graph, breadcrumbs block, chunked static sitemaps with live root index.

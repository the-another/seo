# The Another SEO — Webmaster Verification & Analytics — Design

**Plugin slug:** `the-another-seo`
**Date:** 2026-07-25
**Status:** Draft — pending review

## Overview

Two related additions that share one settings tab and one release:

1. **Site verification** for Google Search Console, Bing Webmaster Tools, Yandex Webmaster, and Yahoo, by both methods a plugin can serve: the **HTML tag** (a `<meta>` in `<head>`) and the **HTML file** (a token-named file at the site root, served virtually — no file written to disk, no FTP). Method C (Google Analytics) is satisfied as a side effect of the analytics work below. DNS TXT verification is the one method out of reach — see [Out of scope](#out-of-scope).
2. **Analytics snippet output** — a GA4 Measurement ID and a Google Tag Manager Container ID, emitted through the WordPress script API, with a filter surface that lets developers add secondary tracking properties per page without any additional UI or database schema.

Neither feature touches the indexable table, the sitemap subsystem, or any existing output class. Both are additive: with empty settings the plugin's rendered output is byte-identical to today's.

## Decisions made during brainstorming

1. **"Google Search Console token/key" and the Google verification field are the same thing** — one field, labelled for Search Console. There is no separate credential, because the HTML-tag method *is* Search Console verification.
2. **Analytics snippets are in scope**, not deferred to a later spec.
3. **Both GA4 and GTM fields exist, and both emit when both are set** — with an inline warning about double-counted pageviews, since a GTM container commonly fires its own GA4 tag. The plugin does not silently drop a value the admin typed in.
4. **Secondary/per-page tracking is filter-based and programmatic** — no metabox field, no settings rules table, no new database column.
5. **Verification tags print on the front page only** — search engines read the tag at the property root, so printing ~200 bytes on every URL of a catalog-scale site buys nothing. A filter widens this for subdirectory-prefix properties.
6. **Two focused output services, not one combined class** — verification and analytics have different print conditions, different hooks, and different filter surfaces.
7. **HTML-file verification is in scope alongside the meta tags** — the plugin serves `google<token>.html`, `BingSiteAuth.xml`, and `yandex_<token>.html` virtually. This is the route for anyone who would otherwise be sent to their registrar, and it is the method some teams prefer because the token never appears in page source.

## Architecture

Two new hook-bearing services, registered in `Plugin::register_services()` and initialised in `Plugin::init_services()` exactly like the existing `SocialOutput` and `SchemaOutput`:

| Class | Hooks | Condition | Approx. size |
|---|---|---|---|
| `Verification/VerificationOutput` | `wp_head` (priority 1) | front page only | ~70 lines |
| `Verification/VerificationFileServer` | `template_redirect` (priority 0) | request path matches a configured filename | ~90 lines |
| `Analytics/AnalyticsOutput` | `wp_enqueue_scripts`, `wp_head`, `wp_body_open` | frontend, any URL | ~120 lines |

**Why verification is not folded into `MetaOutput`:** `MetaOutput::print_head_tags()` returns early when `CurrentContext::resolve()` yields `null`, and a blog-index front page has no indexable row. Verification tags must print regardless of context resolution, so sharing that method would mean either duplicating the guard or weakening it.

**Why file serving is a separate class from tag output:** it is a request handler, not a renderer. It terminates the request with `exit`, sets its own status and content-type headers, and never runs on a normal page load. Sharing a class with a `wp_head` printer would put a hard `exit` inside an output method — the kind of thing that makes every test around it awkward.

**Why analytics is not folded into verification:** different lifecycle. Verification is four static `<meta>` tags on one URL with no scripts; analytics is a `<head>` script plus a `<body>` `noscript` iframe on every URL, with its own enable condition and six-filter surface. Combining them produces one class with two unrelated print paths — the file most likely to sprawl at the next feature.

## Settings

Nine new keys in the existing `taseo_settings` option array. No new option, no new table, no migration.

**Meta-tag method** — four keys:

| Key | Meta name emitted | Stored format |
|---|---|---|
| `verify_google` | `google-site-verification` | `[A-Za-z0-9_-]` only |
| `verify_bing` | `msvalidate.01` | `[A-Za-z0-9_-]` only |
| `verify_yandex` | `yandex-verification` | `[A-Za-z0-9_-]` only |
| `verify_yahoo` | `y_key` | `[A-Za-z0-9_-]` only |

**File method** — three keys. Yahoo has none: it retired its own webmaster tools and its properties are verified through Bing.

| Key | Served at | Stored format |
|---|---|---|
| `verify_google_file` | `/google<token>.html` | `/^google[a-z0-9]+\.html$/` (full filename) |
| `verify_bing_file` | `/BingSiteAuth.xml` | `/^[A-Za-z0-9]+$/` (token only — filename is fixed) |
| `verify_yandex_file` | `/yandex_<token>.html` | `/^yandex_[a-z0-9]+\.html$/` (full filename) |

Google and Yandex name the file after the token, so the field takes the whole filename the service gave you and the token is derived from it — one value to paste, nothing to transcribe. Bing's filename is always `BingSiteAuth.xml`, so its field takes the token that goes inside.

**Analytics** — two keys:

| Key | Stored format |
|---|---|
| `analytics_ga4_id` | `/^G-[A-Z0-9]{4,}$/`, upper-cased |
| `analytics_gtm_id` | `/^GTM-[A-Z0-9]{4,}$/`, upper-cased |

New `Settings` getters, following the existing one-getter-per-key convention with defaults carried in the getter:

```php
public function get_verification_code( string $engine ): string;  // 'google'|'bing'|'yandex'|'yahoo', '' default
public function get_verification_file( string $engine ): string;  // 'google'|'bing'|'yandex', '' default
public function get_ga4_id(): string;                             // '' default
public function get_gtm_id(): string;                             // '' default
```

`get_verification_code()` maps an engine slug to its `verify_*` key and returns `''` for an unknown slug. The engine→meta-name mapping lives in `VerificationOutput`, not `Settings`: it is an output concern, and keeping it there means `Settings` stores four opaque strings.

### Admin UI

A seventh tab, `webmaster` ⇒ **Webmaster Tools**, added to `SettingsPage::TABS` and its `match` in `render_page()`, rendered by a new `render_webmaster_tab()` following the existing `printf`-into-`form-table` pattern. Two `<h2>` groups:

- **Site verification** — for each service, its meta-tag field and (where supported) its file field, side by side with a one-line description. Either method verifies on its own; a service with both filled emits both, which is harmless.
- **Analytics** — GA4 Measurement ID and GTM Container ID inputs, with `G-XXXXXXXXXX` / `GTM-XXXXXXX` placeholders.

Each configured verification file renders its live URL next to the field as a clickable link — `https://example.com/google1a2b3c4d.html`. The service is about to fetch exactly that URL, so letting the admin click it first turns "verification failed, why?" into a two-second check.

When both analytics IDs are set, the tab renders an inline `notice notice-warning` inside the Analytics group:

> Both a GA4 Measurement ID and a GTM Container ID are set. If your Tag Manager container already fires a GA4 tag, pageviews will be counted twice.

This is inline rather than a global `admin_notices` banner. The two existing global notices (conflicting SEO plugin, unwritable uploads directory) both signal something broken; this is a configuration caution that only makes sense while looking at the fields it describes.

### Sanitization

Handled in `SettingsPage::sanitize_settings()`, which is already public and unit-tested. The nine new keys do **not** join the existing shared text-key loop — that loop applies `sanitize_text_field()`, which would happily store a pasted `<meta>` tag, a malformed ID, or a filename containing a path. They get three dedicated `isset()`-guarded loops instead — verification codes, verification filenames, analytics IDs — each described below.

**Verification codes — paste tolerance.** Search Console, Bing, and Yandex all hand the user a complete tag:

```html
<meta name="google-site-verification" content="AbC123_-xyz" />
```

Users paste the whole thing. The sanitizer therefore:

1. If the raw value contains `<meta`, extracts the `content` attribute value via regex; otherwise takes the raw value.
2. Trims whitespace.
3. Strips every character outside `[A-Za-z0-9_-]`.

Step 3 is the security guarantee, not merely a tidiness pass: a stored verification code physically cannot contain a quote, angle bracket, or space, so it cannot break out of the `content="…"` attribute no matter what happens downstream. The character class covers all four services — Google's tokens are base64url-ish, Bing's are uppercase hex, Yandex's are hex, Yahoo's are alphanumeric.

**Verification filenames.** Trimmed, lower-cased for Google and Yandex (their tokens are lowercase hex), then matched against the per-service regex in the settings table. A value that fails is stored as `''`. The regexes are anchored and allow no dot, slash, or `..` beyond the single trailing extension, so a stored filename cannot describe anything but a root-level token file — worth stating explicitly because this value is compared against an incoming request path.

Bing's token is stored bare and its filename is a constant in the code, never user input.

**Analytics IDs.** Trimmed, upper-cased, matched against their regex. A value that fails is stored as `''` rather than kept, so a typo can never reach the enqueue path and emit a script tag pointing at a nonexistent property.

**Tab ownership.** The `webmaster` tab owns only text keys — no checkboxes or checkbox-lists — so it needs no entry in the tab-scoped force-set block at the end of `sanitize_settings()`. That block exists because an unchecked checkbox submits nothing and would otherwise be merge-preserved forever; a cleared text input still submits `''`, so the `isset()` guard sees it and clears the key.

### Targeted fix: preserve the active tab on save

`handle_save()` currently redirects to `options-general.php?page=taseo&updated=1` with no `tab` parameter, so saving from any tab returns the user to General. `handle_sitemap_regenerate()` already preserves its tab. Adding a seventh tab makes the inconsistency more visible, so `handle_save()` will append the posted, validated tab slug to its redirect. One-line change, covered by a `SettingsPageTest` assertion.

## Frontend output

### `Verification/VerificationOutput`

```php
public function init( HookManager $hook_manager ): void {
    $hook_manager->register_action( 'wp_head', array( $this, 'print_tags' ), 1 );
}
```

`print_tags()`:

1. Computes `$should_print = is_front_page() && ! is_paged()`, passed through `taseo_verification_should_print`.
2. Builds a name⇒code map from the four settings getters, dropping empty values.
3. Applies `taseo_verification_tags` to the map.
4. Re-validates the post-filter map: every meta name through `sanitize_key()`, every code stripped to `[A-Za-z0-9_-]` and dropped if the strip leaves it empty.
5. Prints one `<meta name="…" content="…" />` per surviving entry, `esc_attr` on both.

With all four fields empty and no filter, the method returns after step 2 having printed nothing. The `get_option()` call behind the getters is already primed by the rest of the plugin on the same request, so the cost is a hash lookup.

`! is_paged()` matters because `is_front_page()` is also true on `/page/2/`; verification belongs on the property root only.

### `Verification/VerificationFileServer`

```php
public function init( HookManager $hook_manager ): void {
    $hook_manager->register_action( 'template_redirect', array( $this, 'maybe_serve' ), 0, 0 );
}
```

**No rewrite rules.** `SitemapServer` registers rewrite rules because its URLs are patterned and it wants an Apache static-serving block. Verification files need neither: there are at most three of them, at fixed paths, hit a handful of times ever. Matching the request path on `template_redirect` avoids adding rules that would require a rewrite flush on upgrade — the plugin's `FLUSH_REWRITE_OPTION` machinery exists, but every flush it triggers rebuilds `.htaccess` on every site that updates, and this feature does not need to spend that.

The request reaches WordPress on its own: no such file exists on disk, so the standard WordPress `.htaccess` rule (or nginx `try_files`) routes it to `index.php`, WordPress resolves a 404, and `template_redirect` fires.

`maybe_serve()`:

1. Takes `wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH )`, strips the `home_url()` path prefix, and trims slashes. The prefix strip is what makes subdirectory installs work: a site at `/blog/` serves its token file at `/blog/google….html`, which is the root of its URL-prefix property.
2. Compares the result against the configured filenames — exactly, case-sensitively. Bing documents `BingSiteAuth.xml` as case-sensitive on some hosts, and an exact match is also what keeps this from becoming a pattern-matching surface.
3. On no match, returns immediately. This is the path taken by every 404 on the site, so it does nothing but a string compare against at most three values.
4. On a match: `status_header( 200 )`, `nocache_headers()`, `header( 'X-Robots-Tag: noindex' )`, the service's content type, then the body, then `exit`.

**Bodies are byte-exact.** Google's and Bing's documentation both warn that verification fails if the CMS injects extra whitespace, a BOM, or markup, and Yandex states the content must be "strictly the same" as issued. So each body is a single constant string built from the token, echoed with nothing before or after it:

| Service | Content-Type | Body |
|---|---|---|
| Google | `text/html; charset=UTF-8` | `google-site-verification: google<token>.html` |
| Bing | `application/xml; charset=UTF-8` | `<?xml version="1.0"?>\n<users>\n  <user><token></user>\n</users>` |
| Yandex | `text/html; charset=UTF-8` | `<html>\n<head>\n<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">\n</head>\n<body>Verification: <token></body>\n</html>` |

Running at `template_redirect` priority 0 puts this ahead of the theme and of `MetaOutput`, so nothing has printed yet. The plugin's own files carry no closing `?>` (enforced by the existing PHPCS ruleset), so no stray trailing newline can leak in from our side. A BOM introduced by *another* plugin's file is outside our control and is exactly the kind of thing the clickable URL next to the field surfaces in one click.

`maybe_serve()` takes the same `bool $do_exit = true` parameter the sitemap and admin-post handlers already use, so tests can drive it without killing the process — the established pattern in this codebase, registered with 0 accepted args for the same reason documented in `SettingsPage::init()`.

### `Analytics/AnalyticsOutput`

```php
public function init( HookManager $hook_manager ): void {
    $hook_manager->register_action( 'wp_enqueue_scripts', array( $this, 'enqueue_gtag' ) );
    $hook_manager->register_action( 'wp_head', array( $this, 'print_gtm_head' ), 1 );
    $hook_manager->register_action( 'wp_body_open', array( $this, 'print_gtm_body' ) );
}
```

All three callbacks share a guard: `! is_admin() && ! is_customize_preview()`, passed through `taseo_analytics_should_print`, then an emptiness check on the relevant ID list.

**GA4 — `enqueue_gtag()`**

```php
wp_enqueue_script( 'taseo-gtag', 'https://www.googletagmanager.com/gtag/js?id=' . $ids[0], array(), null, false );
wp_add_inline_script( 'taseo-gtag', $bootstrap . $config_lines, 'after' );
```

`$ver = null` suppresses WordPress's `?ver=` cache-buster, which Google's endpoint does not expect. `$in_footer = false` keeps the loader in `<head>` where gtag expects it. The inline script is the standard `dataLayer` bootstrap plus one `gtag('config', 'G-…', {…})` line per ID.

Multiple properties are native to gtag — one loader, N `config` calls — which is why filter-driven secondary tracking costs nothing structurally: the code path is identical for one ID or four.

Routing through `wp_enqueue_script`/`wp_add_inline_script` rather than echoing raw `<script>` markup keeps PHPCS and Plugin Check satisfied, and gives caching and script-deferral plugins a handle to reason about.

**GTM — `print_gtm_head()` / `print_gtm_body()`**

Head: the standard container loader, emitted per container ID via `wp_print_inline_script_tag()`, which routes through core's `wp_inline_script_attributes` filter so CSP-nonce plugins can attach a nonce.

Body: `<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=…" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>` on `wp_body_open`.

Themes predating WordPress 5.2 never fire `wp_body_open`. Those sites lose the no-JS fallback iframe while the head loader still works. This is accepted rather than worked around: the plugin already requires WordPress 6.9, so the only affected case is a theme that hardcodes `<body>` without the hook, and injecting into `the_content` or output-buffering the whole page to compensate would be far more invasive than the fallback is worth.

## Filter surface

The programmatic extension API, replacing what would otherwise be per-page UI:

| Filter | Value | Purpose |
|---|---|---|
| `taseo_verification_tags` | `array<string,string>` meta name ⇒ code | Add services beyond the four fields (Baidu, Pinterest, Norton) |
| `taseo_verification_should_print` | `bool` | Widen past the front page, e.g. subdirectory-prefix properties |
| `taseo_verification_files` | `array<string,array{content_type:string,body:string}>` filename ⇒ response | Serve additional token files (Ahrefs, Facebook domain verification, Pinterest) |
| `taseo_analytics_ga4_ids` | `array<int,string>` | Secondary tracking properties, per page |
| `taseo_analytics_gtm_ids` | `array<int,string>` | Secondary containers, per page |
| `taseo_analytics_gtag_config` | `array<string,array<string,mixed>>` keyed by measurement ID | Per-property gtag parameters |
| `taseo_analytics_should_print` | `bool` | Suppress entirely — consent gating, staging, logged-in exclusion |

**Secondary tracking**, the case that motivated this design:

```php
add_filter( 'taseo_analytics_ga4_ids', function ( array $ids ): array {
    if ( is_singular( 'landing_page' ) ) {
        $ids[] = 'G-CAMPAIGN99';
    }
    return $ids;
} );
```

**Per-property configuration:**

```php
add_filter( 'taseo_analytics_gtag_config', function ( array $config ): array {
    $config['G-CAMPAIGN99'] = array( 'send_page_view' => false );
    return $config;
} );
```

Config values are JSON-encoded with `wp_json_encode()` into the `gtag('config', …)` call, so arbitrary parameter values are safe without per-value escaping rules.

`taseo_verification_files` is the escape hatch for every service we don't have a field for. Its keys are run through the same filename regex shape and its content types through a small allow-list (`text/plain`, `text/html`, `application/xml`); a body is emitted verbatim, because byte-exactness is the whole point of the method and any transformation would break it. That makes this filter the one place in the design where a plugin can put arbitrary bytes at a URL on the site — deliberately, since that is precisely what "serve this verification file" means, and it requires the same code-level access as `add_action` itself.

**Everything returned from a filter is re-validated before output.** Filtered GA4 and GTM IDs go through the same regexes as stored ones and are dropped outright on failure; filtered verification codes go through the same character-class strip and are dropped only if the strip empties them; filtered meta names go through `sanitize_key()`. Failures are silent — a broken filter degrades that one entry, never the whole tag set. A filter is third-party code, and treating its return value as trusted would hand every other plugin on the site a script-injection path into `<head>` — the exact opposite of what a filter is for. ID lists are also de-duplicated, so a filter that appends the primary ID cannot double-count.

**Consent is deliberately a filter, not a feature.** `taseo_analytics_should_print` is the hook a cookie-consent plugin uses to gate output. Shipping our own consent UI would mean owning banner design, regional rule sets, and consent storage — a separate product, already solved by plugins the affected sites run.

## Error handling & edge cases

| Case | Behavior |
|---|---|
| No settings configured | Nothing enqueued, nothing printed, zero added bytes |
| Filter returns malformed IDs or codes | Dropped by re-validation; valid siblings still emit |
| Filter appends an already-present ID | De-duplicated |
| Both GA4 and GTM set | Both emit; inline warning in the tab |
| Theme lacks `wp_body_open` | Head loader works; `noscript` fallback absent |
| Paged front page (`/page/2/`) | No verification tags |
| Admin, customizer preview, REST, feeds | No analytics output |
| User pastes a full `<meta>` tag | `content` attribute extracted |
| Invalid GA4/GTM ID submitted | Not stored; field saves empty |
| No verification files configured | `maybe_serve()` returns after one string compare; 404 renders normally |
| Request path matches no configured file | Same — normal 404 |
| A real file with that name exists on disk | Webserver serves it; WordPress never runs. The admin's own upload wins |
| Subdirectory install (`/blog/`) | `home_url()` path stripped before matching, so the file serves at `/blog/<token>.html` |
| Filename submitted with a path (`../wp-config.php`) | Rejected at save by the anchored regex; never stored, never compared |

Pages carrying `noindex` still emit analytics — analytics measures traffic, not indexability, and suppressing it there would silently under-count real visits.

## WordPress.org compliance

Contacting a third-party service requires disclosure in `readme.txt` under the .org guidelines, and Plugin Check flags its absence. Because `scripts/tests/plugin-check.sh` is one of the four CI jobs, a missing disclosure fails the build — this is a hard requirement, not a nicety.

`readme.txt` gains an **External services** section stating:

- The plugin loads Google Analytics (`googletagmanager.com/gtag/js`) and/or Google Tag Manager (`googletagmanager.com/gtm.js`, `googletagmanager.com/ns.html`).
- These load **only** when the site owner enters a Measurement ID or Container ID; the plugin contacts nothing by default.
- What is transmitted: the visitor's IP address, user agent, and the URL being viewed, sent to Google by the loaded script.
- Links to Google's terms of service and privacy policy.

Verification meta tags need no disclosure — they are inert markup that contacts no one.

## Testing

### Unit (PHPUnit + Brain Monkey, joining the existing 193)

**`tests/Unit/Verification/VerificationOutputTest.php`**
- Prints all four tags on the front page.
- Prints nothing when not the front page, and nothing on a paged front page.
- Omits engines with empty codes; prints nothing when all four are empty.
- Escapes output and drops filter-injected values containing quotes or brackets.
- `taseo_verification_tags` can add an engine; `taseo_verification_should_print` can suppress and widen.

**`tests/Unit/Verification/VerificationFileServerTest.php`**
- Each service's body is byte-exact against a fixture string, including newlines and the absence of a trailing one.
- Correct content type and a 200 status header per service.
- `X-Robots-Tag: noindex` sent.
- A non-matching path returns without emitting anything.
- Nothing configured → returns without emitting anything.
- Subdirectory install: `home_url()` path prefix stripped before matching.
- Case sensitivity: `bingsiteauth.xml` does not match `BingSiteAuth.xml`.
- `taseo_verification_files` can add a file; a disallowed content type is rejected.

**`tests/Unit/Analytics/AnalyticsOutputTest.php`**
- Enqueues `taseo-gtag` with the correct URL, `null` version, and head placement.
- One `gtag('config', …)` line per ID, in order.
- `taseo_analytics_ga4_ids` additions appear; invalid additions are dropped; duplicates collapse.
- `taseo_analytics_gtag_config` parameters land in the right `config` call.
- GTM head loader and `wp_body_open` `noscript` both emit; neither emits when the container ID is empty.
- Nothing emits in admin or when `taseo_analytics_should_print` returns false.

**`tests/Unit/Settings/SettingsTest.php`** — the new getters, empty-string defaults, unknown engine slug returns `''` for both `get_verification_code()` and `get_verification_file()`.

**`tests/Unit/Admin/SettingsPageTest.php`** — full-`<meta>` paste extraction for each service; quote/bracket/space stripping; invalid GA4 and GTM IDs rejected; valid IDs upper-cased; verification filenames validated per service, with path-traversal and wrong-extension attempts rejected; the new tab renders; the double-set warning appears only when both IDs are set; `handle_save()` redirect preserves the tab.

### E2E (Playwright)

New `tests/e2e/functional/specs/webmaster.spec.ts`, provisioning settings through WP-CLI like the existing specs:

1. Front page contains all four verification `<meta>` tags with the configured values.
2. A single post contains none of them.
3. Front page contains a `<script src="…/gtag/js?id=G-…">` and a `gtag('config', 'G-…')` inline script.
4. Body contains the GTM `noscript` iframe for the configured container.
5. `GET /google<token>.html` returns 200 with the exact expected body and no surrounding markup — the assertion that catches theme or plugin output leaking into the response, which is the failure mode Google's docs warn about and the one unit tests cannot see.
6. `GET /BingSiteAuth.xml` returns 200 with the exact XML body.
7. `GET /google-wrong-token.html` returns 404.

### Scale

Roughly 40 new unit tests and 7 e2e assertions. Three new source files (~280 lines) plus edits to `Settings`, `SettingsPage`, `Plugin`, and `readme.txt`.

## Out of scope

- **Consent management UI** — `taseo_analytics_should_print` is the integration point.
- **Search Console API integration** (impressions, clicks, indexing status) — a separate project with an OAuth flow, quota handling, and its own admin surface.
- **Analytics platforms other than GA4 and GTM** — Matomo, Plausible, Fathom, Meta Pixel. The filter surface does not cover these; they would need their own fields and output paths.
- **Per-page tracking UI** — explicitly replaced by the filter surface.
- **Baidu, Pinterest, and other verification services as first-class fields** — reachable through `taseo_verification_tags` and `taseo_verification_files`.
- **DNS TXT verification** — see below.

### DNS TXT verification

A TXT record is served by the domain's authoritative nameservers. WordPress is not in that path and cannot answer a DNS query for its own domain, so no amount of plugin code produces one. The only implementation is an integration that calls a DNS provider's API — Cloudflare, Route 53, DigitalOcean — with a stored credential, which means encrypted credential storage, one client per provider, zone selection, and rollback when the record is written but verification still fails. That is its own design, not a section here.

This matters for one specific case, and it is worth stating plainly rather than leaving to be discovered: **Search Console Domain properties** (`example.com`, covering every subdomain and protocol) accept **only** DNS verification. Neither the meta tag nor the served file will verify one. **URL-prefix properties** (`https://example.com/`) accept the tag, the file, Google Analytics, and Tag Manager — all four of which this spec now delivers. Bing and Yandex both accept file and tag verification for ordinary site properties.

So: this feature covers every property type a plugin can reach, and the gap is exactly one — Search Console Domain properties — which is a DNS-provider integration whenever it's wanted.

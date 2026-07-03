# Dockerised Test/Build Toolchain Port Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Port the fully dockerised test/build toolchain (unit/e2e test grouping, Makefile, Docker images, Plugin Check suite, functional Playwright suite, release-zip pipeline, version scripts, CI workflow) from `../the-another-multi-brand-global-styles` into `the-another-seo`.

**Architecture:** Straight port + adapt. Infrastructure files are copied from the sibling source plugin (verbatim where possible, exact string adaptations where not) and this plugin's specifics are layered in: a `wp-scripts` JS build step in the zip pipeline, runtime `vendor/` (Action Scheduler), new `readme.txt`/`.distignore`, and five new plugin-specific Playwright specs. Everything runs inside two Docker images; both e2e suites test the packaged `-test` zip, never the source tree.

**Tech Stack:** Alpine 3.24.1 Docker images (php83, Composer, wp-cli + dist-archive-command v3.1.0, Node, Chromium), PHPUnit 11 + Brain Monkey, Playwright + `@wordpress/e2e-test-utils-playwright`, WordPress.org Plugin Check (WP-CLI runner), GitHub Actions.

**Approved spec:** `docs/superpowers/specs/2026-07-04-test-infra-port-design.md`

## Global Constraints

- Source plugin (copy FROM, never modify): `../the-another-multi-brand-global-styles` (absolute: `/Volumes/DevExtreme/Aucteeno/wp-plugins/the-another-multi-brand-global-styles`)
- Plugin slug / dirname / zip basename: `the-another-seo`; main file `the-another-seo.php`; version constant `THE_ANOTHER_SEO_VERSION`; current version `0.1.0`
- PHP `>=8.3`; WordPress `Requires at least: 6.9`; text domain `the-another-seo`
- Docker image names: `the-another-seo-runner:latest` (unit/tooling), `the-another-seo-e2e-runner:latest` (e2e)
- All Make targets run inside Docker; nothing requires host PHP/Node
- Both e2e suites consume `build/the-another-seo-test.zip`, built fresh each run
- `dist/` (built JS), `blocks/`, and `vendor/` (with `woocommerce/action-scheduler`) SHIP in the zip; tests/scripts/docs/Makefile/dev-config do not
- All version pins (Alpine 3.24.1, WP_VERSION=7.0, SQLITE_PLUGIN_VERSION=2.2.23, WP_CLI_SERVER_COMMAND_VERSION=v2.0.15, PCP_VERSION=2.0.0, dist-archive-command v3.1.0) stay identical to the source plugin
- Commit after every task; commit messages end with `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`

**Plugin facts used by the e2e specs (verified against `includes/`):**

- Custom tables: `{$wpdb->prefix}taseo_indexables`, `{$wpdb->prefix}taseo_sitemap_files`
- Settings page: `options-general.php?page=taseo`
- Head output on singular front-end pages (empty settings option is fully functional — no configuration needed): `<meta name="description">` (default template `%%excerpt%%` — only prints when non-empty), `<link rel="canonical">`, `<meta property="og:type|og:title|og:site_name">` (+ og:description/og:url when available), `<meta name="twitter:card" content="summary_large_image">`, `<meta name="twitter:title">`, and a `<script type="application/ld+json">` block (wp_head priorities 1/2/3)
- Breadcrumbs block: `the-another/seo-breadcrumbs` (block.json in `blocks/breadcrumbs/`, editorScript `file:../../dist/breadcrumbs/index.js`, render `file:./render.php`)
- Sitemap: `/sitemap.xml` root index served live via rewrite `^sitemap\.xml$`; chunk URLs match `^([a-z0-9_-]+)-sitemap-([0-9]+)\.xml$`; chunk files written to `wp-content/uploads/taseo-sitemaps/`
- Activation defers work: `Installer::activate()` only creates tables and sets `taseo_needs_backfill` + `taseo_needs_rewrite_flush` options. The NEXT request's `init` (including any wp-cli command — wp-cli fires init) dispatches the Action Scheduler backfill chain (hook `taseo_backfill_batch`, group `taseo`) and flushes rewrites. Draining the AS queue makes sitemap chunks deterministic.

---

### Task 1: Unit-runner Docker image + Makefile

**Files:**
- Create: `tests/Unit/Dockerfile` (verbatim copy)
- Create: `Makefile`
- Modify: `.gitignore`

**Interfaces:**
- Produces: `make docker-build`, `make install`, `make install-dev`, `make require`, `make update`, `make dump-autoload`, `make lint`, `make format`, `make test`, `make coverage`, `make release`, `make version-*`, `make all`, `make clean`, plus `make docker-build-e2e`, `make test-e2e`, `make check-plugin` (these last three depend on files created in Tasks 3–6 and will fail until then — that is expected). Later tasks invoke these targets verbatim.

- [ ] **Step 1: Copy the unit Dockerfile verbatim**

The source file contains no plugin-specific strings (verified) — copy it unchanged:

```bash
mkdir -p tests/Unit
cp ../the-another-multi-brand-global-styles/tests/Unit/Dockerfile tests/Unit/Dockerfile
```

- [ ] **Step 2: Write the Makefile**

Create `Makefile` with exactly this content (identical to the source plugin except the two image names; note Makefile recipes MUST be tab-indented):

```makefile
.PHONY: docker-build docker-build-e2e install install-dev require update dump-autoload lint format test coverage test-e2e release check-plugin version-patch version-minor version-major all clean

# Docker image names
DOCKER_IMAGE = the-another-seo-runner:latest
DOCKER_RUN = docker run --rm -v $(PWD):/app -w /app $(DOCKER_IMAGE)

# Separate, Chromium-capable image for the e2e/Plugin Check Make targets —
# kept apart from DOCKER_IMAGE so lint/test/release stay small and fast.
DOCKER_IMAGE_E2E = the-another-seo-e2e-runner:latest
# -e CI forwards the host's CI value (unset locally, "true" in GitHub
# Actions) into the container — playwright.config.ts's retries/timeout/
# forbidOnly all key off process.env.CI, so without this the container never
# sees it and CI silently runs with local (non-CI) settings.
DOCKER_RUN_E2E = docker run --rm -e CI -v $(PWD):/app -w /app $(DOCKER_IMAGE_E2E)

# Build the e2e Docker image (Dockerfile lives with the e2e suites; build
# context stays the repo root — the image copies no project files anyway)
docker-build-e2e:
	docker build -f tests/e2e/Dockerfile -t $(DOCKER_IMAGE_E2E) .

# Build Docker image (Dockerfile lives with the unit tests it primarily
# serves; also used for lint/release/version-bump tooling)
docker-build:
	docker build -f tests/Unit/Dockerfile -t $(DOCKER_IMAGE) .

# Install composer dependencies without dev dependencies (runs in Docker)
install: docker-build
	$(DOCKER_RUN) composer install --no-dev

# Install composer dependencies including dev (needed for lint/test; runs in Docker)
install-dev: docker-build
	$(DOCKER_RUN) composer install

# Require new composer package (runs in Docker)
# Usage: make require PACKAGE="vendor/package"
require: docker-build
	$(DOCKER_RUN) composer require $(PACKAGE)

# Update composer dependencies (runs in Docker)
update: docker-build
	$(DOCKER_RUN) composer update

# Dump autoloader without dev dependencies (runs in Docker)
dump-autoload: docker-build
	$(DOCKER_RUN) composer dump-autoload --no-dev --optimize

# Run PHPCS linter (runs in Docker)
lint: docker-build
	$(DOCKER_RUN) composer phpcs

# Format code using PHPCBF (WARNING: This MODIFIES source code, runs in Docker)
format: docker-build
	$(DOCKER_RUN) composer phpcbf

# Run PHPUnit tests (runs in Docker; clears the result cache first so stale
# ordering can never mask a failure)
test: docker-build
	$(DOCKER_RUN) sh -c "rm -rf .phpunit.cache && php ./vendor/bin/phpunit"

# Run PHPUnit with coverage (runs in Docker; loads xdebug only for this
# invocation, see tests/Unit/Dockerfile). Prints a per-file text report and
# writes Clover XML to build/coverage/ for tooling.
coverage: docker-build
	$(DOCKER_RUN) sh -c "rm -rf .phpunit.cache && mkdir -p build/coverage && php -dzend_extension=xdebug.so -dxdebug.mode=coverage ./vendor/bin/phpunit --coverage-text --coverage-clover=build/coverage/clover.xml"

# Package plugin for distribution: lint + test gates, then zip into build/
# (everything runs inside Docker). Note: the zip step reinstalls composer
# without dev dependencies, so run `make install-dev` before the next
# lint/test cycle.
release: install-dev lint test
	$(DOCKER_RUN) sh -c "npm install --no-audit --no-fund && npm run plugin-zip"

# Run the functional native-PHP + Playwright suite (activation, meta/social
# tags, schema, breadcrumbs, sitemap) inside Docker. Both this target and
# check-plugin below call the same shared script — see scripts/run-e2e.sh.
test-e2e: docker-build-e2e
	$(DOCKER_RUN_E2E) sh scripts/run-e2e.sh functional

# Build a throwaway release zip (labeled -test, never the real version —
# see scripts/version-zip.js's --label flag) and run WordPress.org's
# official Plugin Check against it in a fresh WordPress instance installed
# FROM that zip — catches packaging bugs (missing files, wrong autoloader)
# a source-directory mount would never surface. Entirely inside Docker via
# the same shared script as test-e2e.
check-plugin: docker-build-e2e
	$(DOCKER_RUN_E2E) sh scripts/run-e2e.sh plugin-check

# Bump version (package.json, composer.json, plugin header, VERSION constant,
# readme.txt stable tag + changelog stub, lock files) — runs in Docker, no
# git commit; review and commit the result yourself.
version-patch: docker-build
	$(DOCKER_RUN) sh -c "npm install --no-audit --no-fund && npm run version:patch"

version-minor: docker-build
	$(DOCKER_RUN) sh -c "npm install --no-audit --no-fund && npm run version:minor"

version-major: docker-build
	$(DOCKER_RUN) sh -c "npm install --no-audit --no-fund && npm run version:major"

# Run all: install-dev, lint, test (all in Docker)
all: install-dev lint test

# Clean vendor, node_modules, caches, and build output (dist/ is gitignored
# build output here — the zip pipeline rebuilds it fresh every run)
clean:
	rm -rf vendor/ node_modules/ build/ dist/ .phpunit.cache/
```

- [ ] **Step 3: Extend .gitignore**

Append these lines to `.gitignore` (current content: `vendor/`, `node_modules/`, `dist/`, `.phpunit.cache/`, `.superpowers/`):

```
build/
test-results/
playwright-report/
artifacts/
```

- [ ] **Step 4: Verify the image builds and existing suites still pass in Docker**

Run: `make docker-build`
Expected: image `the-another-seo-runner:latest` builds successfully (first build downloads packages; several minutes).

Run: `make install-dev && make lint && make test`
Expected: composer installs, PHPCS passes, PHPUnit runs the existing suite (tests still under `tests/` — that is fine at this point) with all tests passing, `OK` summary.

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/Dockerfile Makefile .gitignore
git commit -m "build: add dockerised unit-runner image and Makefile

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: Regroup unit tests into tests/Unit

**Files:**
- Move: `tests/Admin`, `tests/Breadcrumbs`, `tests/Database`, `tests/Indexable`, `tests/Meta`, `tests/Schema`, `tests/Settings`, `tests/Sitemap`, `tests/Social`, `tests/ContainerTest.php`, `tests/InstallerTest.php`, `tests/PluginTest.php`, `tests/bootstrap.php` → all into `tests/Unit/`
- Modify: `composer.json` (autoload-dev), `phpunit.xml.dist`, `tests/Unit/bootstrap.php`

**Interfaces:**
- Consumes: `make test`, `make install-dev` from Task 1
- Produces: unit suite lives at `tests/Unit/` with PSR-4 `TheAnother\Plugin\SEO\Tests\` → `tests/Unit/`; `phpunit.xml.dist` testsuite named `Unit`. Test-class namespaces are unchanged.

- [ ] **Step 1: Move the test files with git mv**

```bash
git mv tests/Admin tests/Breadcrumbs tests/Database tests/Indexable tests/Meta tests/Schema tests/Settings tests/Sitemap tests/Social tests/ContainerTest.php tests/InstallerTest.php tests/PluginTest.php tests/bootstrap.php tests/Unit/
```

(If `tests/Social/` is empty, `git mv` skips it — check with `ls tests/` afterwards and `mkdir -p tests/Unit/Social` is NOT needed; an empty dir carries no tests. `tests/Unit/Dockerfile` from Task 1 is already in place and unaffected.)

- [ ] **Step 2: Update composer.json autoload-dev**

In `composer.json`, change:

```json
	"autoload-dev": {
		"psr-4": {
			"TheAnother\\Plugin\\SEO\\Tests\\": "tests/"
		}
	},
```

to:

```json
	"autoload-dev": {
		"psr-4": {
			"TheAnother\\Plugin\\SEO\\Tests\\": "tests/Unit/"
		}
	},
```

- [ ] **Step 3: Update phpunit.xml.dist**

Change the bootstrap attribute and the testsuite block:

```xml
		 bootstrap="tests/Unit/bootstrap.php"
```

```xml
	<testsuites>
		<testsuite name="Unit">
			<directory>./tests/Unit</directory>
		</testsuite>
	</testsuites>
```

(Everything else in the file stays as is.)

- [ ] **Step 4: Fix the bootstrap's require paths**

In `tests/Unit/bootstrap.php`, the file is now one directory deeper. Change:

```php
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/vendor/brain/monkey/inc/patchwork-loader.php';
```

to:

```php
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once dirname( __DIR__, 2 ) . '/vendor/brain/monkey/inc/patchwork-loader.php';
```

Leave every other line of the bootstrap (ABSPATH stub, upgrade.php stub, WP shims) untouched. If any other `dirname( __DIR__ )` occurrences exist in the file, bump each to `dirname( __DIR__, 2 )` the same way.

- [ ] **Step 5: Regenerate the autoloader and verify the suite passes unchanged**

Run: `make install-dev`
Expected: composer regenerates autoload with the new tests path.

Run: `make test`
Expected: PHPUnit discovers the same number of tests as before the move (compare against Task 1 Step 4's count) and reports `OK`.

Run: `make lint`
Expected: PHPCS passes (paths in `.phpcs.xml.dist` don't reference `tests/` explicitly — verified).

- [ ] **Step 6: Commit**

```bash
git add -A tests/ composer.json phpunit.xml.dist
git commit -m "test: regroup PHPUnit suite under tests/Unit

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: Release-zip pipeline (readme.txt, .distignore, scripts, package.json, composer build)

**Files:**
- Create: `readme.txt`, `.distignore`, `scripts/dist-archive.sh`, `scripts/version-zip.js` (verbatim copy), `scripts/version-bump.js` (copy + 3 edits)
- Modify: `package.json`, `composer.json`

**Interfaces:**
- Consumes: `make release` from Task 1
- Produces: `npm run plugin-zip` → `build/the-another-seo-<version>.zip` + `build/the-another-seo.zip` (latest alias); `npm run plugin-zip:check` → `build/the-another-seo-test.zip` only; `npm run version:patch|minor|major`. Tasks 4–5 invoke `plugin-zip:check` via `scripts/run-e2e.sh`.

- [ ] **Step 1: Create readme.txt**

WordPress.org format; `Stable tag` and the `== Changelog ==` heading are load-bearing (parsed by `scripts/version-bump.js`), and Plugin Check requires the file. Exact content:

```
=== The Another SEO ===
Contributors: theanother, ziontrooper
Tags: seo, open graph, schema, sitemap, breadcrumbs
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Performance-first SEO for WordPress at catalog scale: templated titles and meta, Open Graph and Twitter Cards, Schema.org JSON-LD, breadcrumbs, and sitemaps.

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
```

- [ ] **Step 2: Create .distignore**

Adapted from the source plugin — differences: no `/coverage/`, no `/CLAUDE.md`/`/README.md`/`/CONTRIBUTORS.md`/`/CHANGELOG.md` lines for files this repo doesn't have (harmless either way, but keep only real ones), and crucially `/dist/` and `/blocks/` are NOT listed (they ship). Exact content:

```
# Source control / project metadata
/.git/
/.github/
/.idea/
/.vscode/
/.superpowers/
.DS_Store

# Development dependencies and caches
/node_modules/
/.phpunit.cache/

# Tests (tests/Unit PHPUnit + its Dockerfile, tests/e2e suites + their
# Dockerfile and Playwright config), docs, tooling
# (readme.txt is the shipped user doc)
/tests/
/docs/
/scripts/
/Makefile
/artifacts/
/test-results/
/playwright-report/

# Build output (zips must never nest a previous zip). NOTE: /dist/ is NOT
# ignored — it holds the built block JS the plugin loads at runtime; the
# plugin-zip pipeline rebuilds it fresh immediately before archiving.
/build/
*.zip

# Dev configuration — composer.json ships (family convention), the rest doesn't
/composer.lock
/package.json
/package-lock.json
/phpunit.xml.dist
/.phpcs.xml.dist
/.gitignore
/.distignore

# Composer's vendor/bin only holds dev binaries
/vendor/bin/

# NOTE: /vendor/ is intentionally NOT ignored. The release pipeline runs
# `composer build` (install --no-dev + optimized dump-autoload) immediately
# before archiving, so the shipped vendor/ holds the autoloader plus the
# runtime woocommerce/action-scheduler package the main plugin file requires.
```

- [ ] **Step 3: Create scripts/dist-archive.sh**

Adapted from the source plugin (slug only). Exact content:

```sh
#!/bin/sh
# Build the distribution zip from .distignore via wp-cli dist-archive.
#
# The tree is first staged without .git/node_modules/build to a temp dir:
# our pinned dist-archive-command v3.1.0 has a path-handling bug when the
# source contains a .git directory, and the staged copy also keeps those
# dirs out of the archive scanner entirely. (The pin exists because
# dist-archive-command v3.2.x requires wp-cli ^2.13, and the latest
# released wp-cli is 2.12 — see tests/Unit/Dockerfile.) .distignore is part of
# the staged tree, so all other exclusions still come from it.
set -e

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT

tar cf - --exclude='.git' --exclude='node_modules' --exclude='build' . | tar xf - -C "$STAGE"

wp dist-archive "$STAGE" "$(pwd)/the-another-seo.zip" \
	--plugin-dirname=the-another-seo --force --allow-root > /dev/null
```

- [ ] **Step 4: Copy version-zip.js verbatim; copy version-bump.js and adapt**

`version-zip.js` derives every name from `package.json`'s `name` field — no edits needed:

```bash
cp ../the-another-multi-brand-global-styles/scripts/version-zip.js scripts/version-zip.js
cp ../the-another-multi-brand-global-styles/scripts/version-bump.js scripts/version-bump.js
```

Then make exactly three edits in `scripts/version-bump.js`:

1. `const MAIN_PLUGIN_FILE = 'the-another-multi-brand-global-styles.php';` → `const MAIN_PLUGIN_FILE = 'the-another-seo.php';`
2. `const VERSION_CONSTANT_NAME = 'THE_ANOTHER_MULTI_BRAND_GLOBAL_STYLES_VERSION';` → `const VERSION_CONSTANT_NAME = 'THE_ANOTHER_SEO_VERSION';`
3. `const repo = 'https://github.com/theanother/the-another-multi-brand-global-styles';` → `const repo = 'https://github.com/theanother/the-another-seo';`

(The CHANGELOG.md block in that script is guarded by `fs.existsSync` — this repo has no CHANGELOG.md, so it self-skips. Leave the code in place.)

- [ ] **Step 5: Merge package.json**

Replace `package.json` with exactly this (existing `build`/`start`/`lint:js` scripts and `@wordpress/scripts` kept; `plugin-zip` gains the `npm run build` step the source plugin doesn't have; devDependency versions match the source plugin):

```json
{
	"name": "the-another-seo",
	"version": "0.1.0",
	"description": "Performance-first SEO for WordPress at catalog scale.",
	"author": "The Another",
	"license": "GPL-2.0-or-later",
	"homepage": "https://theanother.org/plugin/seo/",
	"scripts": {
		"build": "wp-scripts build blocks/breadcrumbs/index.js --output-path=dist/breadcrumbs",
		"start": "wp-scripts start blocks/breadcrumbs/index.js --output-path=dist/breadcrumbs",
		"lint:js": "wp-scripts lint-js blocks",
		"plugin-zip": "composer build && npm run build && sh scripts/dist-archive.sh && node scripts/version-zip.js",
		"plugin-zip:check": "composer build && npm run build && sh scripts/dist-archive.sh && node scripts/version-zip.js --label=test",
		"version": "node scripts/version-bump.js",
		"version:patch": "node scripts/version-bump.js patch",
		"version:minor": "node scripts/version-bump.js minor",
		"version:major": "node scripts/version-bump.js major",
		"test:e2e": "npx playwright test --config tests/e2e/functional/playwright.config.ts",
		"test:e2e:ui": "npx playwright test --config tests/e2e/functional/playwright.config.ts --ui",
		"check:plugin": "node tests/e2e/check-plugin/run-plugin-check.mjs"
	},
	"devDependencies": {
		"@playwright/test": "^1.59.1",
		"@wordpress/e2e-test-utils-playwright": "^1.43.0",
		"@wordpress/scripts": "^30.0.0"
	}
}
```

- [ ] **Step 6: Add the composer build script**

In `composer.json`'s `"scripts"` block, add the `build` entry:

```json
	"scripts": {
		"phpcs": "phpcs",
		"phpcbf": "phpcbf",
		"test": "phpunit",
		"build": [
			"@composer install -q --no-dev",
			"@composer dump-autoload -q --no-dev --optimize"
		]
	}
```

- [ ] **Step 7: Build a release and inspect the zip**

Run: `make release`
Expected: install-dev + lint + test gates pass, npm installs, `wp-scripts build` produces `dist/breadcrumbs/index.js`, and the run ends with `✓ Created the-another-seo-0.1.0.zip in build directory` and `✓ Updated the-another-seo.zip in build directory (latest version)`.

Run: `unzip -l build/the-another-seo-0.1.0.zip`
Expected present: `the-another-seo/the-another-seo.php`, `the-another-seo/readme.txt`, `the-another-seo/composer.json`, `the-another-seo/includes/Plugin.php`, `the-another-seo/blocks/breadcrumbs/block.json`, `the-another-seo/blocks/breadcrumbs/render.php`, `the-another-seo/dist/breadcrumbs/index.js`, `the-another-seo/dist/breadcrumbs/index.asset.php`, `the-another-seo/vendor/autoload.php`, `the-another-seo/vendor/woocommerce/action-scheduler/action-scheduler.php`.
Expected ABSENT: any path under `tests/`, `scripts/`, `docs/`, `node_modules/`, `vendor/bin/`, plus `Makefile`, `package.json`, `phpunit.xml.dist`, `.phpcs.xml.dist`, `.distignore`, `composer.lock`.

Run: `make install-dev`
(Restores dev vendor after the no-dev release build.)

- [ ] **Step 8: Commit**

```bash
git add readme.txt .distignore scripts/ package.json package-lock.json composer.json
git commit -m "build: add release-zip pipeline with JS build step, readme.txt, and version tooling

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: E2E Docker image, shared WP provisioning, run-e2e.sh

**Files:**
- Create: `tests/e2e/Dockerfile` (verbatim copy), `tests/e2e/lib/provision-wp.sh`, `scripts/run-e2e.sh`

**Interfaces:**
- Consumes: `make docker-build-e2e` (Task 1), `npm run plugin-zip:check` (Task 3)
- Produces: image `the-another-seo-e2e-runner:latest` with baked WP core at `/opt/wp-core`, SQLite drop-in at `/opt/sqlite-database-integration`, Plugin Check zip at `/opt/plugin-check.zip`, Chromium at `$CHROMIUM_EXECUTABLE_PATH`; shell function `provision_wp()` (sets `$WP_DIR`, honors `$WP_SITE_URL`); entrypoint `sh scripts/run-e2e.sh <functional|plugin-check>` used by both Make targets and Tasks 5–7.

- [ ] **Step 1: Copy the e2e Dockerfile verbatim**

The source file contains no plugin-specific strings (verified — all comments are generic):

```bash
mkdir -p tests/e2e/lib
cp ../the-another-multi-brand-global-styles/tests/e2e/Dockerfile tests/e2e/Dockerfile
```

- [ ] **Step 2: Create tests/e2e/lib/provision-wp.sh**

Adapted from the source (mktemp prefix + site title only). Exact content:

```sh
# Shared native-PHP WordPress provisioning for both e2e suites. POSIX sh,
# meant to be SOURCED (callers: functional/environment/serve-wp.sh,
# check-plugin/provision-pcp-wp.sh). Requires the tests/e2e/Dockerfile
# image: baked core at /opt/wp-core, SQLite drop-in at
# /opt/sqlite-database-integration.
#
# Contract: provision_wp() creates a fresh ephemeral install and sets
# WP_DIR. Ordering inside is load-bearing: the SQLite drop-in
# (wp-content/db.php) must exist before `wp core install`, or install
# tries to reach MySQL. Site URL comes from $WP_SITE_URL (default
# http://localhost:8881); admin credentials are exactly admin/password —
# @wordpress/e2e-test-utils-playwright RequestUtils' hardcoded defaults.

provision_wp() {
	# Fresh temp copy of the baked core: clean site every run.
	WP_DIR="$(mktemp -d /tmp/taseo-e2e-wp.XXXXXX)"
	cp -a /opt/wp-core/. "$WP_DIR"/

	# SQLite drop-in: plugin files first, then db.php generated from the
	# plugin's own db.copy template (its documented manual-install
	# procedure).
	cp -a /opt/sqlite-database-integration "$WP_DIR/wp-content/plugins/"
	sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$WP_DIR/wp-content/plugins/sqlite-database-integration#" \
		-e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
		"$WP_DIR/wp-content/plugins/sqlite-database-integration/db.copy" \
		> "$WP_DIR/wp-content/db.php"

	# DB credentials are dummies — the drop-in ignores them (hence
	# --skip-check).
	wp config create --path="$WP_DIR" --dbname=wordpress --dbuser=wordpress \
		--dbpass=wordpress --skip-check --allow-root
	# Ephemeral test instance: PHP errors straight onto the page is pure
	# upside (turns investigations into "check the screenshot").
	wp config set WP_DEBUG true --raw --path="$WP_DIR" --allow-root
	wp config set WP_DEBUG_DISPLAY true --raw --path="$WP_DIR" --allow-root

	wp core install --path="$WP_DIR" --url="${WP_SITE_URL:-http://localhost:8881}" \
		--title="TASEO E2E" --admin_user=admin --admin_password=password \
		--admin_email=admin@example.com --skip-email --allow-root
}
```

- [ ] **Step 3: Create scripts/run-e2e.sh**

Adapted from the source (zip basename only). Exact content:

```sh
#!/bin/sh
# Shared entrypoint for both e2e Make targets (test-e2e, check-plugin), run
# inside the e2e image (tests/e2e/Dockerfile). Keeping this logic in exactly
# one script — instead of duplicated across two Make recipes — is what
# guarantees the functional suite and the Plugin Check suite can never drift
# from what CI actually runs.
#
# Usage: sh scripts/run-e2e.sh <functional|plugin-check>
set -e

SUITE="$1"

if [ "$SUITE" != "functional" ] && [ "$SUITE" != "plugin-check" ]; then
	echo "Usage: run-e2e.sh <functional|plugin-check>" >&2
	exit 1
fi

npm ci --no-audit --no-fund

# Both suites test the SAME packaged artifact: build the -test zip fresh
# every run (a stale zip would silently test old code). `composer build`
# inside this pipeline (install --no-dev + optimized autoload) is also what
# provides vendor/ on fresh CI checkouts — no separate vendor bootstrap.
# Side effect: a local vendor/ is left in no-dev state afterwards
# (`make install-dev` restores dev tooling for lint/test).
rm -f build/the-another-seo-test.zip
npm run plugin-zip:check

if [ "$SUITE" = "functional" ]; then
	npx playwright test --config tests/e2e/functional/playwright.config.ts
else
	# No Playwright/browser here: Plugin Check runs via its WP-CLI runner —
	# see tests/e2e/check-plugin/run-plugin-check.mjs.
	node tests/e2e/check-plugin/run-plugin-check.mjs
fi
```

- [ ] **Step 4: Verify the e2e image builds**

Run: `make docker-build-e2e`
Expected: image `the-another-seo-e2e-runner:latest` builds (downloads WP core 7.0, SQLite drop-in, Plugin Check 2.0.0, Chromium — the longest build in this plan; ~5–10 minutes on first run).

- [ ] **Step 5: Commit**

```bash
git add tests/e2e/Dockerfile tests/e2e/lib/provision-wp.sh scripts/run-e2e.sh
git commit -m "test: add e2e Docker image, shared WP provisioning, and run-e2e entrypoint

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 5: Plugin Check suite

**Files:**
- Create: `tests/e2e/check-plugin/pcp-early-init-marker.php` (verbatim copy), `tests/e2e/check-plugin/provision-pcp-wp.sh`, `tests/e2e/check-plugin/run-plugin-check.mjs` (copy + 1 edit)
- Possibly modify: plugin source / `readme.txt` (fixing ERROR-level findings — see Step 4)

**Interfaces:**
- Consumes: `provision_wp()` (Task 4), `build/the-another-seo-test.zip` (built by run-e2e.sh), `/opt/plugin-check.zip` (baked into the image)
- Produces: passing `make check-plugin`; failure artifact `build/plugin-check-results.txt`

- [ ] **Step 1: Copy the marker and the runner; adapt the slug**

```bash
mkdir -p tests/e2e/check-plugin
cp ../the-another-multi-brand-global-styles/tests/e2e/check-plugin/pcp-early-init-marker.php tests/e2e/check-plugin/
cp ../the-another-multi-brand-global-styles/tests/e2e/check-plugin/run-plugin-check.mjs tests/e2e/check-plugin/
```

In `tests/e2e/check-plugin/run-plugin-check.mjs`, make exactly one edit:

`const PLUGIN_SLUG = 'the-another-multi-brand-global-styles';` → `const PLUGIN_SLUG = 'the-another-seo';`

(Everything else — `ZIP_PATH` and the stderr/stdout gating — derives from `PLUGIN_SLUG`. Verify with `grep -n 'multi-brand' tests/e2e/check-plugin/run-plugin-check.mjs` → no matches.)

- [ ] **Step 2: Create provision-pcp-wp.sh**

Adapted from the source (zip basename only). Exact content:

```sh
#!/bin/sh
# Provision the ephemeral WordPress for the Plugin Check suite: shared
# native-PHP provisioning (tests/e2e/lib/provision-wp.sh), then Plugin
# Check (baked, pinned — /opt/plugin-check.zip) installed BEFORE our -test
# zip: the reverse order broke PCP's activation with a persistent
# "database tables are unavailable" error (verified empirically in the
# source plugin's Playground-era suite; root cause never pinned). No server
# is started — PCP's WP-CLI runner makes no HTTP requests.
#
# Prints WP_DIR=<path> as its last line; run-plugin-check.mjs parses it.
set -e

REPO_ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"

. "$REPO_ROOT/tests/e2e/lib/provision-wp.sh"
provision_wp

wp plugin install /opt/plugin-check.zip --activate --path="$WP_DIR" --allow-root
wp plugin install "$REPO_ROOT/build/the-another-seo-test.zip" \
	--activate --path="$WP_DIR" --allow-root

echo "WP_DIR=$WP_DIR"
```

- [ ] **Step 3: Run the suite**

Run: `make check-plugin`
Expected on first run: the zip builds, WordPress provisions, both `wp plugin check` runs execute with `early_init=yes`, and the suite reports findings. WARNINGs alone → suite passes. ERROR-level findings → suite fails with `✗ Plugin Check found ERROR-level issues` and each finding listed as `[ERROR] file:line code — message`.

Also confirm the plugin ACTIVATES under the SQLite drop-in during provisioning (watch for `Plugin 'the-another-seo' activated.`). If activation fatals here, STOP and investigate before proceeding — this is the spec's flagged SQLite/dbDelta/Action Scheduler risk. Debug with the printed `WP_DIR` inside the container; the fix belongs in plugin code or provisioning, never in weakened gating.

- [ ] **Step 4: Fix ERROR-level findings until the suite passes**

For each `[ERROR]` finding: fix the underlying issue in plugin source or `readme.txt` (typical first-run categories: readme field problems, missing `Tested up to`, text-domain mismatches, escaping/sanitization sniffs, disallowed function usage). After each round of fixes:

Run: `make test && make lint` (fixes must not break unit tests or PHPCS)
Run: `make check-plugin`
Repeat until: `✓ Plugin Check suite passed.`

Do NOT suppress or filter findings in `run-plugin-check.mjs` — the runner's gating logic is part of the port and stays identical to the source plugin.

- [ ] **Step 5: Commit**

```bash
git add tests/e2e/check-plugin/ readme.txt includes/ the-another-seo.php
git commit -m "test: add WordPress.org Plugin Check suite (WP-CLI runner) and fix its findings

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

(Adjust the `git add` list to whatever files Step 4 actually touched; `git status` first.)

---

### Task 6: Functional Playwright suite — scaffolding + provisioning + activation spec

**Files:**
- Create: `tests/e2e/functional/playwright.config.ts` (verbatim copy), `tests/e2e/functional/environment/serve-wp.sh`, `tests/e2e/functional/setup/global-setup.ts` (verbatim copy), `tests/e2e/functional/setup/provision.setup.ts`, `tests/e2e/functional/support/helpers.ts`, `tests/e2e/functional/specs/activation.spec.ts`

**Interfaces:**
- Consumes: `provision_wp()` (Task 4), `build/the-another-seo-test.zip`, npm devDeps (Task 3)
- Produces: `make test-e2e` green with setup + activation. For Task 7's specs: helpers `createPost( requestUtils, { title, content, excerpt?, slug? } ): Promise<number>` and `createPage( requestUtils, { title, content, slug? } ): Promise<number>` (both return the created object's ID, status publish); provisioned fixtures — post slug `seo-target-post` (title `SEO Target Post`, excerpt `A deterministic excerpt for meta tags.`) and page slug `crumbs` (title `Crumbs`, content = breadcrumbs block comment). Provisioning is idempotent for `reuseExistingServer` re-runs.

- [ ] **Step 1: Copy the verbatim files**

Neither contains plugin-specific strings (verified):

```bash
mkdir -p tests/e2e/functional/environment tests/e2e/functional/setup tests/e2e/functional/support tests/e2e/functional/specs
cp ../the-another-multi-brand-global-styles/tests/e2e/functional/playwright.config.ts tests/e2e/functional/
cp ../the-another-multi-brand-global-styles/tests/e2e/functional/setup/global-setup.ts tests/e2e/functional/setup/
```

- [ ] **Step 2: Create environment/serve-wp.sh**

Adapted from the source: zip basename, plus one NEW block — draining the Action Scheduler backfill queue so indexable rows and sitemap chunk files exist deterministically before any spec runs. Exact content:

```sh
#!/bin/sh
# Boot a real, ephemeral WordPress for the functional e2e suite. Invoked by
# playwright.config.ts's webServer.command; requires the tests/e2e/Dockerfile
# image, including its baked wp-cli server-command package (the `wp server`
# subcommand this script execs). Provisioning (baked core, SQLite drop-in,
# config, install) lives in the shared tests/e2e/lib/provision-wp.sh — this
# script adds only the functional-suite specifics: the packaged -test zip,
# pretty permalinks, the plugin's deferred-backfill drain, and the server.
#
# Installation completes BEFORE the server binds the port — that ordering is
# what makes Playwright's plain webServer.url readiness check truthful.
set -e

PORT="${WP_E2E_PORT:-8881}"
REPO_ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"

ZIP="$REPO_ROOT/build/the-another-seo-test.zip"
if [ ! -f "$ZIP" ]; then
	echo "$ZIP missing — run via scripts/run-e2e.sh functional (or make test-e2e), which builds it" >&2
	exit 1
fi

WP_SITE_URL="http://localhost:$PORT"
. "$REPO_ROOT/tests/e2e/lib/provision-wp.sh"
provision_wp

# The same packaged artifact the check-plugin suite gates — never a
# file-by-file source copy, so packaging bugs (missing file, wrong
# autoloader, bad .distignore exclusion) fail functionally too. The zip's
# inner dirname is already the real slug (dist-archive's --plugin-dirname).
wp plugin install "$ZIP" --activate --path="$WP_DIR" --allow-root

# Pretty permalinks: the sitemap rewrites (^sitemap\.xml$ and the chunk
# pattern) need real path URLs. A direct option write via wp-cli (unlike the
# admin UI, it doesn't sanitize the structure based on server rewrite
# support); wp server's router handles the actual /pretty/paths at request
# time. These wp-cli invocations also fire init, which is where the plugin
# dispatches its activation-deferred backfill chain and flushes rewrites
# (Installer sets taseo_needs_backfill / taseo_needs_rewrite_flush flags;
# Plugin::start() consumes them on the next request — wp-cli counts).
wp rewrite structure '/%postname%/' --path="$WP_DIR" --allow-root
wp rewrite flush --path="$WP_DIR" --allow-root

# Drain the Action Scheduler queue: the initial indexable backfill runs as a
# chain of async taseo_backfill_batch actions (each batch re-enqueues the
# next). Draining it here makes indexable rows and static sitemap chunk
# files exist BEFORE any spec runs — otherwise the sitemap spec would race
# WP-cron. Bounded loop: each pass runs everything currently due; the chain
# for this tiny site is a handful of batches, 20 passes is generous.
i=0
while [ "$(wp action-scheduler list --status=pending --format=count --path="$WP_DIR" --allow-root)" != "0" ] && [ $i -lt 20 ]; do
	wp action-scheduler run --force --path="$WP_DIR" --allow-root
	i=$((i + 1))
done
if [ "$(wp action-scheduler list --status=pending --format=count --path="$WP_DIR" --allow-root)" != "0" ]; then
	echo "Action Scheduler queue did not drain after $i passes" >&2
	exit 1
fi

# Multiple built-in-server workers so WordPress's own loopback requests
# (cron spawn, site health) can't deadlock the single PHP process. The
# running server's output is spooled to a file rather than Playwright's
# console: php -S logs every request (Accepted/Closing/status lines), which
# drowns the test output. Boot-phase output above still reaches the console,
# and real PHP errors still surface on-page via WP_DEBUG_DISPLAY (and thus
# in failure screenshots); the spool file covers the rest if a run needs a
# post-mortem inside the container.
echo "TASEO e2e WordPress ready: serving $WP_DIR on port $PORT (server log: $WP_DIR/php-server.log)"
PHP_CLI_SERVER_WORKERS=6 exec wp server --host=0.0.0.0 --port="$PORT" \
	--path="$WP_DIR" --allow-root >>"$WP_DIR/php-server.log" 2>&1
```

- [ ] **Step 3: Create support/helpers.ts**

```ts
/**
 * Shared helpers for the functional e2e specs. All content creation goes
 * through the REST API via @wordpress/e2e-test-utils-playwright's
 * RequestUtils (authenticated as admin by the global setup's storage state).
 */

import type { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

interface CreatePostInput {
	title: string;
	content: string;
	excerpt?: string;
	slug?: string;
}

interface CreatePageInput {
	title: string;
	content: string;
	slug?: string;
}

export async function createPost(
	requestUtils: RequestUtils,
	{ title, content, excerpt, slug }: CreatePostInput
): Promise< number > {
	const post = await requestUtils.rest< { id: number } >( {
		method: 'POST',
		path: '/wp/v2/posts',
		data: { title, content, excerpt, slug, status: 'publish' },
	} );
	return post.id;
}

export async function createPage(
	requestUtils: RequestUtils,
	{ title, content, slug }: CreatePageInput
): Promise< number > {
	const page = await requestUtils.rest< { id: number } >( {
		method: 'POST',
		path: '/wp/v2/pages',
		data: { title, content, slug, status: 'publish' },
	} );
	return page.id;
}
```

- [ ] **Step 4: Create setup/provision.setup.ts**

Runs once in the `setup` Playwright project before every spec (the config's project dependency — copied verbatim in Step 1 — wires this). Content:

```ts
/**
 * Provisioning: seeds the deterministic front-end fixtures the specs
 * assert against. Saving content through REST fires the plugin's
 * save_post-hooked indexable sync, so rows exist immediately — no
 * Action Scheduler dependency here (the initial backfill was already
 * drained by environment/serve-wp.sh before the server started).
 */

import { test } from '@wordpress/e2e-test-utils-playwright';
import { createPost, createPage } from '../support/helpers';

test( 'provision: seo fixture content', async ( { requestUtils } ) => {
	test.setTimeout( 120_000 );

	// Idempotency guard: with reuseExistingServer a re-run hits an already
	// provisioned database where the slugs below exist.
	const existing = await requestUtils.rest< Array< { id: number } > >( {
		method: 'GET',
		path: '/wp/v2/posts',
		params: { slug: 'seo-target-post' },
	} );
	if ( existing.length > 0 ) {
		return;
	}

	// The meta/social/schema specs assert against this post. The excerpt is
	// what the default description template (%%excerpt%%) resolves to.
	await createPost( requestUtils, {
		title: 'SEO Target Post',
		slug: 'seo-target-post',
		content: '<p>Deterministic body content for the SEO e2e suite.</p>',
		excerpt: 'A deterministic excerpt for meta tags.',
	} );

	// The breadcrumbs spec renders the block through its server-side
	// render.php via ordinary front-end delivery.
	await createPage( requestUtils, {
		title: 'Crumbs',
		slug: 'crumbs',
		content: '<!-- wp:the-another/seo-breadcrumbs /-->',
	} );
} );
```

- [ ] **Step 5: Create specs/activation.spec.ts**

```ts
/**
 * Plugin activation and admin-surface smoke checks.
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'activation', () => {
	test( 'plugin is active', async ( { requestUtils } ) => {
		const plugins = await requestUtils.rest<
			Array< { plugin: string; status: string } >
		>( {
			method: 'GET',
			path: '/wp/v2/plugins',
		} );

		const ours = plugins.find( ( p ) =>
			p.plugin.includes( 'the-another-seo' )
		);
		expect( ours ).toBeDefined();
		expect( ours!.status ).toBe( 'active' );
	} );

	test( 'plugin can be deactivated and reactivated through wp-admin', async ( {
		page,
		requestUtils,
	} ) => {
		await requestUtils.deactivatePlugin( 'the-another-seo' );

		await page.goto( '/wp-admin/plugins.php' );
		const row = page.locator( 'tr[data-slug="the-another-seo"]' );
		await row.getByRole( 'link', { name: 'Activate' } ).click();

		await expect( page.locator( '#message.updated' ) ).toContainText(
			'activated'
		);

		const plugins = await requestUtils.rest<
			Array< { plugin: string; status: string } >
		>( {
			method: 'GET',
			path: '/wp/v2/plugins',
		} );
		const ours = plugins.find( ( p ) =>
			p.plugin.includes( 'the-another-seo' )
		);
		expect( ours ).toBeDefined();
		expect( ours!.status ).toBe( 'active' );
	} );

	test( 'frontend responds without fatals', async ( { page } ) => {
		const response = await page.goto( '/' );
		expect( response!.status() ).toBe( 200 );
		await expect( page.locator( 'body' ) ).not.toContainText(
			'Fatal error'
		);
	} );

	test( 'settings page renders', async ( { page } ) => {
		await page.goto( '/wp-admin/options-general.php?page=taseo' );
		await expect(
			page.getByRole( 'heading', { name: 'The Another SEO' } )
		).toBeVisible();
	} );
} );
```

Note for the implementer: if the settings page's `<h1>` text differs, check `includes/Admin/SettingsPage.php`'s `add_options_page()` call (line ~89) and match the real page title — adjust the heading assertion, not the page.

The deactivate/reactivate test also exercises the plugin's deactivation hook (Action Scheduler unscheduling + surgical rewrite-rule removal) — a real regression surface for this plugin.

- [ ] **Step 6: Run the functional suite**

Run: `make test-e2e`
Expected: zip builds, WordPress provisions and activates the plugin, the AS queue drains, the `setup` project seeds fixtures, and all 4 activation tests plus the provision test pass: `5 passed`.

If the run fails at provisioning or activation under SQLite, this is the spec's flagged risk — investigate inside the container (the `WP_DIR` path is printed; `docker run -it --rm -v $(pwd):/app -w /app the-another-seo-e2e-runner:latest sh` for a manual session) and fix in plugin code or provisioning.

- [ ] **Step 7: Commit**

```bash
git add tests/e2e/functional/
git commit -m "test: add functional Playwright e2e suite with provisioning and activation spec

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 7: Functional specs — meta/social, schema, breadcrumbs, sitemap

**Files:**
- Create: `tests/e2e/functional/specs/meta-social.spec.ts`, `tests/e2e/functional/specs/schema.spec.ts`, `tests/e2e/functional/specs/breadcrumbs.spec.ts`, `tests/e2e/functional/specs/sitemap.spec.ts`

**Interfaces:**
- Consumes: fixtures from Task 6's provision.setup.ts (post `seo-target-post` titled `SEO Target Post` with excerpt `A deterministic excerpt for meta tags.`; page `crumbs` containing the breadcrumbs block); site title `TASEO E2E` (set by provision-wp.sh); pretty permalinks `/%postname%/`
- Produces: full `make test-e2e` green

- [ ] **Step 1: Create specs/meta-social.spec.ts**

```ts
/**
 * Template-driven <title>/meta description and Open Graph / Twitter Card
 * tags on a managed singular front-end page.
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'meta and social tags', () => {
	test.beforeEach( async ( { page } ) => {
		await page.goto( '/seo-target-post/' );
	} );

	test( 'templated title and meta description', async ( { page } ) => {
		// Default title template: %%title%% %%sep%% %%sitename%%.
		await expect( page ).toHaveTitle( /SEO Target Post .+ TASEO E2E/ );

		// Default description template: %%excerpt%%.
		await expect(
			page.locator( 'meta[name="description"]' )
		).toHaveAttribute(
			'content',
			'A deterministic excerpt for meta tags.'
		);
	} );

	test( 'canonical URL points at the permalink', async ( { page } ) => {
		await expect(
			page.locator( 'link[rel="canonical"]' )
		).toHaveAttribute( 'href', /\/seo-target-post\/$/ );
	} );

	test( 'Open Graph tags', async ( { page } ) => {
		await expect(
			page.locator( 'meta[property="og:type"]' )
		).toHaveAttribute( 'content', 'website' );
		await expect(
			page.locator( 'meta[property="og:title"]' )
		).toHaveAttribute( 'content', /SEO Target Post/ );
		await expect(
			page.locator( 'meta[property="og:site_name"]' )
		).toHaveAttribute( 'content', 'TASEO E2E' );
		await expect(
			page.locator( 'meta[property="og:url"]' )
		).toHaveAttribute( 'content', /\/seo-target-post\/$/ );
	} );

	test( 'Twitter Card tags', async ( { page } ) => {
		await expect(
			page.locator( 'meta[name="twitter:card"]' )
		).toHaveAttribute( 'content', 'summary_large_image' );
		await expect(
			page.locator( 'meta[name="twitter:title"]' )
		).toHaveAttribute( 'content', /SEO Target Post/ );
	} );
} );
```

- [ ] **Step 2: Create specs/schema.spec.ts**

```ts
/**
 * Schema.org JSON-LD graph on a managed singular page.
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'schema.org JSON-LD', () => {
	test( 'ld+json block is present, parses, and contains a graph', async ( {
		page,
	} ) => {
		await page.goto( '/seo-target-post/' );

		const raw = await page
			.locator( 'script[type="application/ld+json"]' )
			.first()
			.textContent();
		expect( raw ).toBeTruthy();

		const data = JSON.parse( raw! );
		expect( data[ '@context' ] ).toBe( 'https://schema.org' );

		// The plugin emits a connected graph; posts default to Article.
		const graph = data[ '@graph' ] ?? [ data ];
		const types = graph.flatMap( ( node: { '@type': string | string[] } ) =>
			Array.isArray( node[ '@type' ] ) ? node[ '@type' ] : [ node[ '@type' ] ]
		);
		expect( types ).toContain( 'Article' );
	} );
} );
```

Note for the implementer: before finalizing assertions, view the real output once (`curl -s localhost:8881/seo-target-post/ | grep -A2 'ld+json'` inside the container, or a Playwright trace). If the graph shape differs (e.g. `@context` value, no `@graph` wrapper), match the actual emitted structure from `includes/Schema/SchemaGraph.php` — adjust the assertion, never skip the JSON.parse gate.

- [ ] **Step 3: Create specs/breadcrumbs.spec.ts**

```ts
/**
 * Breadcrumbs block server-side rendering on the front end.
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'breadcrumbs block', () => {
	test( 'renders a trail on a page containing the block', async ( {
		page,
	} ) => {
		await page.goto( '/crumbs/' );

		// render.php outputs a nav landmark with the trail; the current
		// page is the last crumb.
		const nav = page.locator( 'nav', { hasText: 'Crumbs' } ).first();
		await expect( nav ).toBeVisible();
		// Trail starts at Home.
		await expect( nav ).toContainText( 'Home' );
	} );
} );
```

Note for the implementer: check `blocks/breadcrumbs/render.php` for the actual wrapper element/class (e.g. a `nav` with an aria-label or a `.taseo-breadcrumbs` class) and use the most specific stable selector it provides; the two content assertions (current title + Home) stay.

- [ ] **Step 4: Create specs/sitemap.spec.ts**

```ts
/**
 * Sitemap tree: live root index at /sitemap.xml referencing chunk files
 * that themselves serve valid XML. serve-wp.sh drained the backfill queue
 * before the server started, so chunks for the seeded content exist.
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'sitemap', () => {
	test( 'root index responds with a sitemapindex', async ( { request } ) => {
		const res = await request.get( '/sitemap.xml' );
		expect( res.status() ).toBe( 200 );
		const body = await res.text();
		expect( body ).toContain( '<sitemapindex' );
		expect( body ).toContain( '-sitemap-' );
	} );

	test( 'first referenced chunk serves a urlset containing the seeded post', async ( {
		request,
	} ) => {
		const index = await ( await request.get( '/sitemap.xml' ) ).text();

		// Chunk URLs match ^([a-z0-9_-]+)-sitemap-([0-9]+)\.xml$ at site root.
		const loc = index.match( /<loc>([^<]+-sitemap-\d+\.xml)<\/loc>/ )?.[ 1 ];
		expect( loc ).toBeTruthy();

		const chunkPath = new URL( loc! ).pathname;
		const res = await request.get( chunkPath );
		expect( res.status() ).toBe( 200 );
		const body = await res.text();
		expect( body ).toContain( '<urlset' );

		// The post-type chunk must list the seeded post's pretty permalink.
		const postChunk = index.includes( 'post-sitemap-' );
		if ( postChunk ) {
			const postRes = await request.get( '/post-sitemap-1.xml' );
			expect( postRes.status() ).toBe( 200 );
			expect( await postRes.text() ).toContain( '/seo-target-post/' );
		}
	} );

	test( 'core wp-sitemap is disabled while the plugin serves its own', async ( {
		request,
	} ) => {
		const res = await request.get( '/wp-sitemap.xml' );
		expect( res.status() ).not.toBe( 200 );
	} );
} );
```

- [ ] **Step 5: Run the full functional suite**

Run: `make test-e2e`
Expected: all specs pass — provision + 4 activation + 4 meta-social + 1 schema + 1 breadcrumbs + 3 sitemap = `14 passed`. Iterate on any selector/shape mismatches per the implementer notes above (fix the assertion to the real plugin output; if the plugin output itself is wrong, that's a plugin bug — fix it and note it in the commit).

- [ ] **Step 6: Commit**

```bash
git add tests/e2e/functional/specs/
git commit -m "test: add e2e specs for meta/social tags, schema, breadcrumbs, and sitemap

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 8: CI workflow + final verification

**Files:**
- Create: `.github/workflows/e2e.yml`

**Interfaces:**
- Consumes: `make test-e2e`, `make check-plugin` (identical entrypoints locally and in CI)

- [ ] **Step 1: Create .github/workflows/e2e.yml**

Adapted from the source plugin (artifact names/comments only). Exact content:

```yaml
name: E2E and Plugin Check

on:
  pull_request:
    branches:
      - master
      - main
      - 'release/**'
  workflow_dispatch:

permissions:
  contents: read

jobs:
  test-e2e:
    name: Functional E2E
    runs-on: ubuntu-latest
    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Run functional e2e suite
        run: make test-e2e

      - name: Upload Playwright report on failure
        if: failure()
        uses: actions/upload-artifact@v4
        with:
          name: playwright-report-functional
          path: |
            playwright-report/
            test-results/
          retention-days: 7

  # Runs Plugin Check natively via wp-cli (no browser, no Playwright) —
  # all checks, including the 5 runtime ones. The WP-CLI runner is
  # upstream's only behat-tested path for runtime checks; see
  # tests/e2e/check-plugin/run-plugin-check.mjs for the early-init canary
  # that guards against silent runtime-check under-coverage.
  check-plugin:
    name: Plugin Check (PCP)
    runs-on: ubuntu-latest
    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Run Plugin Check suite
        run: make check-plugin

      - name: Upload Plugin Check results on failure
        if: failure()
        uses: actions/upload-artifact@v4
        with:
          name: plugin-check-results
          path: build/plugin-check-results.txt
          retention-days: 7
```

- [ ] **Step 2: Final full verification (spec acceptance criteria)**

Run each and confirm:

1. `make all` → install-dev + lint + test all green
2. `make test-e2e` → 14 passed
3. `make check-plugin` → `✓ Plugin Check suite passed.`
4. `make release` → `build/the-another-seo-0.1.0.zip` produced; spot-check with `unzip -l` (dist/, blocks/, vendor/woocommerce/action-scheduler present; tests/, scripts/ absent)
5. `make install-dev` (restore dev vendor after release's no-dev build)
6. `make coverage` → coverage report prints, `build/coverage/clover.xml` written
7. `make version-patch` → prints `✓ Bumped version to 0.1.1 (patch)` and the per-file update ticks (package.json, composer.json, the-another-seo.php header + `THE_ANOTHER_SEO_VERSION`, readme.txt stable tag + changelog stub, lock files). Then discard the throwaway bump — it was only a verification:

```bash
git checkout -- package.json package-lock.json composer.json composer.lock the-another-seo.php readme.txt
```

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/e2e.yml
git commit -m "ci: run functional e2e and Plugin Check suites on pull requests

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

# Design: Port dockerised test/build toolchain from multi-brand-global-styles

**Date:** 2026-07-04
**Status:** Awaiting user review

## Goal

Bring `the-another-seo` up to the same fully dockerised test/build infrastructure as
`the-another-multi-brand-global-styles` (the source plugin, a sibling repo at
`../the-another-multi-brand-global-styles`): unit/e2e test grouping, a Makefile with the
complete target set, two Docker images, the functional Playwright e2e suite, the
WordPress.org Plugin Check suite, the release-zip pipeline, version-bump tooling, and the
GitHub Actions e2e workflow.

**Approach chosen:** straight port + adapt. Files are copied from the source plugin and
adapted only where this plugin genuinely differs (names/slugs, the JS build step, the
runtime Composer dependency, missing supporting files). No shared-toolchain extraction;
the two plugins stay independently maintainable.

## Decisions made during brainstorming

- **Functional e2e scope:** core SEO surface — activation plus front-end output specs
  (meta/social tags, Schema JSON-LD, breadcrumbs, sitemap). Admin-flow specs are out of
  scope for this iteration.
- **CI:** yes — port `.github/workflows/e2e.yml` too.
- **Approach:** straight port + adapt (over shared-toolchain extraction or a minimal
  Makefile-only port).

## Section 1 — Test regrouping (`tests/` → `tests/Unit` + `tests/e2e`)

All existing PHPUnit test files move from `tests/<Domain>/` to `tests/Unit/<Domain>/`,
keeping their domain subfolders (Admin, Breadcrumbs, Database, Indexable, Meta, Schema,
Settings, Sitemap, Social). `tests/bootstrap.php` moves to `tests/Unit/bootstrap.php`.
Test-class namespaces stay `TheAnother\Plugin\SEO\Tests\...`.

Config updates:

- `composer.json`: `autoload-dev` PSR-4 path `tests/` → `tests/Unit/`.
- `phpunit.xml.dist`: bootstrap → `tests/Unit/bootstrap.php`; testsuite renamed to `Unit`
  pointing at `./tests/Unit` (matching the source plugin).
- `tests/Unit/bootstrap.php`: the two `dirname( __DIR__ )` require paths become
  `dirname( __DIR__, 2 )` (one directory deeper). All SEO-specific bootstrap extras
  (ABSPATH stub, wp-admin/upgrade.php stub, WP function shims) stay unchanged.

Nothing about the tests themselves changes; `make test` must pass identically after the
move.

## Section 2 — Docker images, Makefile, scripts, release pipeline

### Docker images

- `tests/Unit/Dockerfile` — verbatim copy from the source plugin: Alpine 3.24.1, php83
  toolchain, php83-pecl-xdebug (loaded per-invocation by `make coverage` only), Composer
  installed under php83, wp-cli phar + dist-archive-command pinned to v3.1.0, Node/npm.
- `tests/e2e/Dockerfile` — verbatim copy: same PHP stack plus baked WordPress core
  (`WP_VERSION` ARG), the official SQLite drop-in, wp-cli server-command (pinned), a
  pinned Plugin Check zip (`PCP_VERSION` ARG) at `/opt/plugin-check.zip`, Alpine
  Chromium (with `PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1` + `CHROMIUM_EXECUTABLE_PATH`), and
  the ffmpeg registry-path shim for Playwright video recording. All version ARG pins are
  kept identical to the source plugin.

Both images contain no project files; the repo is bind-mounted to `/app` at run time.

### Makefile

Copied with the full identical target set: `docker-build`, `docker-build-e2e`,
`install`, `install-dev`, `require`, `update`, `dump-autoload`, `lint`, `format`,
`test`, `coverage`, `test-e2e`, `check-plugin`, `release`, `version-patch`,
`version-minor`, `version-major`, `all`, `clean`. Adaptations:

- `DOCKER_IMAGE = the-another-seo-runner:latest`
- `DOCKER_IMAGE_E2E = the-another-seo-e2e-runner:latest`
- `DOCKER_RUN_E2E` keeps `-e CI` forwarding (Playwright config keys off `process.env.CI`).
- `clean` additionally removes `dist/` — it is gitignored build output here, and the
  zip pipeline rebuilds it fresh every run (a fresh CI checkout has no `dist/` at all).

### Scripts (`scripts/`)

Copied and adapted for slug `the-another-seo`, main file `the-another-seo.php`, and
version constant `THE_ANOTHER_SEO_VERSION`:

- `run-e2e.sh` — shared entrypoint for `test-e2e` and `check-plugin`; builds
  `build/the-another-seo-test.zip` fresh each run via `npm run plugin-zip:check`, then
  runs either the Playwright config or `run-plugin-check.mjs`.
- `dist-archive.sh` — stages the tree (excluding `.git`, `node_modules`, `build`) and
  runs `wp dist-archive` with `--plugin-dirname=the-another-seo`.
- `version-zip.js` — renames the archive to `the-another-seo-<version>.zip` (or
  `-test.zip` with `--label=test`), moving it into `build/`.
- `version-bump.js` — bumps `package.json`, `composer.json`, the plugin header,
  `THE_ANOTHER_SEO_VERSION`, and `readme.txt` (stable tag + changelog stub).

### JS build integration (this plugin's main deviation)

The source plugin has no JS build; this plugin builds `blocks/breadcrumbs/index.js` into
`dist/breadcrumbs/` via `@wordpress/scripts`. The zip pipeline therefore gains a build
step:

- `npm run plugin-zip` = `composer build && npm run build && sh scripts/dist-archive.sh
  && node scripts/version-zip.js`
- `npm run plugin-zip:check` = same with `--label=test`.

`dist/` is always freshly built before archiving so the zip can never ship stale JS.
Both Docker images already carry Node/npm, so this stays fully in-container.

### composer.json / package.json changes

- `composer.json`: add the `build` script:
  `["@composer install -q --no-dev", "@composer dump-autoload -q --no-dev --optimize"]`.
- `package.json`: keep existing `build`/`start`/`lint:js` scripts; add `plugin-zip`,
  `plugin-zip:check`, `version`, `version:patch|minor|major`, `test:e2e`,
  `test:e2e:ui`, `check:plugin`; add devDependencies `@playwright/test` and
  `@wordpress/e2e-test-utils-playwright` (same versions as the source plugin).

### vendor/ in the zip

As in the source plugin, `/vendor/` is deliberately NOT dist-ignored — the release
pipeline runs `composer build` immediately before archiving. Here this matters more:
`woocommerce/action-scheduler` is a runtime dependency, so the shipped `vendor/`
contains it plus the optimized autoloader (not autoloader-only as in the source plugin).

## Section 3 — E2E suites (`tests/e2e/`)

Both suites test the SAME packaged artifact (`build/the-another-seo-test.zip`), never
the source tree — packaging bugs (missing files, wrong autoloader, missing dist/) fail
the suites.

### Shared provisioning

- `tests/e2e/lib/provision-wp.sh` — copied; `provision_wp()` creates a fresh temp
  WordPress from the baked core with the SQLite drop-in, dummy DB config, WP_DEBUG on,
  admin/password credentials. Adaptations: mktemp prefix and site title only.

### Functional suite (`tests/e2e/functional/`)

Copied with slug/zip-name adaptations:

- `playwright.config.ts` — port 8881, storage-state auth, 1 worker, CI-keyed
  retries/timeouts/forbidOnly, `CHROMIUM_EXECUTABLE_PATH` launch override, webServer =
  `environment/serve-wp.sh`.
- `environment/serve-wp.sh` — provisions WordPress and installs/activates
  `build/the-another-seo-test.zip` before binding the port.
- `setup/global-setup.ts`, `setup/provision.setup.ts`, `support/helpers.ts` — copied,
  helper content adapted to this plugin.

New plugin-specific specs (core SEO surface):

1. `activation.spec.ts` — the packaged zip installs and activates cleanly; the custom
   tables (indexables, sitemap files) exist; no PHP errors on the front page.
2. `meta-social.spec.ts` — a front-end post/page renders the template-driven `<title>`
   and meta description, plus Open Graph and Twitter Card tags.
3. `schema.spec.ts` — the JSON-LD script tag is present and parses as valid JSON with
   the expected graph nodes.
4. `breadcrumbs.spec.ts` — breadcrumb output renders on a suitable front-end page.
5. `sitemap.spec.ts` — the sitemap index responds at its URL and references at least one
   chunk that also serves valid XML.

Exact assertions are defined in the implementation plan after inspecting the plugin's
runtime behavior in the provisioned environment.

### Plugin Check suite (`tests/e2e/check-plugin/`)

Copied verbatim apart from the slug:

- `run-plugin-check.mjs` — runs WordPress.org Plugin Check via its WP-CLI runner (not
  the wp-admin AJAX flow) against the `-test` zip in a fresh provisioned WordPress.
  Run 1 = full default check set; run 2 = the 5 runtime checks explicitly (early-init
  canary). ERROR-type findings gate; WARNINGs are reported. Structural failures (missing
  runs, `early_init=no`, fatals, wp-cli `Error:` lines, unparseable report lines,
  PHP problems raised by this plugin's own code) always gate.
- `provision-pcp-wp.sh`, `pcp-early-init-marker.php` — copied.
- Results are written to `build/plugin-check-results.txt` for CI artifact upload.

### Known risks

- **SQLite drop-in compatibility:** this plugin creates custom tables via dbDelta and
  bundles Action Scheduler (which creates its own tables). Both must work under the
  SQLite drop-in. If activation fails there, fix forward in provisioning (e.g. drop-in
  version bump) rather than weakening the suites; switching the e2e environment to MySQL
  is the last resort and would be its own design change.
- **First Plugin Check run:** the plugin has never been through Plugin Check. Fixing any
  ERROR-level findings it surfaces (readme requirements, headers, escaping, etc.) is in
  scope for this work — that is the point of the suite.

## Section 4 — Supporting files + CI

- `readme.txt` (new) — WordPress.org format modeled on the source plugin: plugin name
  header, `Stable tag: 0.1.0`, Requires at least 6.9 / Requires PHP 8.3 (matching the
  plugin header), short/long description from composer.json, and a `== Changelog ==`
  section compatible with `version-bump.js`'s stub insertion. Required by Plugin Check.
- `.distignore` (new) — the source plugin's list adapted: excludes `/.git/`,
  `/.github/`, IDE dirs, `/node_modules/`, `/.phpunit.cache/`, `/tests/`, `/docs/`,
  `/scripts/`, `/Makefile`, `/build/`, `*.zip`, lock/dev-config files, `/vendor/bin/`.
  Deviations from the source: `/dist/` and `/blocks/` are NOT excluded (block.json,
  render.php, and built JS must ship); `/vendor/` ships (runtime Action Scheduler).
- `.gitignore` — add `build/`, `test-results/`, `playwright-report/`, `artifacts/`.
- `.github/workflows/e2e.yml` (new) — copied: `test-e2e` job (runs `make test-e2e`,
  uploads `playwright-report/` + `test-results/` on failure) and `check-plugin` job
  (runs `make check-plugin`, uploads `build/plugin-check-results.txt` on failure), on
  pull requests to main/master/release branches + manual dispatch.

## Acceptance criteria

1. `make test` — the relocated unit suite passes in Docker.
2. `make lint` — PHPCS passes in Docker.
3. `make test-e2e` — the functional Playwright suite passes in Docker against the
   packaged `-test` zip.
4. `make check-plugin` — Plugin Check passes (no ERROR-level findings, early-init
   verified) in Docker.
5. `make release` — produces `build/the-another-seo-<version>.zip` containing `dist/`,
   `blocks/`, runtime `vendor/` (with Action Scheduler), and no dev files.
6. `make coverage`, `make version-patch` work as in the source plugin.
7. CI workflow runs the two e2e jobs on pull requests.

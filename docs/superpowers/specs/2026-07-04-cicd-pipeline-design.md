# CI/CD Pipeline — Portable CI + Release Workflow + deploy-plugin Skill — Design

**Date:** 2026-07-04
**Status:** Approved (design approved in-session)
**Branch:** `ci-cd-pipeline`

> **Supersedes** the CI-dependent sections of
> `docs/superpowers/specs/2026-07-04-deploy-plugin-skill-design.md`. That spec was
> written against the old CI (`e2e.yml`, base branch `main`, no git remote). This
> document is the source of truth for the deploy-plugin skill's CI references.

## Goal

Port the sibling plugin `the-another-multi-brand-global-styles` (MBGS) CI/CD
pipeline — PR #4 "Portable CI scripts + GitHub release workflow" plus its
deploy-plugin skill — into `the-another-seo`, adapting names, branch, and the
version constant. Three coupled outcomes:

1. **Portable CI** — replace the Docker/`make`-only CI (`e2e.yml`, two jobs) with
   shared, environment-agnostic shell scripts that run **identically** in local
   Docker and natively on GitHub `ubuntu-24.04` runners. PRs gain lint + unit
   coverage they never had in CI.
2. **Release workflow** — on every push to `master`, re-run the full gate, build
   the release zip, tag `v<version>`, and publish a GitHub Release with the zip.
3. **deploy-plugin skill** — a project-scoped Claude Code skill that preps a
   versioned release on the PR branch (gate, bump, changelog, push, monitor CI).

## Decisions (this session)

- **Delivery:** one branch (`ci-cd-pipeline`), one PR, implemented and verified
  as **staged commits** (foundation → CI → release+docs → skill).
- **Alpine → Ubuntu:** full parity with MBGS. Both Docker base images move
  `alpine:3.24.1` → `ubuntu:24.04`; this is what lets one setup script run
  identically in Docker and on the GH runner. The Alpine/musl workarounds are
  deleted.
- **Unified spec supersedes** the stale deploy-plugin spec (pointer note added
  atop the old file).
- **Sandbox env flag name:** `THE_ANOTHER_SEO_CHROMIUM_NO_SANDBOX` (consistent
  with the existing `THE_ANOTHER_SEO_VERSION` constant).

## Current state (verified)

- CI: only `.github/workflows/e2e.yml` — two jobs (Functional E2E, Plugin
  Check), run via `make` → Docker → Alpine images. No lint/unit/release in CI.
- Default branch: **`master`**. Remote `origin` **exists**:
  `git@github.com:the-another/seo.git` (GitHub repo `the-another/seo`).
- Version constant: `THE_ANOTHER_SEO_VERSION` in `the-another-seo.php`.
- `README.md`, `CONTRIBUTORS.md`, `CHANGELOG.md`: **do not exist**.
- `scripts/version-bump.js` `repo` constant = `https://github.com/theanother/the-another-seo`
  — **mismatch** with the real origin `the-another/seo`; CHANGELOG compare links
  would 404.
- Current version pins (in `tests/e2e/Dockerfile` ARGs, to migrate into setup
  scripts): `WP_VERSION=7.0`, `SQLITE_PLUGIN_VERSION=2.2.23`,
  `WP_CLI_SERVER_COMMAND_VERSION=v2.0.15`, `PCP_VERSION=2.0.0`,
  dist-archive-command `v3.1.0`.

## Component A — Portable scripts

```
scripts/
  setup/
    unit.sh          # toolchain for lint/unit/zip-build:
                     #   PHP 8.3 CLI + extensions (xdebug installed, disabled),
                     #   Composer, Node >= 24, wp-cli + dist-archive-command v3.1.0
    e2e.sh           # sources setup/unit.sh, then adds:
                     #   php8.3 sqlite3/pdo-sqlite/gd, unzip,
                     #   WP core 7.0            -> /opt/wp-core
                     #   SQLite drop-in 2.2.23  -> /opt/sqlite-database-integration
                     #   Plugin Check 2.0.0     -> /opt/plugin-check.zip
                     #   wp-cli server-command v2.0.15
                     #   npm ci + `npx playwright install --with-deps chromium ffmpeg`
  tests/
    lint.sh          # ensure dev composer deps, run `composer phpcs`
    unit.sh          # ensure dev composer deps, clear .phpunit.cache, run phpunit
    e2e.sh           # source lib/build-test-zip.sh, then playwright run
    plugin-check.sh  # source lib/build-test-zip.sh, then PCP CLI runner
    lib/
      build-test-zip.sh  # shared body of today's scripts/run-e2e.sh
                         # (npm ci, fresh -test zip via plugin-zip:check,
                         # editor-asset tripwire), sourced by e2e.sh + plugin-check.sh
```

`scripts/run-e2e.sh` is **removed**; its logic moves to
`scripts/tests/lib/build-test-zip.sh` + the two thin suite wrappers. All
references (Makefile, docs, error messages in provision scripts /
`run-plugin-check.mjs`) are updated.

### Setup-script contracts (ported verbatim from MBGS)

- **Setup scripts install toolchain only; test scripts install project deps and
  run.** Exception: `setup/e2e.sh` runs `npm ci` because `npx playwright install`
  needs the lockfile-pinned Playwright version; the later `npm ci` in the test
  scripts is then a fast no-op.
- **Idempotent** — a present-and-adequate tool is left alone (GH runners ship
  parts of the toolchain).
- **Root vs non-root:** scripts compute an `as_root` helper (`sudo` when non-root
  and sudo exists — the GH runner case; direct when root — the Docker case).
- **All version pins live in the setup scripts** as env-overridable defaults —
  the Dockerfile ARGs go away; the scripts become the single source of truth.
- **PHP:** install `php8.3-*` via apt (Ubuntu 24.04 native series). Fail loudly
  if `php -v` is not 8.3.x — this is why workflows pin `runs-on: ubuntu-24.04`.
- **xdebug:** installed but disabled (`phpdismod`) so lint/test stay fast;
  `make coverage` loads it explicitly per invocation, unchanged.
- **Node:** if missing or major < 24, install Node 24 via NodeSource apt repo;
  otherwise leave the environment's Node alone.

### Canonical artifact paths stay `/opt`

`/opt/wp-core`, `/opt/sqlite-database-integration`, `/opt/plugin-check.zip`
remain the contract in both environments, so `tests/e2e/lib/provision-wp.sh` and
`tests/e2e/check-plugin/provision-pcp-wp.sh` keep working unchanged (comments
updated to reference the setup script instead of "the image").

## Component B — Alpine → Ubuntu Docker images

Both Dockerfiles become thin wrappers over the setup scripts:

- `tests/Unit/Dockerfile`: `FROM ubuntu:24.04` → `COPY scripts/setup ...` →
  `RUN sh scripts/setup/unit.sh` → `WORKDIR /app`.
- `tests/e2e/Dockerfile`: `FROM ubuntu:24.04` → COPY `package.json`,
  `package-lock.json`, `scripts/setup` into a scratch dir →
  `RUN sh scripts/setup/e2e.sh && rm -rf node_modules` → `WORKDIR /app`.
  Playwright's Chromium/ffmpeg bake into `/root/.cache/ms-playwright` at build
  time; the scratch `node_modules` is discarded (the real one is created in the
  bind-mounted `/app` at run time).

Deleted along with Alpine: the musl-Chromium apk install,
`CHROMIUM_EXECUTABLE_PATH`, `PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD`, the ffmpeg
registry-path symlink hack, the php83-vs-distro composer pinning, and the
`memory_limit=-1` wp-cli extraction workaround (Ubuntu CLI php.ini defaults to
`memory_limit=-1`).

**Playwright sandbox:** Chromium refuses to run sandboxed as root, and the
Docker containers run as root. The e2e image sets
`ENV THE_ANOTHER_SEO_CHROMIUM_NO_SANDBOX=1`; `playwright.config.ts` passes
`args: ['--no-sandbox']` exactly when that variable is set. Host runs (macOS)
and GH runners (non-root) stay sandboxed. The old `CHROMIUM_EXECUTABLE_PATH`
branch in `playwright.config.ts` is removed together with the Alpine Chromium.

## Component C — Makefile

Targets keep names and semantics; recipes call the new scripts:

- `lint` → `$(DOCKER_RUN) sh scripts/tests/lint.sh`
- `test` → `$(DOCKER_RUN) sh scripts/tests/unit.sh` (cache-clear moves into the
  script so CI gets it too)
- `test-e2e` → `$(DOCKER_RUN_E2E) sh scripts/tests/e2e.sh`
- `check-plugin` → `$(DOCKER_RUN_E2E) sh scripts/tests/plugin-check.sh`

`release`, `version-*`, `coverage`, `install*` unchanged apart from comments.

## Component D — `.github/workflows/ci.yml` (replaces `e2e.yml`)

- Trigger: `pull_request` → `master`/`main`/`release/**`/`feature/**`, plus
  `workflow_dispatch`. `permissions: contents: read`.
- Four jobs on `ubuntu-24.04`, each: checkout → `sh scripts/setup/<x>.sh` →
  `sh scripts/tests/<y>.sh`:
  - `lint` (setup/unit.sh + tests/lint.sh)
  - `unit` (setup/unit.sh + tests/unit.sh) — new CI coverage
  - `test-e2e` (setup/e2e.sh + tests/e2e.sh) — keeps on-failure Playwright
    report / test-results artifact upload
  - `check-plugin` (setup/e2e.sh + tests/plugin-check.sh) — keeps on-failure
    `build/plugin-check-results.txt` artifact upload
- `e2e.yml` is deleted. **One-time follow-up:** branch-protection required-check
  names change (the two `e2e.yml` job names are replaced by the four above);
  update repo settings once after merge.

## Component E — `.github/workflows/release.yml` (new)

- Trigger: `push` to `master` + `workflow_dispatch`. `permissions: contents: write`.
- The same four gate jobs as `ci.yml` (duplicated inline; both files stay
  self-contained and tiny — each job is checkout + two script calls).
- `release` job, `needs: [lint, unit, test-e2e, check-plugin]`:
  1. Read version from `package.json` (`node -p`).
  2. Skip everything (with `::warning`) if tag `v<version>` already exists on
     origin — pushing to master without a version bump is a no-op release.
  3. `sh scripts/setup/unit.sh` (PHP/Composer/Node/wp-cli + dist-archive).
  4. `npm ci && npm run plugin-zip` → `build/the-another-seo-<version>.zip`.
  5. Create and push tag `v<version>`.
  6. `softprops/action-gh-release@v2` with `generate_release_notes: true` and
     the zip attached.

## Component F — Supporting docs + repo-URL fix

New files, modeled on MBGS's but written for this plugin:

- **`README.md`** — plugin-facing only (no dev guidelines): what the plugin does
  (indexable table at catalog scale, templated titles/meta, Open Graph/Twitter
  Cards, Schema.org JSON-LD, breadcrumbs block, chunked static sitemaps),
  requirements (WP 6.9+, PHP 8.3+), license, homepage; pointer lines to
  `readme.txt`, `CONTRIBUTORS.md`, `CHANGELOG.md`.
- **`CONTRIBUTORS.md`** — dev guidelines: maintainers, contact links, an
  architecture overview of `includes/` (Container/HookManager/Plugin + the
  domain dirs), and the full command reference (Make targets + the new
  `scripts/setup` + `scripts/tests` scripts).
- **`CHANGELOG.md`** — Keep a Changelog format with a "How releases are cut"
  preamble; an `[Unreleased]` section and a `[0.1.0] - 2026-07-02` initial entry;
  compare links against the corrected repo URL. Creating this file **activates**
  the currently-dormant CHANGELOG block in `scripts/version-bump.js`.
- **`.distignore`** gains `/README.md`, `/CONTRIBUTORS.md`, `/CHANGELOG.md` —
  dev docs must not ship in the release zip (`readme.txt` is the shipped user
  doc). A rebuilt zip must not contain them.
- **`scripts/version-bump.js`**: change the `repo` constant from
  `https://github.com/theanother/the-another-seo` to
  `https://github.com/the-another/seo` (match `origin`), so CHANGELOG compare
  links resolve.

## Component G — deploy-plugin skill

`.claude/skills/deploy-plugin/SKILL.md`, frontmatter:

```yaml
---
name: deploy-plugin
description: Use when preparing a plugin release — bumps version, updates changelog from PR, validates lock files, commits, pushes, and monitors CI
disable-model-invocation: true
argument-hint: "[patch|minor|major]"
---
```

Body mirrors MBGS/aucteeno structure, adapted to this repo's **new** reality:

- **Step 0 — Quality gate (full, local Docker):** `make all` → `make test-e2e` →
  `make check-plugin` → `make install-dev` (restore dev vendor). On any failure:
  stop, show the error, ask whether to fix; fix → re-run failing check → restart
  Step 0; if the fix fails, stop.
- **Pre-flight checks** (hard stops, skill never does these itself): remote
  exists (now passes — `origin` is set); current branch is **not `master`**;
  clean working tree; branch pushed; a PR exists (`gh pr view`) — else **hard
  stop, ask the user to create the PR first** (the skill never creates it).
- **Step 1 — Version type:** use the `[patch|minor|major]` arg if given, else ask
  (show current version from `package.json`).
- **Step 2 — Curate `[Unreleased]` in CHANGELOG.md, then `make version-<type>`.**
  Ordering is load-bearing: the bump promotes `[Unreleased]` into the dated
  entry, so notes must be curated first (from the PR body/title + `git log
  master..HEAD`).
- **Step 3 — readme.txt changelog from PR:** replace the `* Version bump`
  placeholder with a WordPress-format entry using category-prefixed bullets.
- **Step 4 — Validate lock files** (dockerised): `npm install --package-lock-only
  && composer validate --no-check-all`.
- **Step 5 — Re-verify:** `make all` only (bump touches metadata the e2e suites
  do not assert). One-fix-attempt rule.
- **Step 6 — Commit and push:** `chore: bump version to X.Y.Z, update changelog`.
- **Step 7 — Monitor CI:** the workflow is now **`CI`** (`.github/workflows/ci.yml`,
  **four** jobs: PHPCS, PHPUnit, Functional E2E, Plugin Check (PCP)). Monitor via
  `gh run list --branch <branch>` / `gh run view <id> --log-failed`. CI green →
  report the release is ready and the PR can be merged. **Note for the operator:**
  merging to `master` auto-triggers `release.yml`, which re-gates, builds the
  zip, tags `v<version>`, and publishes the GitHub Release — the skill's scope
  ends at "PR is green and mergeable"; merge and publish stay manual/automated
  outside it. CI fail → one fix attempt, commit, push, re-monitor; second failure
  → stop and show the user.

The pointer note is added atop
`docs/superpowers/specs/2026-07-04-deploy-plugin-skill-design.md`.

## Staged commit order

1. **Foundation:** portable scripts (`scripts/setup/*`, `scripts/tests/*`,
   `lib/build-test-zip.sh`; remove `run-e2e.sh`) + Ubuntu Dockerfiles + Makefile
   rewire + `playwright.config.ts` sandbox flag. Verify `make lint`, `make test`,
   `make test-e2e`, `make check-plugin`, and `npm run plugin-zip` all green.
2. **CI:** `ci.yml` replaces `e2e.yml`.
3. **Release + docs:** `release.yml` + version-bump repo-URL fix +
   `README.md`/`CONTRIBUTORS.md`/`CHANGELOG.md` + `.distignore`.
4. **Skill:** `.claude/skills/deploy-plugin/SKILL.md` + supersede note on the old
   deploy spec.

## Verification

1. `make lint`, `make test` — unchanged results via new Ubuntu unit image.
2. `make test-e2e`, `make check-plugin` — unchanged results via new Ubuntu e2e
   image (Playwright-downloaded Chromium instead of Alpine's).
3. `npm run plugin-zip` inside the unit image — zip builds, versioned name; the
   zip does **not** contain README/CONTRIBUTORS/CHANGELOG.
4. `make version-patch` on a scratch run promotes `[Unreleased]` and prints
   `✓ Updated CHANGELOG.md` (then revert) — proving the dormant block activates
   and the compare links use the corrected repo URL.
5. Push the branch, open a PR — `ci.yml`'s four native jobs pass.
6. After merge to master — `release.yml` gates pass; with an unbumped version the
   release job skips on the existing-tag check; on a bump it publishes
   `v<version>` with the zip attached.
7. Dry-run the skill: Step 0's gate runs green on the branch; pre-flight passes
   the now-present remote and stops correctly if no PR exists.

## Out of scope / follow-ups

- GH Actions caching (Playwright browsers, `/opt` artifacts, composer/npm) —
  measure first; add later if CI time hurts.
- wp.org SVN deployment — releases are GitHub-only.
- Reusable workflow (`workflow_call`) to dedupe the four gate jobs between
  `ci.yml` and `release.yml` — revisit if the duplication drifts.
- Branch-protection required-check name update after merge (repo settings).

## Risks

- **Runner image drift:** `ubuntu-24.04` pin keeps PHP at 8.3. When the plugin's
  PHP target moves, bump the runner label and Docker base together.
- **First native CI run** is the real test of provisioning portability; the PR
  that lands this change exercises `ci.yml` itself.
- **Root Chromium sandbox** asymmetry — `--no-sandbox` applies only in the Docker
  container (`THE_ANOTHER_SEO_CHROMIUM_NO_SANDBOX=1`); CI runs sandboxed as the
  non-root runner user. If e2e behavior ever differs between local Docker and CI,
  check this first.

# Design: /deploy-plugin skill (ported from aucteeno)

**Date:** 2026-07-04
**Status:** Awaiting user review

## Goal

Add a project-scoped Claude Code skill at `.claude/skills/deploy-plugin/SKILL.md` that
prepares a versioned release of the-another-seo on its PR branch, ported from the
aucteeno plugin's `deploy-plugin` skill
(`../aucteeno/.claude/skills/deploy-plugin/SKILL.md`) and adapted to this repo's
dockerised toolchain.

**Scope (user decision):** same as aucteeno — release-prep on the PR branch: quality
gate, version bump, changelog from the PR, push, monitor CI until green. Merging the PR
and any artifact publishing stay manual/separate. Approach: straight adapt of the
aucteeno SKILL.md structure, step for step.

## Decisions made during brainstorming

- **Scope:** identical to aucteeno (release-prep on PR branch; merge/publish manual).
- **Quality gate:** FULL local gate — `make all` + `make test-e2e` + `make check-plugin`
  before anything else (not just the fast unit/lint gate).
- **No-PR handling:** if no PR exists for the branch, HARD STOP and ask the user to
  create one before re-running. The skill never creates the PR itself.
- **Supporting docs (user addition):** the flow needs three new repo files — `README.md`
  (plugin description, no dev guidelines), `CONTRIBUTORS.md` (dev guidelines), and
  `CHANGELOG.md` (separate Keep-a-Changelog file). See "Supporting docs" below.
- **Rejected alternatives:** thin skill delegating to a `make deploy` target (changelog
  authoring is interactive and cannot live in make); extended flow with artifact
  publishing (out of scope per user).

## Supporting docs (new files, modeled on the sibling plugin's)

Three files, each modeled on its counterpart in `../the-another-multi-brand-global-styles`
but written for this plugin's actual feature set and toolchain:

- **`README.md`** — plugin description only, no dev guidelines: what the plugin does
  (indexable table at catalog scale, templated titles/meta, Open Graph/Twitter Cards,
  Schema.org JSON-LD, breadcrumbs block, chunked static sitemaps), requirements
  (WP 6.9+, PHP 8.3+), license, homepage; pointer lines to `readme.txt` (wp.org
  listing), `CONTRIBUTORS.md` (development), `CHANGELOG.md` (history).
- **`CONTRIBUTORS.md`** — dev guidelines: maintainers table (theanother, ziontrooper —
  mirroring readme.txt's `Contributors:` header), contact links, an architecture
  overview of `includes/` (Container/HookManager/Plugin plus the domain dirs: Admin,
  Blocks/Breadcrumbs, Database, Indexable, Meta, Schema, Settings, Sitemap, Social),
  and the full dockerised command reference (every Make target with a one-line
  description, mirroring the Makefile).
- **`CHANGELOG.md`** — Keep a Changelog format, with the sibling's "How releases are
  cut" preamble (notes go under `[Unreleased]`; `make version-*` promotes them into a
  dated entry and retargets compare links; readme.txt gets a separate stub to replace).
  Initial content: `[Unreleased]` documenting the test-infra port (dockerised
  Makefile/images, tests/Unit regrouping, e2e + Plugin Check suites, release pipeline,
  CI workflow, ABSPATH-guard fix) and a `[0.1.0] - 2026-07-02` "Initial release" entry;
  compare links against `https://github.com/theanother/the-another-seo` (matching the
  `repo` constant already in `scripts/version-bump.js` — its CHANGELOG.md block
  currently self-skips because the file is missing, and activates once this file
  exists).

**`.distignore` addition:** `/README.md`, `/CONTRIBUTORS.md`, `/CHANGELOG.md` are dev
docs and must NOT ship in the release zip (readme.txt is the shipped user doc) — same
convention as the sibling plugin.

## Skill file

`.claude/skills/deploy-plugin/SKILL.md`, frontmatter:

```yaml
---
name: deploy-plugin
description: Use when preparing a plugin release — bumps version, updates changelog from PR, validates lock files, commits, pushes, and monitors CI
disable-model-invocation: true
argument-hint: "[patch|minor|major]"
---
```

Body mirrors aucteeno's section structure: Step 0 quality gate → pre-flight checks →
Step 1 version type → Step 2 bump → Step 3 changelog → Step 4 lock validation → Step 5
re-verify → Step 6 commit+push → Step 7 monitor CI. All adaptations below.

## Step 0 — Quality gate (full, dockerised)

Run in this order; all must pass before anything else:

1. `make all` — install-dev + PHPCS + PHPUnit (fast failures first, ~3 min; 200 tests)
2. `make test-e2e` — 14 Playwright tests against the packaged -test zip
3. `make check-plugin` — WordPress.org Plugin Check, 0-errors gate
4. `make install-dev` — restore dev vendor (the e2e targets' zip build leaves `vendor/`
   in no-dev state; documented side effect of scripts/run-e2e.sh)

On any failure: stop immediately, show the exact error, and ask the user whether to
attempt a fix (aucteeno's protocol verbatim: fix → re-run the failing check → restart
Step 0 from the top; if the fix doesn't work, stop — do not proceed to pre-flight).

## Pre-flight checks

Aucteeno's decision graph with two adaptations: the default branch here is `main` (not
`master`), and a new FIRST check that a remote exists at all (this repo currently has
none, so the skill stops loudly at pre-flight until GitHub is wired up).

Checks, in order — any failure is a hard stop that tells the user what to do; the skill
never does these things itself:

1. `git remote -v` non-empty — else STOP: "add a git remote first"
2. `git branch --show-current` is not `main` — else STOP: "checkout a feature branch"
3. `git status --porcelain` empty — else STOP: "commit or stash first"
4. `git log @{u}..HEAD --oneline` empty — else STOP: "push first"
5. `gh pr view --json number,title,state -q '.number'` returns a PR number — else
   **HARD STOP: ask the user to create a PR for the branch before re-running the
   skill** (user decision: the skill must not create the PR)

## Step 1 — Version type

If the skill was invoked with a `[patch|minor|major]` argument, use it. Otherwise ask
(aucteeno wording), showing the current version read from `package.json`. Wait for the
answer; never assume.

## Step 2 — Curate CHANGELOG.md, then bump

Ordering is load-bearing: `make version-<type>` PROMOTES the `[Unreleased]` section of
`CHANGELOG.md` into the new dated release entry (scripts/version-bump.js), so the
release notes must be in `[Unreleased]` BEFORE the bump runs.

1. **Curate `[Unreleased]`:** derive release notes from the PR
   (`gh pr view --json body -q '.body'`, `--json title -q '.title'`) and
   `git log main..HEAD --oneline`; ensure `CHANGELOG.md`'s `[Unreleased]` section
   contains them under Keep-a-Changelog headings (`### Added` / `### Changed` /
   `### Fixed` / …). If `[Unreleased]` already has accurate notes, leave them.
2. **Bump:** `make version-<type>` (dockerised). Updates: `package.json`,
   `composer.json`, `the-another-seo.php` (header + `THE_ANOTHER_SEO_VERSION`
   constant), `readme.txt` (stable tag + `* Version bump` changelog stub),
   `CHANGELOG.md` ([Unreleased] → dated entry + fresh empty [Unreleased] + compare
   links), and syncs both lock files.

## Step 3 — readme.txt changelog from PR

Identical to aucteeno: replace the `* Version bump` placeholder in `readme.txt` with a
WordPress-format entry (`= X.Y.Z - YYYY-MM-DD =`) using category-prefixed bullets
(`Fix:`, `Add:`, `Refactor:`, `Docs:`, `Chore:`) — the same notes just promoted in
CHANGELOG.md, reformatted for the wp.org listing.

## Step 4 — Validate lock files

Dockerised equivalents of aucteeno's host commands, inside the runner image:

```bash
docker run --rm -v "$(pwd)":/app -w /app the-another-seo-runner:latest \
  sh -c "npm install --package-lock-only && composer validate --no-check-all"
```

If either fails, fix before proceeding.

## Step 5 — Re-verify

Re-run `make all` only. The bump touches version metadata that the e2e suites do not
assert, so the full e2e/Plugin Check gates from Step 0 are not repeated. Aucteeno's
one-fix-attempt rule applies: if lint or tests fail, attempt one fix; if it doesn't
work, stop and tell the user.

## Step 6 — Commit and push

```bash
git add -A
git commit -m "chore: bump version to X.Y.Z, update changelog"
git push
```

## Step 7 — Monitor CI

This repo's workflow is **"E2E and Plugin Check"** (`.github/workflows/e2e.yml`, two
jobs: Functional E2E, Plugin Check (PCP)). Monitor via:

```bash
gh run list --branch <branch> --limit 1 --json databaseId,status,conclusion
gh run view <run-id> --log-failed   # on failure
```

- CI passes → report the release is ready and the PR can be merged (merge is manual).
- CI fails → fetch failed logs, identify the error, attempt ONE fix, commit, push,
  re-monitor. If the second attempt fails, stop and show the user the error.

Known context for the implementer: CI currently runs only the two e2e jobs (no
unit/lint job — a tracked follow-up from the test-infra port), so Step 0's local gate
is the only unit/PHPCS gate in this flow. This is why the full local gate was chosen.

## Acceptance criteria

1. `.claude/skills/deploy-plugin/SKILL.md` exists with the frontmatter above and the
   adapted step content; `/deploy-plugin` appears as an invocable skill.
2. Every command in the skill is real and correctly spelled for this repo: Make targets
   exist in `Makefile`; the workflow name matches `.github/workflows/e2e.yml`; file
   names (`the-another-seo.php`, `readme.txt`, `CHANGELOG.md`) and the
   version-constant name match.
3. `README.md`, `CONTRIBUTORS.md`, `CHANGELOG.md` exist with the content scoped above;
   `.distignore` excludes all three; a rebuilt zip does not contain them.
4. `make version-patch` on a scratch run promotes `[Unreleased]` and prints
   `✓ Updated CHANGELOG.md` (then the bump is reverted) — proving the previously
   dormant CHANGELOG block activates.
5. Dry-run verification: Step 0's gate commands run green on the current branch, and
   pre-flight's remote check stops with the correct message in this remote-less repo.
6. No behavioral drift from aucteeno beyond the documented adaptations (dockerised
   commands, `main` base, full gate, remote check, hard-stop-ask on missing PR,
   CHANGELOG.md curation before bump).

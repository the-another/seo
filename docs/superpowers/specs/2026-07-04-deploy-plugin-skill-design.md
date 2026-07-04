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
- **Rejected alternatives:** thin skill delegating to a `make deploy` target (changelog
  authoring is interactive and cannot live in make); extended flow with artifact
  publishing (out of scope per user).

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

## Step 2 — Bump

`make version-<type>` (dockerised). Updates: `package.json`, `composer.json`,
`the-another-seo.php` (header + `THE_ANOTHER_SEO_VERSION` constant), `readme.txt`
(stable tag + `* Version bump` changelog stub), and syncs both lock files.

## Step 3 — Changelog from PR

Identical to aucteeno, with `main` as the base branch:

1. `gh pr view --json body -q '.body'` and `--json title -q '.title'`
2. `git log main..HEAD --oneline`
3. Write a WordPress readme.txt changelog entry (`= X.Y.Z - YYYY-MM-DD =`) with
   category-prefixed bullets (`Fix:`, `Add:`, `Refactor:`, `Docs:`, `Chore:`) sourced
   from the PR summary and commits
4. Replace the `* Version bump` placeholder in `readme.txt`

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
   names (`the-another-seo.php`, `readme.txt`) and the version-constant name match.
3. Dry-run verification: Step 0's gate commands run green on the current branch, and
   pre-flight's remote check stops with the correct message in this remote-less repo.
4. No behavioral drift from aucteeno beyond the documented adaptations (dockerised
   commands, `main` base, full gate, remote check, hard-stop-ask on missing PR).

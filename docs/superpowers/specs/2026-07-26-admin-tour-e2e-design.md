# The Another SEO — Admin Tour E2E — Design

**Plugin slug:** `the-another-seo`
**Date:** 2026-07-26
**Status:** Draft — pending review

## Overview

One long end-to-end test that walks every tab of the settings screen, changes a
field on each, saves, and verifies the change persisted. It serves two purposes at
once:

1. **A regression test for the save path across all seven tabs.** Today each tab's
   sanitizer is unit-tested and only the Webmaster Tools tab has an admin round-trip
   e2e test. A tab whose save silently drops its own fields — the exact class of bug
   the tab-preserving redirect fix addressed — would pass every existing test.
2. **A video walkthrough of the plugin's admin surface.** Playwright already records
   one video per test, so a single long test yields a single continuous recording
   rather than two dozen fragments. That recording is the artifact: a quick visual
   answer to "what does this plugin actually do?", extended as the plugin grows.

The second purpose is why this is deliberately one test rather than seven. Seven
tests would be better isolated and worse video.

## Decisions made during brainstorming

1. **Reset the whole instance before the tour, and run the tour last** — rather than
   restoring each field after changing it. A reset gives the recording a pristine
   starting point and removes any ordering obligation to clean up afterwards.
2. **Reset by database snapshot/restore**, not by resetting only the plugin's
   options. The tour gets a genuinely clean WordPress, not just a clean plugin.
3. **The snapshot is taken after login**, which is the constraint that determines
   where it lives — see below.
4. **The tour asserts, it does not merely walk.** Every tab verifies its own
   round-trip.

## The constraint that shapes everything

WordPress authenticates the browser's cookie against a session token stored in
`usermeta`. That token is created when `setup/global-setup.ts` logs in and writes
`artifacts/storage-states/admin.json`.

A database restored to its post-install state does not contain that token, so the
browser is silently logged out and every subsequent admin navigation redirects to
`wp-login.php`. **The snapshot must therefore be taken after the login, not after
provisioning.**

This rules out taking it in `environment/serve-wp.sh`, which finishes and execs the
server before `global-setup` ever runs. The snapshot has to be a setup *step*.

## Components

### 1. `WP_DIR` discovery — `environment/serve-wp.sh`

`provision_wp()` creates the WordPress root with `mktemp -d /tmp/taseo-e2e-wp.XXXXXX`
(`tests/e2e/lib/provision-wp.sh:18`). That path exists only inside the shell; the
Playwright test process, which needs it to find the database, cannot see it.

`serve-wp.sh` gains one line before its final `exec`, writing the path to a file the
test process can read:

```sh
printf '%s\n' "$WP_DIR" > "$REPO_ROOT/artifacts/e2e-wp-dir.txt"
```

`artifacts/` is already in both `.gitignore` (line 10) and `.distignore` (line 26),
and `global-setup.ts` already writes the storage state into it, so the directory
exists and is correctly excluded.

### 2. Snapshot — `setup/snapshot.setup.ts` (new)

A setup-project test file. Within a project Playwright runs files in path order, so
`setup/snapshot.setup.ts` runs after `setup/provision.setup.ts` — meaning the
snapshot captures WordPress installed, admin logged in, and fixture content seeded.

It reads `artifacts/e2e-wp-dir.txt`, then copies the whole SQLite directory:

```
$WP_DIR/wp-content/database/  →  $WP_DIR/.taseo-db-snapshot/
```

**The whole directory, not one file.** The SQLite drop-in may keep `-wal` and `-shm`
companions alongside its database; restoring the main file while stale journal files
remain would produce a corrupt or half-rolled-back state. Copying and restoring the
directory as a unit avoids the question entirely.

The snapshot lives inside `$WP_DIR` so it is removed with the temp directory when the
container exits, and under a dot-prefixed name outside `wp-content` so no WordPress
directory scan sees it.

### 3. The tour — `specs/zz-admin-tour.spec.ts` (new)

The `zz-` prefix is load-bearing: it sorts after every other spec file
(`webmaster.spec.ts` is currently last), and `playwright.config.ts` pins
`workers: 1` with `fullyParallel: false`, so file order is execution order.

**`beforeAll`** restores `.taseo-db-snapshot/` over `wp-content/database/`, then
navigates to the admin and **asserts the session is still valid**. That assertion
exists specifically so the failure mode named above announces itself: if someone
later reorders the setup files so the snapshot predates the login, this test fails
with "not authenticated after restore" instead of a confusing redirect loop.

**The tour proper** — one test, `test.setTimeout( 180_000 )`, since seven save
round-trips comfortably exceed the 30s default (60s in CI).

For each of the seven tabs, in the order they appear on screen:

| Tab slug | Field changed | Settings key |
|---|---|---|
| `general` | Title separator | `separator` |
| `types` | A post type checkbox | `enabled_post_types` |
| `templates` | A title template | `title_templates` |
| `social` | X / Twitter site handle | `twitter_site` |
| `schema` | Breadcrumb separator | `breadcrumb_separator` |
| `sitemap` | Links per file | `sitemap_max_links` |
| `webmaster` | Google verification code | `verify_google` |

Each tab performs the same five steps:

1. Navigate to `options-general.php?page=taseo&tab=<slug>`.
2. Assert the tab's nav item carries `nav-tab-active` — proving the tab actually
   switched rather than silently falling back to General.
3. Change the field to a distinctive value.
4. Submit, and assert the redirect lands back on `tab=<slug>`. This is the behaviour
   the tab-preserving redirect fix introduced; asserting it on all seven tabs is what
   makes this a regression test for that fix rather than a demo.
5. Reload and assert the new value is present in the field — persistence proven from
   storage, not echoed from the POST.

A 400 ms pause follows each save, so the recording plays at a speed a human can follow
rather than as a blur. It is a named constant — `VIDEO_PACING_MS` — with a comment
saying it exists for the video and not for stability. Without that comment the next
person to read it will assume it is masking a race, and either delete it or, worse,
add more of them elsewhere. Seven pauses add under three seconds to a suite that
currently runs in about 24.

### 4. CI artifact retention — `.github/workflows/ci.yml`

The e2e job currently uploads `playwright-report/` and `test-results/` only
`if: failure()` (lines 62-70). That is exactly backwards for this feature: the video
is wanted from *green* runs. The condition changes to `if: always()`.

## Error handling & edge cases

| Case | Behavior |
|---|---|
| `artifacts/e2e-wp-dir.txt` missing | Snapshot setup fails with a message naming the file and the script that writes it |
| Snapshot directory missing at restore time | `beforeAll` fails naming `snapshot.setup.ts` |
| Snapshot predates the login (setup files reordered) | The post-restore auth assertion fails with an explicit message |
| Playwright retries the tour in CI (`retries: 2`) | `beforeAll` re-runs, so each attempt restores the snapshot first and starts clean |
| A tab's save drops its own field | That tab's persistence assertion fails, naming the tab |
| A tab's save redirects to General | That tab's redirect assertion fails |

## Testing

The tour is itself a test, so "testing the test" means proving it can fail. During
implementation, each of the two structural assertions is verified by temporarily
breaking what it protects and confirming a red run:

- Point one tab's form at the wrong tab slug → its redirect assertion must fail.
- Restore the snapshot from a pre-login copy → the auth assertion must fail.

Both are checked and reverted; neither ships.

The existing suites must be unaffected: `make test-e2e` currently reports 24 passing
across seven spec files, and must report 25 across eight with every pre-existing test
still green.

## Out of scope

- **Front-end verification of the tour's changes.** The tour proves settings persist;
  whether each setting changes rendered output is already covered per-feature by the
  existing specs.
- **Narration, captions, or post-processing of the video.** Playwright's raw `.webm`
  is the artifact.
- **Publishing the video anywhere.** It is a CI artifact with the workflow's existing
  7-day retention; distributing it is a separate decision.
- **Resetting between individual tabs.** One reset before the tour is the point;
  per-tab resets would triple the runtime and break the continuity of the recording.

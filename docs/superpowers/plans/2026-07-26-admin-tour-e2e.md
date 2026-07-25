# Admin Tour E2E Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One long end-to-end test that walks all seven settings tabs, changes and saves a field on each, and verifies every change persisted — doubling as a single continuous video of the plugin's admin surface.

**Architecture:** The e2e suite shares one WordPress install across all specs, so the tour resets it first. A new setup step snapshots the SQLite database *after* login and content provisioning; the tour restores that snapshot in `beforeAll` and runs last, so nothing needs cleaning up afterwards. Playwright already records one video per test, so a single long test yields one continuous recording.

**Tech Stack:** Playwright + `@wordpress/e2e-test-utils-playwright`, TypeScript, Node `fs`/`path`, a POSIX `sh` provisioning script, and GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-07-26-admin-tour-e2e-design.md`

## Global Constraints

- **No production PHP may be modified.** This is a test-and-harness feature end to end. If a genuine plugin bug surfaces, stop and report it rather than fixing it — a PHP change here would bypass this plan's review.
- **No version bump.** Versioning belongs to a separate release PR.
- **TypeScript style** follows the existing specs in `tests/e2e/functional/specs/`: tab indentation, spaces inside parentheses and brackets (`page.locator( '#submit' )`), single quotes, `import type` for type-only imports.
- **Shell style** follows `tests/e2e/functional/environment/serve-wp.sh`: POSIX `sh`, `set -e` already in force, `"$VAR"` always quoted.
- **`make test-e2e` must be run in the FOREGROUND**, with the Bash tool's `timeout` set to `900000` ms. Check `docker ps` first and wait for any running container to exit — two runs collide on port 8881. Backgrounding this command has stalled agents twice on this branch.
- **Baseline:** `make test-e2e` currently reports **24 passed**. That total counts the
  `setup` project's provisioning test as well as the seven spec files, so this plan
  adds two to it: Task 1's snapshot setup step and Task 2's tour. It must end at
  **26 passed**, with every pre-existing test still green.
- **Do not weaken, delete, or relax any existing assertion.**
- **Commit style:** Conventional Commits, imperative mood.
- **Branch:** `feature/webmaster-verification` (already checked out; PR #2 is open against master).

---

## File Structure

**Created:**

| Path | Responsibility |
|---|---|
| `tests/e2e/functional/setup/snapshot.setup.ts` | Copies the live SQLite directory to a snapshot, once, after login and content provisioning. |
| `tests/e2e/functional/specs/zz-admin-tour.spec.ts` | Restores the snapshot, then tours all seven tabs asserting each save round-trips. |

**Modified:**

| Path | Change |
|---|---|
| `tests/e2e/functional/environment/serve-wp.sh` | Publishes `WP_DIR` to `artifacts/e2e-wp-dir.txt` so the test process can find the database. |
| `.github/workflows/ci.yml` | E2E artifact upload moves from `if: failure()` to `if: always()`, so the video survives green runs. |

**Two ordering facts the whole design rests on**, both already true in `tests/e2e/functional/playwright.config.ts`:

- `workers: 1` and `fullyParallel: false` — file order is execution order.
- The `default` project declares `dependencies: [ 'setup' ]`, so every `setup/**/*.setup.ts` file completes before any spec runs.

Within a project, Playwright runs files in path order. `provision.setup.ts` < `snapshot.setup.ts`, and `zz-admin-tour.spec.ts` sorts after `webmaster.spec.ts` (currently last).

---

### Task 1: Publish WP_DIR and snapshot the database

**Files:**
- Modify: `tests/e2e/functional/environment/serve-wp.sh` (near the end, before the final `echo`/`exec`)
- Create: `tests/e2e/functional/setup/snapshot.setup.ts`

**Interfaces:**
- Consumes: `provision_wp()` from `tests/e2e/lib/provision-wp.sh`, which sets `WP_DIR` to a `mktemp -d /tmp/taseo-e2e-wp.XXXXXX` path.
- Produces:
  - The file `artifacts/e2e-wp-dir.txt`, containing the absolute `WP_DIR` path and a trailing newline.
  - The directory `$WP_DIR/.taseo-db-snapshot/`, a copy of `$WP_DIR/wp-content/database/` taken after login and content provisioning. Task 2 restores from it.

**Why the snapshot is a setup step and not part of `serve-wp.sh`:** WordPress validates the browser's auth cookie against a session token in `usermeta`. That token is created by `setup/global-setup.ts` logging in — which happens after `serve-wp.sh` has already exec'd the server. A snapshot taken in the shell script would predate the login, and restoring it would silently log the browser out.

- [ ] **Step 1: Publish `WP_DIR` from the provisioning script**

In `tests/e2e/functional/environment/serve-wp.sh`, immediately **before** the final `echo "TASEO e2e WordPress ready: ..."` line, add:

```sh
# Publish WP_DIR for the Playwright process. provision_wp() mints it with
# mktemp, so nothing outside this shell can discover it, and
# setup/snapshot.setup.ts needs it to locate the SQLite database.
# artifacts/ is already gitignored (.gitignore) and excluded from the
# release zip (.distignore); global-setup.ts writes the admin storage
# state into the same directory.
mkdir -p "$REPO_ROOT/artifacts"
printf '%s\n' "$WP_DIR" > "$REPO_ROOT/artifacts/e2e-wp-dir.txt"
```

- [ ] **Step 2: Write the snapshot setup step**

Create `tests/e2e/functional/setup/snapshot.setup.ts`:

```typescript
/**
 * Snapshot the SQLite database so specs/zz-admin-tour.spec.ts can reset the
 * shared WordPress install before it tours the admin.
 *
 * ORDERING IS LOAD-BEARING. This file must run AFTER setup/global-setup.ts
 * has logged in, because WordPress validates the browser's auth cookie
 * against a session token stored in usermeta. A snapshot taken before that
 * login restores a database with no such token, and every admin navigation
 * in the tour would silently redirect to wp-login.php.
 *
 * Two things make that ordering hold today: globalSetup is config-level and
 * runs before any project, and within the setup project Playwright runs
 * files in path order, so provision.setup.ts (content fixtures) precedes
 * snapshot.setup.ts. If either ever changes, the tour's own
 * still-authenticated assertion is what will catch it.
 */

import * as fs from 'node:fs';
import * as path from 'node:path';
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

const ROOT = path.resolve( __dirname, '../../../..' );
const WP_DIR_FILE = path.join( ROOT, 'artifacts/e2e-wp-dir.txt' );

test( 'snapshot: database after login and content provisioning', async () => {
	expect(
		fs.existsSync( WP_DIR_FILE ),
		`${ WP_DIR_FILE } is missing — tests/e2e/functional/environment/serve-wp.sh writes it just before exec'ing the server`
	).toBe( true );

	const wpDir = fs.readFileSync( WP_DIR_FILE, 'utf8' ).trim();
	const live = path.join( wpDir, 'wp-content/database' );
	const snapshot = path.join( wpDir, '.taseo-db-snapshot' );

	expect(
		fs.existsSync( live ),
		`${ live } is missing — the SQLite drop-in keeps the database there`
	).toBe( true );

	// Copy the whole directory, never a single file: the SQLite drop-in may
	// keep -wal and -shm companions alongside the database, and restoring
	// the main file next to stale journals yields a corrupt or
	// half-rolled-back state.
	fs.rmSync( snapshot, { recursive: true, force: true } );
	fs.cpSync( live, snapshot, { recursive: true } );

	expect(
		fs.readdirSync( snapshot ).length,
		'snapshot directory should not be empty'
	).toBeGreaterThan( 0 );
} );
```

- [ ] **Step 3: Run the suite and verify the snapshot step passes**

Run in the FOREGROUND with `timeout: 900000`, after confirming `docker ps` shows no running e2e container:

```
make test-e2e
```

Expected: **25 passed** — the 24 pre-existing tests plus this new setup test. The setup project's tests count toward the total.

If it fails on the missing `artifacts/e2e-wp-dir.txt`, the `printf` line landed after the `exec` (which never returns) instead of before it.

- [ ] **Step 4: Commit**

```bash
git add tests/e2e/functional/environment/serve-wp.sh tests/e2e/functional/setup/snapshot.setup.ts
git commit -m "test: snapshot the e2e database after login for reset-before-tour"
```

---

### Task 2: The admin tour

**Files:**
- Create: `tests/e2e/functional/specs/zz-admin-tour.spec.ts`

**Interfaces:**
- Consumes: `artifacts/e2e-wp-dir.txt` and `$WP_DIR/.taseo-db-snapshot/`, both produced by Task 1.
- Produces: nothing other tasks consume.

**The exact field selectors**, read from `includes/Admin/SettingsPage.php` — use these verbatim:

| Tab slug | Field | Selector |
|---|---|---|
| `general` | Title separator | `input[name="taseo_settings[separator]"]` |
| `types` | "Pages" post type | `input[name="taseo_settings[enabled_post_types][]"][value="page"]` |
| `templates` | Post title template | `input[name="taseo_settings[title_templates][post:post]"]` |
| `social` | X / Twitter handle | `input[name="taseo_settings[twitter_site]"]` |
| `schema` | Breadcrumb separator | `input[name="taseo_settings[breadcrumb_separator]"]` |
| `sitemap` | Links per file | `input[name="taseo_settings[sitemap_max_links]"]` |
| `webmaster` | Google verification code | `input[name="taseo_settings[verify_google]"]` |

**One cross-tab interaction to respect.** The `templates` tab renders one row per *enabled* post type (`SettingsPage::render_templates_tab()` iterates `get_enabled_post_types()`). The `types` step runs before it and disables a post type — so it must disable **`page`**, never `post`, or the `templates` step's selector would no longer exist on the page. This is why the table above pairs `value="page"` with `post:post`.

- [ ] **Step 1: Write the tour**

Create `tests/e2e/functional/specs/zz-admin-tour.spec.ts`:

```typescript
/**
 * A guided tour of every settings tab: change a field, save, prove it stuck.
 *
 * This file earns its keep twice. As a test it covers the per-tab save path
 * end to end — each tab's own fields surviving its own save, and the
 * redirect landing back on the tab you saved from rather than bouncing to
 * General. As an artifact it produces a single continuous video of the
 * plugin's admin surface (playwright.config.ts sets video: 'on', and
 * Playwright records one video per test), which is why this is deliberately
 * one long test instead of seven short ones.
 *
 * It resets the shared WordPress install first and runs last. The zz- prefix
 * is load-bearing: playwright.config.ts pins workers: 1 and
 * fullyParallel: false, so path order is execution order, and this file must
 * sort after every spec that asserts against the seeded state. Because it
 * resets before itself rather than restoring after, it owes nothing to
 * whatever runs next.
 */

import * as fs from 'node:fs';
import * as path from 'node:path';
import type { Page } from '@playwright/test';
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

const ROOT = path.resolve( __dirname, '../../../..' );
const WP_DIR_FILE = path.join( ROOT, 'artifacts/e2e-wp-dir.txt' );

/**
 * Pause after each save so the recorded video plays at a speed a human can
 * follow instead of as a blur. This exists for the video and NOT for
 * stability — every assertion here waits on its own condition. Please don't
 * read it as a masked race and sprinkle more of them around.
 */
const VIDEO_PACING_MS = 400;

const tabUrl = ( slug: string ): string =>
	`/wp-admin/options-general.php?page=taseo&tab=${ slug }`;

/**
 * Restore the database captured by setup/snapshot.setup.ts.
 *
 * @return void
 */
function restoreDatabaseSnapshot(): void {
	if ( ! fs.existsSync( WP_DIR_FILE ) ) {
		throw new Error(
			`${ WP_DIR_FILE } is missing — environment/serve-wp.sh writes it before exec'ing the server`
		);
	}

	const wpDir = fs.readFileSync( WP_DIR_FILE, 'utf8' ).trim();
	const live = path.join( wpDir, 'wp-content/database' );
	const snapshot = path.join( wpDir, '.taseo-db-snapshot' );

	if ( ! fs.existsSync( snapshot ) ) {
		throw new Error(
			`${ snapshot } is missing — setup/snapshot.setup.ts should have created it before any spec ran`
		);
	}

	// Directory-for-directory, so -wal/-shm journals travel with the
	// database rather than being left behind stale.
	fs.rmSync( live, { recursive: true, force: true } );
	fs.cpSync( snapshot, live, { recursive: true } );
}

/**
 * Submit the settings form and confirm the redirect kept us on this tab.
 *
 * force: true is load-bearing, not a shortcut — see the long explanation on
 * saveWebmasterSettings() in specs/webmaster-admin.spec.ts. In short:
 * clicking WP core's own #submit a second time in the same session wedges
 * Playwright's actionability wait, with no plugin JS involved. The click is
 * still real and trusted; it skips the wait, not the click. Every call here
 * is followed by assertions that independently prove the save happened.
 *
 * @param page Playwright page, on the tab, with the field already filled.
 * @param slug Tab slug the redirect must land back on.
 * @return Promise< void >
 */
async function save( page: Page, slug: string ): Promise< void > {
	await page.locator( '#submit' ).click( { force: true } );
	await expect( page ).toHaveURL( new RegExp( `tab=${ slug }` ) );
	await page.waitForTimeout( VIDEO_PACING_MS );
}

/**
 * Assert the requested tab is the active one, not a silent fallback to
 * General.
 *
 * @param page Playwright page.
 * @param slug Tab slug.
 * @return Promise< void >
 */
async function expectTabActive( page: Page, slug: string ): Promise< void > {
	await expect(
		page.locator( `a.nav-tab-active[href*="tab=${ slug }"]` )
	).toBeVisible();
}

interface TextTabStep {
	slug: string;
	label: string;
	selector: string;
	value: string;
}

/**
 * Visit a tab, change one text field, save, and prove it persisted across a
 * reload — read back from storage, not echoed from the POST.
 *
 * @param page Playwright page.
 * @param step Tab, field selector, and the value to type.
 * @return Promise< void >
 */
async function tourTextTab( page: Page, step: TextTabStep ): Promise< void > {
	await page.goto( tabUrl( step.slug ) );
	await expectTabActive( page, step.slug );

	const field = page.locator( step.selector );
	await expect( field, `${ step.label } field exists on the ${ step.slug } tab` ).toBeVisible();

	await field.fill( step.value );
	await save( page, step.slug );

	await page.reload();
	await expect(
		page.locator( step.selector ),
		`${ step.label } persisted after saving the ${ step.slug } tab`
	).toHaveValue( step.value );
}

test.describe( 'admin tour', () => {
	test.beforeAll( () => {
		restoreDatabaseSnapshot();
	} );

	test( 'every settings tab saves and persists its own fields', async ( {
		page,
	} ) => {
		// Seven save round-trips plus a reload each; the 30s default (60s in
		// CI) is nowhere near enough.
		test.setTimeout( 180_000 );

		// The database was just replaced. Prove that did not log us out
		// before blaming anything else for what follows: WordPress checks
		// the auth cookie against a session token in usermeta, so a snapshot
		// taken before global-setup's login would leave us anonymous here
		// and every later step would fail as a confusing redirect.
		await page.goto( tabUrl( 'general' ) );
		await expect(
			page.getByRole( 'heading', { name: 'SEO — The Another' } ),
			'still authenticated after the database restore — if this fails, setup/snapshot.setup.ts ran before global-setup logged in'
		).toBeVisible();

		await tourTextTab( page, {
			slug: 'general',
			label: 'Title separator',
			selector: 'input[name="taseo_settings[separator]"]',
			value: '|',
		} );

		// Post Types & Taxonomies is the one checkbox tab, so it does not go
		// through tourTextTab. Disable "page" and never "post": the
		// templates tab below renders one row per ENABLED post type, and its
		// selector targets post:post.
		await page.goto( tabUrl( 'types' ) );
		await expectTabActive( page, 'types' );

		const pageType = page.locator(
			'input[name="taseo_settings[enabled_post_types][]"][value="page"]'
		);
		await expect( pageType, 'pages start enabled' ).toBeChecked();
		await pageType.uncheck();
		await save( page, 'types' );

		await page.reload();
		await expect(
			page.locator(
				'input[name="taseo_settings[enabled_post_types][]"][value="page"]'
			),
			'pages stayed disabled after saving the types tab'
		).not.toBeChecked();

		await tourTextTab( page, {
			slug: 'templates',
			label: 'Post title template',
			selector: 'input[name="taseo_settings[title_templates][post:post]"]',
			value: '%%title%% %%sep%% Tour',
		} );

		await tourTextTab( page, {
			slug: 'social',
			label: 'X / Twitter handle',
			selector: 'input[name="taseo_settings[twitter_site]"]',
			value: '@tourhandle',
		} );

		await tourTextTab( page, {
			slug: 'schema',
			label: 'Breadcrumb separator',
			selector: 'input[name="taseo_settings[breadcrumb_separator]"]',
			value: '»',
		} );

		await tourTextTab( page, {
			slug: 'sitemap',
			label: 'Links per file',
			selector: 'input[name="taseo_settings[sitemap_max_links]"]',
			value: '250',
		} );

		await tourTextTab( page, {
			slug: 'webmaster',
			label: 'Google verification code',
			selector: 'input[name="taseo_settings[verify_google]"]',
			value: 'tourgoogletoken',
		} );
	} );
} );
```

- [ ] **Step 2: Run the suite**

Run in the FOREGROUND with `timeout: 900000`, `docker ps` confirmed empty:

```
make test-e2e
```

Expected: **26 passed** — 24 pre-existing, plus Task 1's snapshot setup test, plus this tour.

If the tour fails on a `types` or `templates` step, re-read the cross-tab note above before changing anything: disabling `post` instead of `page` removes the templates field the next step needs.

- [ ] **Step 3: Confirm the video exists**

```bash
find test-results -name '*.webm' -newermt '-10 minutes' -exec ls -lh {} \;
```

Expected: at least one `.webm`, and the tour's own file is by far the largest — it is a single recording of all seven tabs rather than a per-test fragment.

- [ ] **Step 4: Commit**

```bash
git add tests/e2e/functional/specs/zz-admin-tour.spec.ts
git commit -m "test: add admin tour covering every settings tab's save round-trip"
```

---

### Task 3: Prove the guard assertions can fail, and keep the video from green runs

**Files:**
- Modify: `.github/workflows/ci.yml` (the "Upload Playwright report on failure" step in the `Functional E2E` job)
- Temporarily modify, then revert: `tests/e2e/functional/specs/zz-admin-tour.spec.ts`

**Why this task exists:** the tour's two structural assertions — the redirect landing on the right tab, and the session surviving the database restore — are the whole reason it is a test rather than a screen recording. An assertion nobody has seen fail is an assertion nobody knows works. Both are verified by breaking what they protect, confirming a red run, and reverting.

- [ ] **Step 1: Prove the redirect assertion fails when the tab is wrong**

Temporarily edit the `webmaster` step at the end of the tour, changing its `slug` from `'webmaster'` to `'general'` — so the tour navigates to the General tab but its field selector and redirect assertion still expect the webmaster tab.

Run `make test-e2e` in the FOREGROUND.

Expected: **FAIL**, on the missing `verify_google` field or the `tab=webmaster` URL assertion.

**Revert the edit** with `git checkout -- tests/e2e/functional/specs/zz-admin-tour.spec.ts` and record the failure message in your report.

- [ ] **Step 2: Prove the auth assertion fails when the session is gone**

Temporarily insert, immediately after `restoreDatabaseSnapshot()` runs and before the first `page.goto` in the test body:

```typescript
		await page.context().clearCookies();
```

That simulates exactly the state a pre-login snapshot would leave behind: a valid database, no valid session.

Run `make test-e2e` in the FOREGROUND.

Expected: **FAIL** on the "still authenticated after the database restore" assertion, with that message visible in the output — which is the point. The failure names its own cause instead of surfacing as a mystery redirect.

**Revert the edit** and record the failure message in your report.

- [ ] **Step 3: Re-run clean**

Run `make test-e2e` in the FOREGROUND.

Expected: **26 passed**. Confirm `git status` shows no modification to `zz-admin-tour.spec.ts` — both temporary edits must be reverted.

- [ ] **Step 4: Keep e2e artifacts from green runs**

In `.github/workflows/ci.yml`, in the `Functional E2E` job, change the upload step so the video survives a passing run. Rename it to match what it now does:

```yaml
      - name: Upload Playwright report and video
        if: always()
        uses: actions/upload-artifact@ea165f8d65b6e75b540449e92b4886f43607fa02  # v4
        with:
          name: playwright-report-functional
          path: |
            playwright-report/
            test-results/
          retention-days: 7
```

Only `if: failure()` → `if: always()` and the step's `name` change. Leave the pinned action SHA, the artifact name, the paths, and the retention exactly as they are.

- [ ] **Step 5: Commit**

```bash
git add .github/workflows/ci.yml
git commit -m "ci: upload e2e video and report on green runs, not just failures"
```

- [ ] **Step 6: Full gate before handing back**

Run each and confirm green:

```bash
make lint
make test
make test-e2e
make check-plugin
```

`make lint` and `make test` should be unchanged from baseline (7/7 files, `OK (275 tests, 832 assertions)`) since this plan touches no PHP. `make test-e2e` should report 26 passed. `make check-plugin` should pass.

---

## Notes for the implementer

**The e2e run is slow and must not be backgrounded.** Two agents on this branch stalled waiting for a background notification that never arrived. Foreground, `timeout: 900000`, and check `docker ps` first.

**`fs.cpSync` needs Node 16.7+.** The e2e image ships a modern Node for Playwright, so this is fine — but if it is somehow missing, report it rather than hand-rolling a recursive copy.

**If a step's `.uncheck()` or `.fill()` wedges** the way `#submit` does, apply the same `{ force: true }` treatment and document why at the call site. Do not add arbitrary `waitForTimeout` calls to "fix" flakiness — `VIDEO_PACING_MS` is for pacing only, and adding more would blur that distinction for the next reader.

**The tour intentionally leaves the site reconfigured.** It runs last and resets before itself. Do not add cleanup — and if you ever add a spec that sorts after `zz-`, it inherits a toured site, which is why the prefix is what it is.

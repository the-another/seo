/**
 * A guided tour of every settings tab: change a field, save, prove it stuck.
 *
 * This file earns its keep twice. As a test it covers the per-tab save path
 * end to end — each tab's own fields surviving its own save, and the
 * redirect landing back on the tab you saved from rather than bouncing to
 * General. As an artifact it produces a numbered full-page screenshot per
 * tab (artifacts/tour/01-general.png through 07-webmaster.png) plus a full
 * Playwright trace (test.use( { trace: 'on' } ) below, for this spec only)
 * — which is why this is deliberately one long test instead of seven short
 * ones: a single trace covering the whole tour is more useful than seven
 * fragments would be.
 *
 * The video artifact is deliberately off for this spec
 * (test.use( { video: 'off' } ) below). Playwright's CDP screencast — what
 * the video is built from — stops updating after a page's first
 * form-submit navigation in a session: record the tour and every frame from
 * the General tab's save onward keeps showing the General tab, separator
 * already set to |, even though the test has by then moved through the
 * other six tabs. No amount of re-pacing fixes this — it is not a timing
 * problem. This is the same root cause as the force: true actionability
 * wedge documented on saveWebmasterSettings() in
 * specs/webmaster-admin.spec.ts — Playwright loses its per-renderer
 * attachments after that first POST-driven navigation. Test correctness is
 * unaffected (navigation and DOM evaluation still work, which is why every
 * assertion below passes); only the screencast-derived video is unusable,
 * so the per-tab screenshots and the trace are the tour's real visual
 * record.
 *
 * It resets the shared WordPress install before touring it and restores the
 * same snapshot again afterwards, so it leaves the site exactly as it found
 * it — the tour permanently changes verify_google and a post title template
 * that other specs assert byte-exact. The restore runs in afterAll rather
 * than inline at the end of the test body: afterAll runs once the per-test
 * context has already closed, so the recorded trace (finalized on context
 * close) still captures the full tour; restoring any earlier would truncate
 * it.
 *
 * The zz- prefix is still load-bearing: playwright.config.ts pins
 * workers: 1 and fullyParallel: false, so path order is execution order, and
 * this file must sort after every spec that asserts against the seeded
 * state. The restore-after only protects runs that come after this one (a
 * second full suite run, or ad hoc reruns) — it does nothing for ordering
 * within a single run, which is still the zz- prefix's job.
 */

import * as fs from 'node:fs';
import * as path from 'node:path';
import type { Page } from '@playwright/test';
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

const ROOT = path.resolve( __dirname, '../../../..' );
const WP_DIR_FILE = path.join( ROOT, 'artifacts/e2e-wp-dir.txt' );
const TOUR_DIR = path.join( ROOT, 'artifacts/tour' );

/**
 * Pause after each visible transition — the tab switch, the field being
 * changed, the save, and the reloaded result — so the tour's actual visual
 * record (the per-tab screenshots and the trace timeline, see the file
 * header) reads as a legible sequence of steps instead of a blur. Applied at
 * four points per tab (after navigating to it, after changing its field,
 * after saving, and after reloading), never just one. This exists for
 * legibility and NOT for stability — every assertion here waits on its own
 * condition, independently of these pauses. Please don't read them as masked
 * races and sprinkle more of them around, and don't reach for one to paper
 * over a flaky assertion elsewhere.
 */
const TOUR_PACING_MS = 500;

/**
 * Screenshot the tab's current state for the tour's visual record.
 *
 * Captured from a throwaway new tab in the same browser context, freshly
 * navigated to `page`'s current URL — not from `page` itself. This is not
 * a stylistic choice: `page`'s own CDP Page-domain session wedges after the
 * first force: true click-family action in its session (see save() and the
 * file header for the underlying cause), and that wedge turns out to reach
 * further than the screencast. Confirmed directly while diagnosing this —
 * both page.screenshot() on `page` and a raw Page.captureScreenshot sent
 * over a brand-new CDP session attached to `page` hang indefinitely once
 * the wedge has happened, even though DOM evaluation and locator actions on
 * `page` keep working fine (which is why the test's own assertions never
 * lie). A screenshot taken from an entirely different page/target — never
 * subjected to that click — succeeds immediately every time; verified
 * across all seven tabs. This is still the "on-demand CDP capture, which
 * is unaffected" fix, just aimed at a target that is actually unaffected.
 *
 * Navigating the fresh tab straight to page.url() is a plain, side-effect
 * -free GET: tourTextTab() already reloaded `page` before calling this, so
 * its URL is the clean tab= URL (WordPress strips the transient
 * ?updated=1 via a client-side history.replaceState right after the
 * redirect lands), not the POST target — no form resubmission, no state
 * change, just the same rendered result `page` is already showing.
 *
 * @param page    Playwright page the tour is running on. Not screenshotted
 *                directly (see above) — only its current URL is read.
 * @param ordinal Tour position (1-based); zero-padded into the filename so
 *                the images sort in tour order.
 * @param slug    Tab slug; identifies the tab in the filename.
 * @return Promise< void >
 */
async function captureTourStep(
	page: Page,
	ordinal: number,
	slug: string
): Promise< void > {
	const name = `${ String( ordinal ).padStart( 2, '0' ) }-${ slug }.png`;

	const capturePage = await page.context().newPage();
	await capturePage.goto( page.url() );
	await capturePage.screenshot( {
		path: path.join( TOUR_DIR, name ),
		fullPage: true,
	} );
	await capturePage.close();
}

const tabUrl = ( slug: string ): string =>
	`/wp-admin/options-general.php?page=taseo&tab=${ slug }`;

/**
 * Restore the database captured by setup/snapshot.setup.ts.
 *
 * Deletes and replaces the shared WordPress database (see the rmSync below),
 * which is only safe with a single worker: a concurrently running spec would
 * have its database vanish out from under it mid-test. playwright.config.ts
 * pins workers: 1, but justifies the pin purely on ecosystem grounds (one
 * shared WordPress install) — nothing there ties it to this function, so
 * someone could reasonably raise it without realizing this restore depends
 * on it too. Assert the dependency directly rather than trust the config
 * comment to stay in sync.
 *
 * @return void
 */
function restoreDatabaseSnapshot(): void {
	expect(
		test.info().config.workers,
		'restoreDatabaseSnapshot() deletes the shared WordPress database — only safe with workers: 1, since a concurrently running spec would have its database disappear mid-test'
	).toBe( 1 );

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
 * saveWebmasterSettings() in specs/webmaster-admin.spec.ts. In short: the
 * wedge is order-dependent, not specific to this button — the second
 * click-family action anywhere in the session reliably wedges Playwright's
 * actionability wait, with no plugin JS involved. The click is still real
 * and trusted; it skips the wait, not the click. Every call here is
 * followed by assertions that independently prove the save happened.
 *
 * @param page Playwright page, on the tab, with the field already filled.
 * @param slug Tab slug the redirect must land back on.
 * @return Promise< void >
 */
async function save( page: Page, slug: string ): Promise< void > {
	await page.locator( '#submit' ).click( { force: true } );
	await expect( page ).toHaveURL( new RegExp( `tab=${ slug }` ) );
	await page.waitForTimeout( TOUR_PACING_MS );
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
	ordinal: number;
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
 * @param step Tour position, tab, field selector, and the value to type.
 * @return Promise< void >
 */
async function tourTextTab( page: Page, step: TextTabStep ): Promise< void > {
	await page.goto( tabUrl( step.slug ) );
	await expectTabActive( page, step.slug );
	await page.waitForTimeout( TOUR_PACING_MS );

	const field = page.locator( step.selector );
	await expect( field, `${ step.label } field exists on the ${ step.slug } tab` ).toBeVisible();

	await field.fill( step.value );
	await page.waitForTimeout( TOUR_PACING_MS );
	await save( page, step.slug );

	await page.reload();
	await page.waitForTimeout( TOUR_PACING_MS );
	await expect(
		page.locator( step.selector ),
		`${ step.label } persisted after saving the ${ step.slug } tab`
	).toHaveValue( step.value );

	await captureTourStep( page, step.ordinal, step.slug );
}

// This spec only — playwright.config.ts's global video: 'on' and
// trace: 'retain-on-failure' stay put for every other spec. Playwright
// requires trace/video overrides to live at file top-level (or in the
// config); it errors ("forces a new worker") if they're set inside a
// describe block. The screencast the video would be built from freezes
// after this session's first form-submit navigation (see the file header),
// so recording one here would just be a broken artifact; a full trace,
// unaffected by that freeze, is what replaces it.
test.use( { trace: 'on', video: 'off' } );

test.describe( 'admin tour', () => {
	test.beforeAll( () => {
		restoreDatabaseSnapshot();
	} );

	test.afterAll( restoreDatabaseSnapshot );

	test( 'every settings tab saves and persists its own fields', async ( {
		page,
	} ) => {
		// Seven save round-trips plus a reload each; the 30s default (60s in
		// CI) is nowhere near enough.
		test.setTimeout( 180_000 );

		// Clear before touring, not after: a re-run must never be able to
		// mix stale screenshots from a previous tour in with the current
		// one.
		fs.rmSync( TOUR_DIR, { recursive: true, force: true } );
		fs.mkdirSync( TOUR_DIR, { recursive: true } );

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
			ordinal: 1,
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
		await page.waitForTimeout( TOUR_PACING_MS );

		const pageType = page.locator(
			'input[name="taseo_settings[enabled_post_types][]"][value="page"]'
		);
		await expect( pageType, 'pages start enabled' ).toBeChecked();
		// force: true for the same reason save()'s #submit click needs it —
		// see saveWebmasterSettings() in specs/webmaster-admin.spec.ts. The
		// toBeChecked() assertions bracketing this call independently prove
		// the uncheck happened.
		await pageType.uncheck( { force: true } );
		await page.waitForTimeout( TOUR_PACING_MS );
		await save( page, 'types' );

		await page.reload();
		await page.waitForTimeout( TOUR_PACING_MS );
		await expect(
			page.locator(
				'input[name="taseo_settings[enabled_post_types][]"][value="page"]'
			),
			'pages stayed disabled after saving the types tab'
		).not.toBeChecked();

		await captureTourStep( page, 2, 'types' );

		await tourTextTab( page, {
			ordinal: 3,
			slug: 'templates',
			label: 'Post title template',
			selector: 'input[name="taseo_settings[title_templates][post:post]"]',
			value: '%%title%% %%sep%% Tour',
		} );

		await tourTextTab( page, {
			ordinal: 4,
			slug: 'social',
			label: 'X / Twitter handle',
			selector: 'input[name="taseo_settings[twitter_site]"]',
			value: '@tourhandle',
		} );

		await tourTextTab( page, {
			ordinal: 5,
			slug: 'schema',
			label: 'Breadcrumb separator',
			selector: 'input[name="taseo_settings[breadcrumb_separator]"]',
			value: '»',
		} );

		await tourTextTab( page, {
			ordinal: 6,
			slug: 'sitemap',
			label: 'Links per file',
			selector: 'input[name="taseo_settings[sitemap_max_links]"]',
			value: '250',
		} );

		await tourTextTab( page, {
			ordinal: 7,
			slug: 'webmaster',
			label: 'Google verification code',
			selector: 'input[name="taseo_settings[verify_google]"]',
			value: 'tourgoogletoken',
		} );
	} );
} );

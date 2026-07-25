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
 * It resets the shared WordPress install before touring it and restores the
 * same snapshot again afterwards, so it leaves the site exactly as it found
 * it — the tour permanently changes verify_google and a post title template
 * that other specs assert byte-exact. The restore runs in afterAll rather
 * than inline at the end of the test body: afterAll runs once the per-test
 * context has already closed, so the recorded video (finalized on context
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

/**
 * Pause after each visible transition — the tab switch, the field being
 * changed, the save, and the reloaded result — so the recorded video plays
 * at a speed a human can follow instead of as a blur. Applied at four points
 * per tab (after navigating to it, after changing its field, after saving,
 * and after reloading), never just one. This exists for the video and NOT
 * for stability — every assertion here waits on its own condition,
 * independently of these pauses. Please don't read them as masked races and
 * sprinkle more of them around, and don't reach for one to paper over a
 * flaky assertion elsewhere.
 */
const VIDEO_PACING_MS = 500;

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
	await page.waitForTimeout( VIDEO_PACING_MS );

	const field = page.locator( step.selector );
	await expect( field, `${ step.label } field exists on the ${ step.slug } tab` ).toBeVisible();

	await field.fill( step.value );
	await page.waitForTimeout( VIDEO_PACING_MS );
	await save( page, step.slug );

	await page.reload();
	await page.waitForTimeout( VIDEO_PACING_MS );
	await expect(
		page.locator( step.selector ),
		`${ step.label } persisted after saving the ${ step.slug } tab`
	).toHaveValue( step.value );
}

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
		await page.waitForTimeout( VIDEO_PACING_MS );

		const pageType = page.locator(
			'input[name="taseo_settings[enabled_post_types][]"][value="page"]'
		);
		await expect( pageType, 'pages start enabled' ).toBeChecked();
		// force: true for the same reason save()'s #submit click needs it —
		// see saveWebmasterSettings() in specs/webmaster-admin.spec.ts. The
		// toBeChecked() assertions bracketing this call independently prove
		// the uncheck happened.
		await pageType.uncheck( { force: true } );
		await page.waitForTimeout( VIDEO_PACING_MS );
		await save( page, 'types' );

		await page.reload();
		await page.waitForTimeout( VIDEO_PACING_MS );
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

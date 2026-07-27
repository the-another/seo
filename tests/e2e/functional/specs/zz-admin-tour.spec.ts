/**
 * A guided tour of every settings tab: change a field, save, prove it stuck.
 *
 * This file earns its keep three times over. As a test it covers the
 * per-tab save path end to end — each tab's own fields surviving its own
 * save, and the redirect landing back on the tab you saved from rather than
 * bouncing to General. As an artifact it produces a numbered full-page
 * screenshot per tab (artifacts/tour/01-general.png through
 * 07-webmaster.png), a full Playwright trace (test.use( { trace: 'on' } )
 * below, for this spec only), and a video of the whole tour — which is why
 * this is deliberately one long test instead of seven short ones: a single
 * trace and a single video covering the whole tour are more useful than
 * seven fragments would be.
 *
 * Both the video and the screenshots depend on save() below never letting
 * the recorded page perform a native <form> submission. A native form-submit
 * navigation (POST -> 302 -> GET) permanently stops Chromium's headless
 * compositor from producing new frames on that page, for the rest of that
 * page's life — a genuine Chromium rendering-pipeline defect, reproduced
 * with zero Playwright/CDP input at all (see .superpowers/video-diagnosis.md
 * for the full empirical writeup: calling
 * HTMLFormElement.prototype.submit.call( form ) directly, with no click and
 * no CDP input whatsoever, froze the page exactly like a real click does).
 * It is not caused by clicking, and it is not Playwright "losing its
 * per-renderer attachments" after a click-family action — an earlier theory
 * this file's comments used to carry, directly disproven while diagnosing
 * this: no click, and no CDP input of any kind, was involved in reproducing
 * it. Other suspects were tested directly against this same freeze and each
 * ruled out in turn: PHP_CLI_SERVER_WORKERS set to 1, 2, and 6; both old
 * (chromium-headless-shell) and new Chromium headless modes; the
 * --disable-gpu and --disable-dev-shm-usage launch flags; and a hung network
 * request — a full CDP Network-domain trace showed every real HTTP request
 * completing normally, with the sole permanently-"in-flight" request being
 * an unrelated background blob: URL load present even on pages that never
 * wedge. None of those explained or fixed it. A GET navigation through the
 * identical redirect chain does not freeze the page. So save() submits the
 * form's real fields via fetch() instead of a native submit, and only ever
 * navigates the recorded page with
 * page.goto() — a GET — to show the result. Genuine click-driven saving (a
 * real click on "Save Changes") remains covered by saveWebmasterSettings()
 * in specs/webmaster-admin.spec.ts, which this file does not touch or
 * weaken.
 *
 * It resets the shared WordPress install before touring it and restores the
 * same snapshot again afterwards, so it leaves the site exactly as it found
 * it — the tour permanently changes verify_google and a post title template
 * that other specs assert byte-exact. The restore runs in afterAll rather
 * than inline at the end of the test body: afterAll runs once the per-test
 * context has already closed, so the recorded trace and video (both
 * finalized on context close) still capture the full tour; restoring any
 * earlier would truncate them.
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
import { fillTemplate, templateSurface } from '../support/helpers';

const ROOT = path.resolve( __dirname, '../../../..' );
const WP_DIR_FILE = path.join( ROOT, 'artifacts/e2e-wp-dir.txt' );
const TOUR_DIR = path.join( ROOT, 'artifacts/tour' );

/**
 * Pause after each visible transition — the tab switch, the field being
 * changed, the save, and the reloaded result — so the tour's actual visual
 * record (the video, the per-tab screenshots, and the trace timeline; see
 * the file header) reads as a legible sequence of steps instead of a blur.
 * Applied at four points per tab (after navigating to it, after changing its
 * field, after saving, and after reloading), never just one. This exists for
 * legibility and NOT for stability — every assertion here waits on its own
 * condition, independently of these pauses. Please don't read them as masked
 * races and sprinkle more of them around, and don't reach for one to paper
 * over a flaky assertion elsewhere.
 */
const TOUR_PACING_MS = 500;

/**
 * Scroll to the bottom and back to the top when the page's content overflows
 * the recorded viewport, so the video still shows a tab's full content even
 * if some future tab grows taller than the fixed 1280x1250 recording size
 * set below (see the file header and test.use() below; today, no tab does —
 * .superpowers/video-diagnosis.md measured the tallest, Webmaster Tools, at
 * 1226px). This is purely for the video recording, not for stability: every
 * assertion in this file is independent of scroll position. Skipped entirely
 * when the page already fits, so short tabs don't sit through a pointless
 * scroll.
 *
 * @param page Playwright page being recorded.
 * @return Promise< void >
 */
async function scrollForRecording( page: Page ): Promise< void > {
	const viewportHeight = page.viewportSize()?.height ?? 0;

	const overflows = await page.evaluate(
		( height ) => document.documentElement.scrollHeight > height,
		viewportHeight
	);

	if ( ! overflows ) {
		return;
	}

	await page.evaluate( () =>
		window.scrollTo( {
			top: document.documentElement.scrollHeight,
			behavior: 'smooth',
		} )
	);
	await page.waitForTimeout( TOUR_PACING_MS );

	await page.evaluate( () =>
		window.scrollTo( { top: 0, behavior: 'smooth' } )
	);
	await page.waitForTimeout( TOUR_PACING_MS );
}

/**
 * Screenshot the tab's current state for the tour's visual record.
 *
 * Screenshotted directly from the recorded page. Earlier revisions of this
 * file captured from a disposable new tab instead, to dodge the Chromium
 * compositor freeze described in the file header — but that freeze is
 * triggered specifically by a native <form> submission navigation, and
 * save() below never performs one anymore, so the recorded page never
 * wedges and there is nothing left to dodge.
 *
 * Applies scrollForRecording() first for the video's benefit (see there),
 * then takes the actual screenshot; a failed capture still propagates
 * rather than being swallowed. Deliberate: this function's whole job is
 * producing the tour's evidence, and a silently missing screenshot is the
 * kind of gap nobody notices until the one time they need it. Better for a
 * bad capture to fail the test loudly than to let the tour "complete" with
 * a hole in its record.
 *
 * @param page    Playwright page the tour is running on.
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

	await scrollForRecording( page );

	await page.screenshot( {
		path: path.join( TOUR_DIR, name ),
		fullPage: true,
	} );
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
 * Submit the settings form without a native <form> submission, then GET the
 * redirect result.
 *
 * The freeze documented in the file header is triggered specifically by a
 * native form-submission navigation (POST -> 302 -> GET) on the recorded
 * page — proven with zero Playwright/CDP input at all by calling
 * HTMLFormElement.prototype.submit.call( form ) directly and watching it
 * freeze identically to a real click (see .superpowers/video-diagnosis.md).
 * A GET navigation through the identical redirect chain does not freeze it.
 * So instead of clicking #submit, this serializes the real <form> with
 * FormData and POSTs it via fetch() from inside the page — same nonce, same
 * hidden action/tab fields, same values, so the server handles it exactly as
 * it would a real submit — then only ever navigates the recorded page with
 * page.goto(), a GET, to the final redirected URL.
 *
 * form.getAttribute( 'action' ) is deliberate, not form.action: the form has
 * a hidden field literally named "action" (WordPress's admin-post.php
 * convention, value taseo_save_settings), and a named form control shadows
 * the .action IDL property of the form itself — form.action would evaluate
 * to that <input> element, not the submit URL, and fetch() would then throw
 * trying to use it as one. getAttribute() reads the raw HTML attribute and
 * is unaffected by named-control shadowing.
 *
 * The selector is deliberately not a bare 'form': a WordPress admin page can
 * carry other <form> elements (the admin bar's command-palette UI among
 * them — visible in this very tour's screenshots), and querySelector( 'form'
 * ) would silently grab whichever one happens to come first in the DOM,
 * POSTing the wrong form with no obvious error if one ever renders ahead of
 * ours. 'form[action*="admin-post.php"]' anchors on the one fact this file
 * already depends on: SettingsPage::render_page() points this form at
 * admin-post.php (see the fetch() target above, and the redirect it
 * produces), so that is what identifies it.
 *
 * The redirect-preserving-tab assertion below is a real regression guard,
 * not incidental — it is what proves the bug fixed on this branch (the
 * redirect silently falling through to the General tab) stays fixed; see
 * saveWebmasterSettings() in specs/webmaster-admin.spec.ts, which guards the
 * same thing for a genuine click. Asserting it against the fetch response's
 * own final URL, before ever navigating the recorded page, keeps that guard
 * intact under this mechanism.
 *
 * Genuine click-driven saving (a real click on "Save Changes") remains
 * covered by saveWebmasterSettings() in specs/webmaster-admin.spec.ts, which
 * this function does not replace or weaken.
 *
 * @param page Playwright page, on the tab, with the field already filled.
 * @param slug Tab slug the redirect must land back on.
 * @return Promise< void >
 */
async function save( page: Page, slug: string ): Promise< void > {
	const redirectUrl = await page.evaluate( async () => {
		const selector = 'form[action*="admin-post.php"]';
		const form = document.querySelector(
			selector
		) as HTMLFormElement | null;

		if ( ! form ) {
			throw new Error(
				`save(): no element matched selector "${ selector }"`
			);
		}

		const action = form.getAttribute( 'action' ) as string;
		const response = await fetch( action, {
			method: 'POST',
			body: new FormData( form ),
			credentials: 'same-origin',
			redirect: 'follow',
		} );

		return response.url;
	} );

	expect(
		redirectUrl,
		`save on the ${ slug } tab redirected back to its own tab`
	).toMatch( new RegExp( `tab=${ slug }` ) );

	await page.goto( redirectUrl );
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
	/**
	 * The field's `name`, when it is a Titles & Templates field.
	 *
	 * Those inputs are hidden behind a chip editing surface, so the tour types
	 * into the surface instead — the input is still the thing that submits and
	 * still the thing whose value is read back afterwards, which is why the
	 * selector and the persistence assertion below are unchanged.
	 */
	templateInput?: string;
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
	const visible = step.templateInput
		? templateSurface( page, step.templateInput )
		: field;

	await expect(
		visible,
		`${ step.label } field exists on the ${ step.slug } tab`
	).toBeVisible();

	if ( step.templateInput ) {
		await fillTemplate( page, step.templateInput, step.value );
	} else {
		await field.fill( step.value );
	}

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

// This spec only — playwright.config.ts's global video: 'on' (unsized) and
// trace: 'retain-on-failure' stay put for every other spec. Playwright
// requires trace/video/viewport overrides to live at file top-level (or in
// the config); it errors ("forces a new worker") if they're set inside a
// describe block. save() below never lets the recorded page perform a
// native form submission (see the file header), so the video no longer
// freezes and is worth keeping alongside the trace. viewport and
// recordVideo.size are both set to 1280x1250 — wide enough to match every
// other spec's default, tall enough for every tab's full-page height
// (measured 748px-1226px in .superpowers/video-diagnosis.md, Webmaster Tools
// the tallest at 1226px) so nothing needs to scroll to be recorded in full;
// scrollForRecording() only helps future tabs that outgrow this.
test.use( {
	trace: 'on',
	video: { mode: 'on', size: { width: 1280, height: 1250 } },
	viewport: { width: 1280, height: 1250 },
} );

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
			label: 'Separator',
			selector: 'input[name="taseo_settings[separator]"]',
			value: '|',
		} );

		// Post Types & Taxonomies is the one checkbox tab, so it does not go
		// through tourTextTab. Disable "page" and never "post": the
		// templates tab below renders one row per ENABLED post type, and its
		// selector targets post:post. ordinal lives here, next to slug, for
		// the same reason every tourTextTab() step carries its own ordinal
		// field rather than a hardcoded counter: a bare literal at the
		// captureTourStep() call site would be a magic number nothing
		// checks against tour order.
		const typesStep = { ordinal: 2, slug: 'types' };

		await page.goto( tabUrl( typesStep.slug ) );
		await expectTabActive( page, typesStep.slug );
		await page.waitForTimeout( TOUR_PACING_MS );

		const pageType = page.locator(
			'input[name="taseo_settings[enabled_post_types][]"][value="page"]'
		);
		await expect( pageType, 'pages start enabled' ).toBeChecked();
		// A plain, unforced uncheck: save() never lets this page perform a
		// native form submission (see the file header), so the Chromium
		// compositor freeze that used to justify a force: true here never
		// happens in the first place. The toBeChecked() assertions
		// bracketing this call independently prove the uncheck happened.
		await pageType.uncheck();
		await page.waitForTimeout( TOUR_PACING_MS );
		await save( page, typesStep.slug );

		await page.reload();
		await page.waitForTimeout( TOUR_PACING_MS );
		await expect(
			page.locator(
				'input[name="taseo_settings[enabled_post_types][]"][value="page"]'
			),
			'pages stayed disabled after saving the types tab'
		).not.toBeChecked();

		await captureTourStep( page, typesStep.ordinal, typesStep.slug );

		await tourTextTab( page, {
			ordinal: 3,
			slug: 'templates',
			label: 'Post title template',
			selector:
				'input[name="taseo_settings[title_templates][post:post]"]',
			templateInput: 'taseo_settings[title_templates][post:post]',
			value: '%%title%% %%sep%% Tour',
		} );

		// The templates tab is the one tab whose editing surface is not the
		// input itself: after the reload above, the saved template must be
		// back on screen as chips carrying the variables' human labels, which
		// is what the tour's own screenshot records.
		await expect(
			templateSurface(
				page,
				'taseo_settings[title_templates][post:post]'
			).locator( '[data-taseo-token]' )
		).toHaveText( [
			'Title',
			'Separator',
		] );

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

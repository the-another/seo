/**
 * The consent gate.
 *
 * Every request here carries ?taseo_consent=on, which the mu-plugin fixture
 * turns into a true answer to taseo_consent_enabled for that request alone.
 * webmaster.spec.ts asserts the ungated output on the same install and must
 * keep passing untouched — the two together are the proof that consent-off
 * behaviour did not change.
 *
 * Values are seeded by tests/e2e/functional/environment/serve-wp.sh:
 * G-E2E12345, GTM-E2E1234, pixel 123456789012345, AW-123456789, UET 12345678.
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import type { Page } from '@playwright/test';

const GATED = '/?taseo_consent=on';

const THIRD_PARTY =
	/googletagmanager\.com|connect\.facebook\.net|bat\.bing\.com|facebook\.com\/tr/;

/**
 * A user agent that is not a crawler's.
 *
 * The boot script refuses to ask a bot (assets/src/consent-boot/crawler.js),
 * and its default pattern matches `headlesschrome` — which is exactly what
 * this container's Chromium calls itself: "Mozilla/5.0 (X11; Linux x86_64)
 * … HeadlessChrome/149.0.7827.0 Safari/537.36". That refusal is deliberate,
 * unit-tested production behaviour (a Lighthouse run must not be shown a
 * banner), so the browser is what has to look ordinary here, not the code
 * under test. Only the absence of "Headless" matters; the version is this
 * image's Chromium at the time of writing and need not track it.
 */
const VISITOR_UA =
	'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.7827.0 Safari/537.36';

/**
 * The second half of looking like a person: navigator.webdriver.
 *
 * Playwright's Chromium reports `navigator.webdriver === true`, which
 * isRealUser() reads as an automated browser and refuses just as firmly as a
 * bot user agent — again by design, and covered by its own unit test. Without
 * this the banner never renders under any circumstances and every assertion
 * about it would pass vacuously by never finding one.
 */
async function asVisitor( page: Page ): Promise< void > {
	await page.addInitScript( () => {
		Object.defineProperty( window.navigator, 'webdriver', {
			configurable: true,
			get: () => false,
		} );
	} );
}

/**
 * The gtag.js loaders this plugin emitted, as against the ones gtag.js goes
 * on to request for itself.
 *
 * A page configuring two Google properties makes gtag.js fetch a second,
 * product-specific loader of its own once it is running — observed here as
 * `…/gtag/js?id=AW-123456789&cx=c&gtm=4e6992`. That is Google's documented
 * multi-product behaviour, already recorded for the ungated page in
 * webmaster.spec.ts, and it is neither emitted nor suppressible by this
 * plugin. What the plugin emits is the bare form: an id and nothing else.
 */
function pluginLoaders( requests: string[] ): string[] {
	return requests.filter( ( url ) =>
		/^https:\/\/www\.googletagmanager\.com\/gtag\/js\?id=[^&]+$/.test( url )
	);
}

/**
 * Every third-party tracking request the page makes, recorded as it happens.
 */
function trackRequests( page: Page ): string[] {
	const seen: string[] = [];

	page.on( 'request', ( request ) => {
		if ( THIRD_PARTY.test( request.url() ) ) {
			seen.push( request.url() );
		}
	} );

	return seen;
}

/**
 * Seed a decision before the document runs, the way a returning visitor's
 * browser already holds one.
 */
async function seedDecision(
	page: Page,
	cats: Record< string, boolean >
): Promise< void > {
	await page.addInitScript( ( value ) => {
		window.localStorage.setItem(
			'taseo_consent',
			JSON.stringify( {
				v: 1,
				cats: value,
				t: Math.floor( Date.now() / 1000 ),
			} )
		);
	}, cats );
}

/**
 * The raw bytes the server sent for the document, before any script ran.
 */
async function documentBody( page: Page ): Promise< string > {
	const [ response ] = await Promise.all( [
		page.waitForResponse(
			( r ) =>
				r.request().resourceType() === 'document' &&
				r.url().includes( 'taseo_consent=on' )
		),
		page.goto( GATED ),
	] );

	return response.text();
}

test.describe( 'tracking consent', () => {
	// Every test in here is a person visiting the site, so the browser has to
	// be one: a real-looking user agent for the whole describe, and
	// navigator.webdriver neutralized per page. The crawler test below opts
	// back out by giving its own context a Googlebot user agent, which is what
	// keeps that half exercising the real pattern rather than the accident of
	// running under automation.
	test.use( { userAgent: VISITOR_UA } );

	test.beforeEach( async ( { page } ) => {
		await asVisitor( page );
	} );

	test( 'a first visit asks, emits inert blocks, and contacts nobody', async ( {
		page,
	} ) => {
		const requests = trackRequests( page );

		await page.goto( GATED );

		expect( requests ).toEqual( [] );
		await expect(
			page.locator( 'script[type="text/plain"][data-taseo-consent]' )
		).not.toHaveCount( 0 );
		await expect( page.getByRole( 'dialog' ) ).toBeVisible();
	} );

	test( 'accepting analytics runs GA4 and leaves the pixel inert', async ( {
		page,
	} ) => {
		await seedDecision( page, { analytics: true, marketing: false } );
		const requests = trackRequests( page );

		await page.goto( GATED );
		await page.waitForLoadState( 'networkidle' );

		expect( requests.some( ( url ) => url.includes( 'gtag/js' ) ) ).toBe( true );
		expect(
			requests.some( ( url ) => url.includes( 'connect.facebook.net' ) )
		).toBe( false );
		await expect( page.getByRole( 'dialog' ) ).toHaveCount( 0 );
	} );

	test( 'accepting marketing never fetches a loader naming the GA4 property', async ( {
		page,
	} ) => {
		await seedDecision( page, { analytics: false, marketing: true } );
		const requests = trackRequests( page );

		await page.goto( GATED );
		await page.waitForLoadState( 'networkidle' );

		const loaders = requests.filter( ( url ) => url.includes( 'gtag/js' ) );

		expect( loaders ).toHaveLength( 1 );
		expect( loaders[ 0 ] ).toContain( 'AW-123456789' );
		expect( loaders[ 0 ] ).not.toContain( 'G-E2E12345' );
		expect(
			requests.some( ( url ) => url.includes( 'connect.facebook.net' ) )
		).toBe( true );
	} );

	test( 'accepting everything loads one gtag and configures both properties', async ( {
		page,
	} ) => {
		await seedDecision( page, { analytics: true, marketing: true } );
		const requests = trackRequests( page );

		await page.goto( GATED );
		await page.waitForLoadState( 'networkidle' );

		// One loader from this plugin, not two: the group attribute makes the
		// analytics and marketing loaders mutually exclusive, so a visitor who
		// accepted both still gets a single copy of gtag.js. gtag.js's own
		// follow-up request for the Ads product is not this plugin's output —
		// see pluginLoaders() above.
		expect( pluginLoaders( requests ) ).toHaveLength( 1 );

		const html = await page.content();

		expect( html ).toContain( "gtag('config', 'G-E2E12345')" );
		expect( html ).toContain( "gtag('config', 'AW-123456789')" );
	} );

	test( 'rejecting everything contacts nobody and leaves the site usable', async ( {
		page,
	} ) => {
		await seedDecision( page, { analytics: false, marketing: false } );
		const requests = trackRequests( page );

		await page.goto( GATED );
		await page.waitForLoadState( 'networkidle' );

		expect( requests ).toEqual( [] );
		await expect( page.locator( 'body' ) ).not.toBeEmpty();
	} );

	test( 'a visitor can come back and change their mind', async ( { page } ) => {
		await seedDecision( page, { analytics: false, marketing: false } );
		const requests = trackRequests( page );

		await page.goto( GATED );
		await page.locator( '[data-taseo-consent-open]' ).click();
		await page.getByRole( 'button', { name: 'Accept all' } ).click();
		await page.waitForLoadState( 'networkidle' );

		expect( requests.some( ( url ) => url.includes( 'gtag/js' ) ) ).toBe( true );
	} );

	test( 'the no-JS halves are not emitted while the gate is on', async ( {
		page,
	} ) => {
		await page.goto( GATED );

		await expect(
			page.locator( 'noscript iframe[src*="ns.html"]' )
		).toHaveCount( 0 );
		await expect(
			page.locator( 'noscript img[src*="facebook.com/tr"]' )
		).toHaveCount( 0 );
	} );

	test( 'one visitor’s decision cannot reach another through a shared cache', async ( {
		browser,
	} ) => {
		const accepted = await browser.newContext();
		const refused = await browser.newContext();
		const acceptedPage = await accepted.newPage();
		const refusedPage = await refused.newPage();

		await seedDecision( acceptedPage, { analytics: true, marketing: true } );
		await seedDecision( refusedPage, { analytics: false, marketing: false } );

		const acceptedRequests = trackRequests( acceptedPage );
		const refusedRequests = trackRequests( refusedPage );

		const acceptedBody = await documentBody( acceptedPage );
		const refusedBody = await documentBody( refusedPage );

		await acceptedPage.waitForLoadState( 'networkidle' );
		await refusedPage.waitForLoadState( 'networkidle' );

		// The bytes the server sent are identical, so there is no variant for a
		// full-page cache to store, mix up, or hand to the wrong visitor. What
		// differs is only what each browser then chose to do with them.
		expect( acceptedBody ).toBe( refusedBody );
		expect( acceptedRequests.length ).toBeGreaterThan( 0 );
		expect( refusedRequests ).toEqual( [] );

		await accepted.close();
		await refused.close();
	} );

	test( 'a crawler is not asked and is not tracked', async ( { browser } ) => {
		const context = await browser.newContext( {
			userAgent:
				'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
		} );
		const page = await context.newPage();

		// Neutralized here too, deliberately: a context made by hand does not
		// inherit the describe's visitor user agent, and without this the
		// browser would be refused for being automated and the Googlebot user
		// agent above would be decorative — the test would pass with the
		// crawler pattern removed entirely.
		await asVisitor( page );

		const requests = trackRequests( page );

		await page.goto( GATED );
		await page.waitForLoadState( 'networkidle' );

		await expect( page.getByRole( 'dialog' ) ).toHaveCount( 0 );
		expect( requests ).toEqual( [] );

		await context.close();
	} );
} );

test.describe( 'tracking consent without JavaScript', () => {
	test.use( { javaScriptEnabled: false } );

	test( 'nothing fires and nothing breaks', async ( { page } ) => {
		const requests = trackRequests( page );

		await page.goto( GATED );

		expect( requests ).toEqual( [] );
		await expect(
			page.locator( 'noscript iframe[src*="ns.html"]' )
		).toHaveCount( 0 );
		await expect( page.locator( 'body' ) ).not.toBeEmpty();
	} );
} );

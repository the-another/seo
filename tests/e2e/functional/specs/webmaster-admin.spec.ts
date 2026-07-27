/**
 * Webmaster Tools admin tab: the tab renders the seeded values, saving
 * redirects back to the same tab (not General — a real bug fixed on this
 * branch), the saved value survives a reload, and — the write→read seam
 * that previously had no coverage at any layer — a value saved through this
 * UI actually reaches the front-end verification meta tag.
 *
 * Seeded values come from tests/e2e/functional/environment/serve-wp.sh and
 * are asserted byte-exact by specs/webmaster.spec.ts. This file mutates
 * verify_yandex (a key webmaster.spec.ts's tracking assertions don't touch
 * outside its own front-page test) and restores it in afterEach so those
 * assertions hold no matter what order the spec files run in. The shared
 * playwright.config.ts pins workers: 1 and fullyParallel: false for this
 * whole suite (one shared WordPress install), so spec files never actually
 * run concurrently today — but restoring defensively costs nothing and
 * doesn't depend on that config staying put.
 */

import * as fs from 'node:fs';
import * as os from 'node:os';
import * as path from 'node:path';
import type { Locator, Page } from '@playwright/test';
import type { RequestUtils } from '@wordpress/e2e-test-utils-playwright';
import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import {
	fillTemplate,
	fillTemplateWithoutClosing,
	templateSurface,
} from '../support/helpers';

const WEBMASTER_TAB_URL =
	'/wp-admin/options-general.php?page=taseo&tab=webmaster';
const TEMPLATES_TAB_URL =
	'/wp-admin/options-general.php?page=taseo&tab=templates';
const POST_TITLE_INPUT = 'taseo_settings[title_templates][post:post]';

/**
 * Click the settings form's "Save Changes" button and wait for the redirect
 * back to this tab.
 *
 * force: true is load-bearing, not a shortcut. The wedge is ORDER-dependent,
 * not element-dependent: the first click-family action (click(), check(),
 * uncheck()) in a session succeeds, and every subsequent one reliably wedges
 * Playwright's actionability wait ("waiting for element to be visible,
 * enabled and stable") forever — reproduced in isolation with a bare
 * Playwright script driving WP core's #submit button twice in a row, with no
 * plugin JS involved at all (this plugin enqueues no admin_enqueue_scripts
 * assets) and the PHP side round-tripping the POST in under 50ms both times
 * (confirmed with curl). It is not specific to #submit or to WP core's
 * markup: specs/zz-admin-tour.spec.ts hits the identical wedge on a
 * checkbox's uncheck() — a different element entirely — once it is the
 * second click-family action in that test. Moving the mouse away first does
 * not help, and no dialog is involved; it looks like a Chromium/Playwright
 * hover-transition false negative that only triggers once a session already
 * has one such action behind it. force: true still dispatches a real,
 * trusted click — it skips the actionability wait, not the click itself —
 * and the assertions after every call here independently confirm the save
 * actually happened.
 *
 * Adding a third click-family interaction to one of these sessions will hit
 * this same wedge; give it force: true and its own independent assertions
 * too, rather than being surprised by it.
 *
 * @param page Playwright page, already on the webmaster tab with the form
 *             filled in.
 * @return Promise< void >
 */
async function saveWebmasterSettings( page: Page ): Promise< void > {
	await page.locator( '#submit' ).click( { force: true } );
	await expect( page ).toHaveURL( /tab=webmaster/ );
}

test.describe( 'webmaster admin settings', () => {
	test.afterEach( async ( { page } ) => {
		await page.goto( WEBMASTER_TAB_URL );

		const yandexInput = page.locator(
			'input[name="taseo_settings[verify_yandex]"]'
		);

		if ( ( await yandexInput.inputValue() ) !== 'yandexe2etoken' ) {
			await yandexInput.fill( 'yandexe2etoken' );
			await saveWebmasterSettings( page );
		}
	} );

	test( 'renders the active tab and its fields populated with seeded values', async ( {
		page,
	} ) => {
		await page.goto( WEBMASTER_TAB_URL );

		await expect( page.locator( 'a.nav-tab-active' ) ).toHaveText(
			'Webmaster Tools'
		);

		await expect(
			page.locator( 'input[name="taseo_settings[verify_google]"]' )
		).toHaveValue( 'googlee2etoken' );
		await expect(
			page.locator( 'input[name="taseo_settings[verify_bing]"]' )
		).toHaveValue( 'BINGE2ETOKEN' );
		await expect(
			page.locator( 'input[name="taseo_settings[verify_yandex]"]' )
		).toHaveValue( 'yandexe2etoken' );
		await expect(
			page.locator( 'input[name="taseo_settings[verify_yahoo]"]' )
		).toHaveValue( 'yahooe2etoken' );
		await expect(
			page.locator( 'input[name="taseo_settings[verify_facebook]"]' )
		).toHaveValue( 'metae2etoken' );

		await expect(
			page.locator( 'input[name="taseo_settings[verify_google_file]"]' )
		).toHaveValue( 'googlee2efile.html' );
		await expect(
			page.locator( 'input[name="taseo_settings[verify_bing_file]"]' )
		).toHaveValue( 'BINGFILETOKEN' );

		// The Google verification file is configured, so its clickable public
		// URL should render. Bing's link must use the fixed BingSiteAuth.xml
		// filename, never the stored token.
		await expect(
			page.getByRole( 'link', { name: /googlee2efile\.html$/ } )
		).toHaveAttribute( 'href', /\/googlee2efile\.html$/ );
		await expect(
			page.getByRole( 'link', { name: /BingSiteAuth\.xml$/ } )
		).toHaveAttribute( 'href', /\/BingSiteAuth\.xml$/ );

		await expect(
			page.locator( 'input[name="taseo_settings[analytics_ga4_id]"]' )
		).toHaveValue( 'G-E2E12345' );
		await expect(
			page.locator( 'input[name="taseo_settings[analytics_gtm_id]"]' )
		).toHaveValue( 'GTM-E2E1234' );
		await expect(
			page.locator( 'input[name="taseo_settings[meta_pixel_id]"]' )
		).toHaveValue( '123456789012345' );

		// Both a GA4 ID and a GTM ID are seeded, so the double-count warning
		// must be showing. Scoped to the settings form: WordPress core's own
		// "a new WordPress version is available" admin notice also carries
		// notice-warning + inline classes and would otherwise collide.
		await expect(
			page.locator( 'form .notice-warning.inline' )
		).toContainText( 'counted twice' );
	} );

	test( 'saving a new value redirects back to the webmaster tab and persists across reload', async ( {
		page,
	} ) => {
		await page.goto( WEBMASTER_TAB_URL );

		const yandexInput = page.locator(
			'input[name="taseo_settings[verify_yandex]"]'
		);
		await expect( yandexInput ).toHaveValue( 'yandexe2etoken' );

		await yandexInput.fill( 'yandexadmine2eupdated' );
		await saveWebmasterSettings( page );

		// The bug fixed on this branch: the redirect must land back on
		// tab=webmaster, not silently fall through to the General tab.
		await expect( page.locator( 'a.nav-tab-active' ) ).toHaveText(
			'Webmaster Tools'
		);
		await expect(
			page.locator( 'input[name="taseo_settings[verify_yandex]"]' )
		).toHaveValue( 'yandexadmine2eupdated' );

		// Reload from scratch: confirms the value was actually persisted to
		// the option, not just echoed back from the just-submitted request.
		await page.reload();
		await expect(
			page.locator( 'input[name="taseo_settings[verify_yandex]"]' )
		).toHaveValue( 'yandexadmine2eupdated' );
	} );

	test( 'a verification code saved through the UI round-trips to the front-end meta tag', async ( {
		page,
	} ) => {
		// Self-contained: sets its own value rather than depending on the
		// previous test's mutation, so this test proves the write→read seam
		// on its own regardless of test/grep selection or execution order.
		await page.goto( WEBMASTER_TAB_URL );

		await page
			.locator( 'input[name="taseo_settings[verify_yandex]"]' )
			.fill( 'yandexadmine2efrontend' );
		await saveWebmasterSettings( page );

		// The write→read seam that previously had no coverage at any layer:
		// the value saved through the admin UI must reach the front-end
		// verification meta tag.
		await page.goto( '/' );
		await expect(
			page.locator( 'meta[name="yandex-verification"]' )
		).toHaveAttribute( 'content', 'yandexadmine2efrontend' );
	} );

	test( 'a template using an unavailable variable is rejected, siblings save', async ( {
		page,
	} ) => {
		await page.goto( TEMPLATES_TAB_URL );

		const productTitle = page.locator(
			`input[name="${ POST_TITLE_INPUT }"]`
		);
		const original = await productTitle.inputValue();

		// The input is hidden behind its chip surface now, so the value is
		// typed there; it still submits, and is still what these assertions
		// read back.
		await fillTemplate( page, POST_TITLE_INPUT, '%%title%% %%discount%%' );
		await expect( productTitle ).toHaveValue( '%%title%% %%discount%%' );

		await page.locator( '#submit' ).click( { force: true } );

		// Both assertions run on the page the save redirected to. The error
		// lives in the settings_errors transient, which is consumed by the
		// first render — so .form-invalid exists here and is deliberately
		// gone after a reload, which the next assertion relies on.
		await expect( page.locator( '.notice-error' ) ).toContainText(
			'%%discount%%'
		);
		await expect( productTitle ).toHaveClass( /form-invalid/ );

		await page.reload();
		await expect( productTitle ).toHaveValue( original );
		await expect( productTitle ).not.toHaveClass( /form-invalid/ );
	} );

	test( 'clicking a variable pill inserts its token', async ( { page } ) => {
		await page.goto( TEMPLATES_TAB_URL );

		const input = page.locator( `input[name="${ POST_TITLE_INPUT }"]` );

		await fillTemplate( page, POST_TITLE_INPUT, '' );

		await page
			.locator(
				`tr:has(input[name="${ POST_TITLE_INPUT }"]) [data-taseo-template-var="%%title%%"]`
			)
			.click( { force: true } );

		await expect( input ).toHaveValue( '%%title%%' );

		// And the inserted variable is a chip carrying its human label, not
		// raw %%title%% text the administrator has to decode.
		await expect(
			templateSurface( page, POST_TITLE_INPUT ).locator(
				'[data-taseo-token="%%title%%"]'
			)
		).toHaveText( 'Title' );
	} );

	test( 'a stored template renders as chips showing human labels', async ( {
		page,
	} ) => {
		await page.goto( TEMPLATES_TAB_URL );

		const surface = templateSurface( page, POST_TITLE_INPUT );

		// The seeded default is "%%title%% %%sep%% %%sitename%%": three
		// variables, each rendered as one atomic chip labelled the way its
		// pill labels it, and no raw %%token%% text left on screen.
		await expect( surface ).toBeVisible();
		await expect(
			page.locator( `input[name="${ POST_TITLE_INPUT }"]` )
		).toBeHidden();

		const chips = surface.locator( '[data-taseo-token]' );
		await expect( chips ).toHaveCount( 3 );
		await expect( chips ).toHaveText( [
			'Title',
			'Separator',
			'Site title',
		] );
		await expect( surface ).not.toContainText( '%%' );

		// Every chip is uneditable, which is what makes it behave as one unit
		// rather than as characters the caret can walk into.
		for ( const token of [ '%%title%%', '%%sep%%', '%%sitename%%' ] ) {
			await expect(
				surface.locator( `[data-taseo-token="${ token }"]` )
			).toHaveAttribute( 'contenteditable', 'false' );
		}

		// The surface borrows core's .components-text-control__input rather
		// than shipping CSS, which only works if core's components stylesheet
		// is actually on the page — a script dependency on wp-components does
		// not bring the style handle with it. Assert the computed border, not
		// the class attribute: nothing else on this screen styles a bare div,
		// so a 1px #949494 border can only have come from that stylesheet.
		await expect( surface ).toHaveClass( /components-text-control__input/ );
		await expect( surface ).toHaveCSS( 'border-top-width', '1px' );
		await expect( surface ).toHaveCSS(
			'border-top-color',
			'rgb(148, 148, 148)'
		);
	} );

	test( 'clicking a field label puts the caret in its surface', async ( {
		page,
	} ) => {
		await page.goto( TEMPLATES_TAB_URL );

		// The server-rendered <label for> still points at the input the
		// surface hides, and a label whose control is not rendered focuses
		// nothing. Clicking it must still land in the field.
		await page
			.locator( 'label[for="taseo-title-post-post"]' )
			.click( { force: true } );

		await expect( templateSurface( page, POST_TITLE_INPUT ) ).toBeFocused();
	} );

	test( 'focusing a field without editing it never rewrites the stored template', async ( {
		page,
	} ) => {
		// Seeded through the PLAIN input with the surface's script blocked, so
		// the stored template holds a character the surface itself normalises:
		// a non-breaking space. That matters — anything the surface can
		// produce it can also reproduce, so a value it would round-trip
		// byte-identically could never tell an inert no-edit path apart from
		// one that silently rewrites. This one can, and a value like it is
		// exactly what a paste into the old plain field left behind.
		const stored = '%%title%%\u00A0Shop';
		const target = 'taseo_settings[title_templates][system_page:404]';
		const bundle = /dist\/settings\/index\.js/;

		await page.route( bundle, ( route ) => route.abort() );
		await page.goto( TEMPLATES_TAB_URL );

		const plain = page.locator( `input[name="${ target }"]` );
		const original = await plain.inputValue();

		await plain.fill( stored );
		await page.locator( '#submit' ).click( { force: true } );
		expect(
			await page.locator( `input[name="${ target }"]` ).inputValue()
		).toBe( stored );

		// Now with the surface mounted: focus it, walk the caret through it,
		// click away. Not one character is typed into this field.
		await page.unroute( bundle );
		await page.goto( TEMPLATES_TAB_URL );

		const input = page.locator( `input[name="${ target }"]` );
		expect( await input.inputValue() ).toBe( stored );

		await templateSurface( page, target ).click( { force: true } );
		await page.keyboard.press( 'End' );
		await page.keyboard.press( 'ArrowLeft' );
		await page.keyboard.press( 'ArrowLeft' );
		await page.keyboard.press( 'Home' );
		await page
			.getByRole( 'heading', { name: 'SEO — The Another' } )
			.click( { force: true } );

		expect( await input.inputValue() ).toBe( stored );

		// The damage is invisible until something saves: the form posts every
		// row, so a rewrite of a field nobody edited rides along with the next
		// save of a different one.
		const otherOriginal = await page
			.locator( `input[name="${ POST_TITLE_INPUT }"]` )
			.inputValue();

		await fillTemplate( page, POST_TITLE_INPUT, '%%title%% elsewhere' );
		await page.locator( '#submit' ).click( { force: true } );
		await expect( page ).toHaveURL( /tab=templates/ );

		expect(
			await page.locator( `input[name="${ target }"]` ).inputValue()
		).toBe( stored );

		// Put both rows back the way they were found.
		await fillTemplate( page, POST_TITLE_INPUT, otherOriginal );
		await page.route( bundle, ( route ) => route.abort() );
		await page.locator( '#submit' ).click( { force: true } );
		await page.locator( `input[name="${ target }"]` ).fill( original );
		await page.locator( '#submit' ).click( { force: true } );

		await expect( page.locator( `input[name="${ target }"]` ) ).toHaveValue(
			original
		);
		await expect(
			page.locator( `input[name="${ POST_TITLE_INPUT }"]` )
		).toHaveValue( otherOriginal );
	} );

	test( 'a variable the row does not offer is marked invalid', async ( {
		page,
	} ) => {
		await page.goto( TEMPLATES_TAB_URL );

		// %%discount%% exists on no row in this environment, and the
		// validator refuses to store it, so this is the one way to see one:
		// type it. It still becomes a chip — an unresolvable variable is not
		// invisible — and that chip carries core's .form-invalid class and
		// falls back to its own slug, having no label to show.
		await fillTemplate( page, POST_TITLE_INPUT, '%%discount%%' );

		const unknown = templateSurface( page, POST_TITLE_INPUT ).locator(
			'[data-taseo-token="%%discount%%"]'
		);

		await expect( unknown ).toHaveClass( /form-invalid/ );
		await expect( unknown ).toHaveText( 'discount' );
	} );

	test( 'the value submitted after editing through the surface is the token text', async ( {
		page,
	} ) => {
		await page.goto( TEMPLATES_TAB_URL );

		const input = page.locator( `input[name="${ POST_TITLE_INPUT }"]` );
		const original = await input.inputValue();

		// Type a template, save it, and read it back from storage after a
		// reload: what the surface writes into the hidden input has to be the
		// canonical %%token%% text, byte for byte, case included.
		await fillTemplate( page, POST_TITLE_INPUT, '%%TITLE%% via surface' );
		await page.locator( '#submit' ).click( { force: true } );
		await expect( page ).toHaveURL( /tab=templates/ );

		await page.reload();
		await expect( input ).toHaveValue( '%%TITLE%% via surface' );

		// A chip keeps the raw casing it was stored with, so re-saving an
		// untouched field cannot quietly rewrite what an administrator typed.
		await expect(
			templateSurface( page, POST_TITLE_INPUT ).locator(
				'[data-taseo-token="%%TITLE%%"]'
			)
		).toHaveText( 'Title' );

		await fillTemplate( page, POST_TITLE_INPUT, original );
		await page.locator( '#submit' ).click( { force: true } );
		await expect(
			page.locator( `input[name="${ POST_TITLE_INPUT }"]` )
		).toHaveValue( original );
	} );

	test( 'with the surface script blocked the plain input still saves', async ( {
		page,
	} ) => {
		// The whole degradation story in one assertion: the built bundle
		// never arrives, so nothing hides the server-rendered input, and the
		// tab is still a working form.
		// A RegExp, not a glob: the enqueue carries a ?ver= query string that
		// a '**/dist/settings/index.js' pattern would never match.
		await page.route( /dist\/settings\/index\.js/, ( route ) =>
			route.abort()
		);

		await page.goto( TEMPLATES_TAB_URL );

		const input = page.locator(
			'input[name="taseo_settings[title_templates][system_page:search]"]'
		);
		const original = await input.inputValue();

		await expect( input ).toBeVisible();
		await expect( input ).toBeEditable();
		await expect(
			templateSurface(
				page,
				'taseo_settings[title_templates][system_page:search]'
			)
		).toHaveCount( 0 );

		await input.fill( '%%sitename%% degraded' );
		await page.locator( '#submit' ).click( { force: true } );

		await expect( page ).toHaveURL( /tab=templates/ );
		await expect(
			page.locator(
				'input[name="taseo_settings[title_templates][system_page:search]"]'
			)
		).toHaveValue( '%%sitename%% degraded' );

		await page
			.locator(
				'input[name="taseo_settings[title_templates][system_page:search]"]'
			)
			.fill( original );
		await page.locator( '#submit' ).click( { force: true } );
		await expect(
			page.locator(
				'input[name="taseo_settings[title_templates][system_page:search]"]'
			)
		).toHaveValue( original );
	} );

	test( 'the %%pri autocomplete is context-aware, not one global list', async ( {
		page,
	} ) => {
		// The design spec (docs/superpowers/specs/2026-07-26-template-
		// variables-design.md, line 148) asks for %%pri on a *product* row
		// to suggest both %%price%% and %%primary_category%%, contrasted
		// with a page row offering only %%primary_category%% — "the
		// assertion that proves the autocomplete is context-aware rather
		// than showing one global list". This e2e WordPress install has no
		// WooCommerce (see tests/e2e/functional/environment/serve-wp.sh):
		// no product row exists here, and %%price%% is unavailable on
		// every row in this environment, so that half of the spec's
		// assertion cannot be exercised at all in this environment.
		//
		// What follows is the strongest still-available proof of the same
		// context-awareness, within this one tab: post:post is registered
		// for the category taxonomy and offers %%primary_category%%;
		// post:page is not registered for it (TemplateVariables::get_for()
		// gates the variable on is_object_in_taxonomy()) and offers nothing
		// for the same fragment; a system-page row, which never gets
		// excerpt/date/primary_category at all, offers nothing either.
		await page.goto( TEMPLATES_TAB_URL );

		/**
		 * Type "%%pri" into one row's surface and read back whatever the
		 * variable autocomplete settled on.
		 *
		 * @param inputName The template input's `name` attribute.
		 */
		async function suggestionsFor(
			inputName: string
		): Promise< string[] > {
			await fillTemplateWithoutClosing( page, inputName, '%%pri' );

			// The completer resolves its options asynchronously and the list
			// renders in a popover; a list with no matches simply never
			// appears, so a fixed wait (rather than waiting for visibility)
			// is what lets this same helper prove both the "offers a
			// suggestion" and "offers nothing" cases.
			await page.waitForTimeout( 700 );

			return page
				.locator( '.components-autocomplete__popover [role="option"]' )
				.allTextContents();
		}

		expect(
			await suggestionsFor( 'taseo_settings[title_templates][post:post]' )
		).toEqual( [ '%%primary_category%%' ] );

		expect(
			await suggestionsFor( 'taseo_settings[title_templates][post:page]' )
		).toEqual( [] );

		expect(
			await suggestionsFor(
				'taseo_settings[title_templates][system_page:404]'
			)
		).toEqual( [] );
	} );

	test( 'selecting an autocomplete suggestion inserts a chip', async ( {
		page,
	} ) => {
		await page.goto( TEMPLATES_TAB_URL );

		await fillTemplateWithoutClosing( page, POST_TITLE_INPUT, '%%pri' );

		const option = page.locator(
			'.components-autocomplete__popover [role="option"]'
		);
		await expect( option ).toHaveText( [ '%%primary_category%%' ] );

		await page.keyboard.press( 'Enter' );

		// The typed fragment is replaced by one atomic chip carrying the
		// variable's human label, and the input behind it holds the token.
		const chip = templateSurface( page, POST_TITLE_INPUT ).locator(
			'[data-taseo-token="%%primary_category%%"]'
		);
		await expect( chip ).toHaveText( 'Primary category' );
		await expect(
			page.locator( `input[name="${ POST_TITLE_INPUT }"]` )
		).toHaveValue( '%%primary_category%%' );

		// And the caret survived the insertion: typing continues after the
		// chip rather than being swallowed by it.
		await page.keyboard.type( ' tail', { delay: 20 } );
		await expect(
			page.locator( `input[name="${ POST_TITLE_INPUT }"]` )
		).toHaveValue( '%%primary_category%% tail' );
	} );
} );

/**
 * The Social tab's default social image: a wp.media picker plus a URL
 * override, replacing the old attachment-ID number box. Covers the picker
 * markup itself, the URL override's round trip, and — the most important of
 * the three — the no-JS degradation path: with the picker bundle blocked,
 * the hidden input carrying the stored attachment ID must still submit
 * whatever it held, so an unrelated save on this tab can never silently
 * clear an administrator's image.
 */
/**
 * Smallest valid PNG WordPress's REST media endpoint will accept: a 1x1
 * transparent pixel. Real image bytes matter here — wp_check_filetype_and_ext()
 * and getimagesize() must both accept it, so this cannot be an arbitrary
 * file wearing a .png extension.
 */
const ONE_PIXEL_PNG = Buffer.from(
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
	'base64'
);

/**
 * Upload a real attachment through the REST API (authenticated as admin via
 * the shared storage state — see global-setup.ts). The most robust way to
 * get a genuine attachment for the media modal to select: this environment
 * seeds no media library content of its own, and this sidesteps driving
 * the modal's own Upload tab (a separate, flakier round trip through the
 * browser's file chooser) just to get a fixture in place.
 *
 * @param requestUtils Authenticated REST helper (the requestUtils fixture).
 * @param name         Distinguishing filename fragment, so a spec run that
 *                     uploads more than one of these can still tell its own
 *                     attachments apart.
 * @return Promise< number > The new attachment's ID.
 */
async function uploadFixtureImage(
	requestUtils: RequestUtils,
	name: string
): Promise< number > {
	const file = path.join( os.tmpdir(), `${ name }-${ Date.now() }.png` );
	fs.writeFileSync( file, ONE_PIXEL_PNG );

	try {
		const media = await requestUtils.uploadMedia( file );

		return media.id;
	} finally {
		fs.unlinkSync( file );
	}
}

/**
 * Drive core's actual media modal to select one already-uploaded attachment
 * for a field, exactly as an administrator would: open the picker, force the
 * Media Library tab (a session that has never opened the modal before opens
 * on Upload Files instead — see wp-includes/js/media-views.js,
 * wp.media.controller.Library's activate(), which reads the persisted
 * libraryContent user setting and falls back to the 'upload' default), click
 * the attachment's own grid tile — WordPress's AttachmentsBrowser gives every
 * tile a stable li[data-id] — and confirm.
 *
 * Every click here uses force: true. This suite has a documented Chromium/
 * Playwright wedge where the first click-family action in a session
 * succeeds and every later one hangs Playwright's actionability wait forever
 * (see saveWebmasterSettings() above); force: true still dispatches a real,
 * trusted click, it just skips that wait, and this helper's own assertions
 * independently confirm each step actually happened.
 *
 * @param page         Playwright page, already on a tab with the field's
 *                     "Select image" button visible.
 * @param field        Locator for the field's data-taseo-image-field wrapper.
 * @param attachmentId The attachment to select.
 * @return Promise< void >
 */
async function selectImageThroughModal(
	page: Page,
	field: Locator,
	attachmentId: number
): Promise< void > {
	await field
		.locator( '[data-taseo-image-select]' )
		.click( { force: true } );

	const modal = page.locator( '.media-modal' );
	await expect( modal ).toBeVisible();

	await modal
		.locator( '.media-frame-router' )
		.getByRole( 'tab', { name: 'Media Library' } )
		.click( { force: true } );

	const tile = modal.locator( `li[data-id="${ attachmentId }"]` );
	await expect( tile ).toBeVisible();
	await tile.click( { force: true } );

	await modal.locator( '.media-button-select' ).click( { force: true } );
	await expect( modal ).toBeHidden();
}

test.describe( 'social tab image field', () => {
	const SOCIAL_TAB_URL =
		'/wp-admin/options-general.php?page=taseo&tab=social';

	test( 'the default social image is a picker, not a number box', async ( {
		page,
	} ) => {
		await page.goto( SOCIAL_TAB_URL );

		const field = page.locator(
			'[data-taseo-image-field]:has(input[name="taseo_settings[default_social_image_id]"])'
		);

		await expect( field ).toBeVisible();
		await expect(
			field.locator( '[data-taseo-image-select]' )
		).toBeVisible();
		await expect(
			page.locator(
				'input[type="number"][name="taseo_settings[default_social_image_id]"]'
			)
		).toHaveCount( 0 );
	} );

	test( 'a URL override saves and survives a reload', async ( { page } ) => {
		await page.goto( SOCIAL_TAB_URL );

		await page
			.locator( 'input[name="taseo_settings[default_social_image_url]"]' )
			.fill( 'https://cdn.example.com/social.jpg' );
		// force: true — every #submit click in this file uses it; see the
		// wedge documented on saveWebmasterSettings() above.
		await page.locator( '#submit' ).click( { force: true } );

		await page.goto( SOCIAL_TAB_URL );
		await expect(
			page.locator( 'input[name="taseo_settings[default_social_image_url]"]' )
		).toHaveValue( 'https://cdn.example.com/social.jpg' );
	} );

	/**
	 * The degradation assertion. With the picker blocked the hidden input
	 * must still submit whatever was stored — losing it would silently
	 * clear an administrator's image on an unrelated save.
	 *
	 * The environment never seeds default_social_image_id, so it starts at
	 * 0. Reading "before" straight off that would make this test vacuous
	 * against exactly the regression it exists to catch: a bug that
	 * silently zeroes the field would produce before === after === '0'
	 * either way, so the assertion at the end would hold whether or not the
	 * save path actually preserved anything (the same trap noted throughout
	 * this plan's progress ledger — see
	 * .superpowers/sdd/2026-07-27-image-fields-media-picker/progress.md).
	 * So this first stores a real, non-zero ID — attachment 42 need not
	 * exist; ImageField::render() only fetches a preview when the ID is
	 * > 0, and a missing attachment just skips that — with the picker
	 * script blocked for the whole test, proving the round trip needs no
	 * JS at any point, not just for the second save.
	 */
	test( 'with the picker script blocked the stored image id still saves', async ( {
		page,
	} ) => {
		await page.route( '**/dist/media-picker/**', ( route ) =>
			route.abort()
		);

		await page.goto( SOCIAL_TAB_URL );

		const idInput = page.locator(
			'input[name="taseo_settings[default_social_image_id]"]'
		);
		await idInput.evaluate( ( el: HTMLInputElement ) => {
			el.value = '42';
		} );
		await page.locator( '#submit' ).click( { force: true } );

		await page.goto( SOCIAL_TAB_URL );
		const before = await idInput.inputValue();
		expect( before ).toBe( '42' );

		await page
			.locator( 'input[name="taseo_settings[facebook_app_id]"]' )
			.fill( '1234567890' );
		await page.locator( '#submit' ).click( { force: true } );

		await page.goto( SOCIAL_TAB_URL );
		await expect( idInput ).toHaveValue( before );
	} );

	test( 'selecting an image through the media modal stores its id and shows a preview', async ( {
		page,
		requestUtils,
	} ) => {
		const attachmentId = await uploadFixtureImage(
			requestUtils,
			'taseo-e2e-select'
		);

		await page.goto( SOCIAL_TAB_URL );

		const field = page.locator(
			'[data-taseo-image-field]:has(input[name="taseo_settings[default_social_image_id]"])'
		);

		await selectImageThroughModal( page, field, attachmentId );

		await expect(
			field.locator(
				'input[name="taseo_settings[default_social_image_id]"]'
			)
		).toHaveValue( String( attachmentId ) );
		await expect(
			field.locator( '[data-taseo-image-preview]' )
		).toBeVisible();
	} );

	test( 'remove clears the id and the preview, and the cleared state survives a save', async ( {
		page,
		requestUtils,
	} ) => {
		const attachmentId = await uploadFixtureImage(
			requestUtils,
			'taseo-e2e-remove'
		);

		await page.goto( SOCIAL_TAB_URL );

		const field = page.locator(
			'[data-taseo-image-field]:has(input[name="taseo_settings[default_social_image_id]"])'
		);
		const idInput = field.locator(
			'input[name="taseo_settings[default_social_image_id]"]'
		);

		// Establish a real, non-zero selection through the actual UI first —
		// starting from 0 would make "cleared" indistinguishable from "never
		// set", the same trap the picker-blocked test above documents.
		await selectImageThroughModal( page, field, attachmentId );
		await expect( idInput ).toHaveValue( String( attachmentId ) );

		await field
			.locator( '[data-taseo-image-remove]' )
			.click( { force: true } );

		await expect( idInput ).toHaveValue( '0' );
		await expect(
			field.locator( '[data-taseo-image-preview]' )
		).toHaveCount( 0 );

		await page.locator( '#submit' ).click( { force: true } );

		await page.goto( SOCIAL_TAB_URL );
		await expect( idInput ).toHaveValue( '0' );
		await expect(
			field.locator( '[data-taseo-image-preview]' )
		).toHaveCount( 0 );
	} );
} );

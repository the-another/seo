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

import type { Page } from '@playwright/test';
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

const WEBMASTER_TAB_URL = '/wp-admin/options-general.php?page=taseo&tab=webmaster';

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
		await page.goto(
			'/wp-admin/options-general.php?page=taseo&tab=templates'
		);

		const productTitle = page.locator(
			'input[name="taseo_settings[title_templates][post:post]"]'
		);
		const original = await productTitle.inputValue();

		await productTitle.fill( '%%title%% %%discount%%' );
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
		await page.goto(
			'/wp-admin/options-general.php?page=taseo&tab=templates'
		);

		const input = page.locator(
			'input[name="taseo_settings[title_templates][post:post]"]'
		);
		await input.fill( '' );
		await input.focus();

		await page
			.locator(
				'tr:has(input[name="taseo_settings[title_templates][post:post]"]) [data-taseo-template-var="%%title%%"]'
			)
			.click( { force: true } );

		await expect( input ).toHaveValue( '%%title%%' );
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
		await page.goto(
			'/wp-admin/options-general.php?page=taseo&tab=templates'
		);

		/**
		 * Type "%%pri" into one row's title template input and read back
		 * whatever the jQuery UI autocomplete menu settled on.
		 *
		 * @param inputName The template input's `name` attribute.
		 */
		async function suggestionsFor(
			inputName: string
		): Promise< string[] > {
			const input = page.locator( `input[name="${ inputName }"]` );

			await input.fill( '' );
			await input.click();
			await input.pressSequentially( '%%pri', { delay: 20 } );

			// jQuery UI Autocomplete debounces its search (default delay:
			// 300ms) before calling our source() function and showing the
			// menu; give it room to settle. A menu with no matches simply
			// never becomes visible, so a fixed wait (rather than waiting
			// for visibility) is what lets this same helper prove both the
			// "offers a suggestion" and "offers nothing" cases.
			await page.waitForTimeout( 500 );

			return page
				.locator( '.ui-autocomplete:visible li' )
				.allTextContents();
		}

		expect(
			await suggestionsFor(
				'taseo_settings[title_templates][post:post]'
			)
		).toEqual( [ '%%primary_category%%' ] );

		expect(
			await suggestionsFor(
				'taseo_settings[title_templates][post:page]'
			)
		).toEqual( [] );

		expect(
			await suggestionsFor(
				'taseo_settings[title_templates][system_page:404]'
			)
		).toEqual( [] );
	} );
} );

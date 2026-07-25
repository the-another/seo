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
 * force: true is load-bearing, not a shortcut: clicking WordPress core's own
 * #submit button a second time on the same page/session reliably wedges
 * Playwright's actionability wait ("waiting for element to be visible,
 * enabled and stable") forever — reproduced in isolation with a bare
 * Playwright script driving this exact WP admin button twice in a row, with
 * no plugin JS involved at all (this plugin enqueues no admin_enqueue_scripts
 * assets) and the PHP side round-tripping the POST in under 50ms both times
 * (confirmed with curl). Moving the mouse away first does not help, and no
 * dialog is involved; it looks like a Chromium/Playwright hover-transition
 * false negative on this specific WP core button markup. force: true still
 * dispatches a real, trusted click — it skips the actionability wait, not
 * the click itself — and the assertions after every call here independently
 * confirm the save actually happened.
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
} );

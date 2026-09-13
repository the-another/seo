/**
 * Site verification tags and files, and tracking snippets.
 *
 * The verification-file assertions compare the FULL response body, not a
 * substring: Google, Bing, and Yandex all fail verification when the CMS
 * injects extra whitespace or markup, and a substring match would not see
 * that. This is the assertion unit tests cannot make.
 *
 * Values are seeded by tests/e2e/functional/environment/serve-wp.sh.
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'webmaster verification and tracking', () => {
	test( 'verification meta tags on the front page', async ( { page } ) => {
		await page.goto( '/' );

		// Both directions of the method gate, not just "the tag printed": a
		// service now has ONE method, never a code and a file value at once
		// (the two-credential state this feature abolishes). serve-wp.sh
		// seeds Google and Bing on the file method — see the file-serving
		// tests below — and Yandex/Yahoo/Meta with a code and no method key,
		// which resolves to meta (Settings::get_verification_method()'s
		// default). So the front page must print exactly the meta-method
		// services' tags and print NEITHER file-method service's tag. A gate
		// that stopped suppressing file-mode services, or one that started
		// suppressing meta-mode ones, would both be caught here — do not
		// "restore" a single-direction assertion that only checks presence.
		await expect(
			page.locator( 'meta[name="google-site-verification"]' )
		).toHaveCount( 0 );
		await expect(
			page.locator( 'meta[name="msvalidate.01"]' )
		).toHaveCount( 0 );
		await expect(
			page.locator( 'meta[name="yandex-verification"]' )
		).toHaveAttribute( 'content', 'yandexe2etoken' );
		await expect( page.locator( 'meta[name="y_key"]' ) ).toHaveAttribute(
			'content',
			'yahooe2etoken'
		);
		await expect(
			page.locator( 'meta[name="facebook-domain-verification"]' )
		).toHaveAttribute( 'content', 'metae2etoken' );
	} );

	test( 'verification tags are absent on a single post', async ( {
		page,
	} ) => {
		await page.goto( '/seo-target-post/' );

		await expect(
			page.locator( 'meta[name="google-site-verification"]' )
		).toHaveCount( 0 );
		await expect(
			page.locator( 'meta[name="msvalidate.01"]' )
		).toHaveCount( 0 );
	} );

	test( 'Google verification file is served byte-exact', async ( {
		request,
	} ) => {
		const response = await request.get( '/googlee2efile.html' );

		expect( response.status() ).toBe( 200 );
		expect( response.headers()[ 'content-type' ] ).toContain( 'text/html' );
		expect( response.headers()[ 'x-robots-tag' ] ).toContain( 'noindex' );
		expect( await response.text() ).toBe(
			'google-site-verification: googlee2efile.html'
		);
	} );

	test( 'Bing verification file is served byte-exact', async ( {
		request,
	} ) => {
		const response = await request.get( '/BingSiteAuth.xml' );

		expect( response.status() ).toBe( 200 );
		expect( response.headers()[ 'content-type' ] ).toContain(
			'application/xml'
		);
		// The body embeds Bing's one stored token (BINGE2ETOKEN, seeded by
		// serve-wp.sh). There is no separate file-only token anymore — the
		// same value that would otherwise be the meta tag's content is what
		// the file method serves in the <user> element.
		expect( await response.text() ).toBe(
			'<?xml version="1.0"?>\n<users>\n  <user>BINGE2ETOKEN</user>\n</users>'
		);
	} );

	test( 'an unconfigured verification filename still 404s', async ( {
		request,
	} ) => {
		const response = await request.get( '/googlewrongtoken.html' );

		expect( response.status() ).toBe( 404 );
	} );

	test( 'GA4 and Tag Manager snippets', async ( { request } ) => {
		// Asserted against the SERVED HTML, not the live DOM. Once a Google Ads
		// ID is configured alongside GA4, gtag.js injects a second,
		// product-specific loader request of its own client-side
		// (gtag/js?id=AW-…&cx=c&gtm=…). That is Google's documented
		// multi-product behaviour: this plugin emits one loader, built from the
		// first configured gtag ID, and cannot emit or suppress the second. A
		// DOM count here would therefore be asserting Google's runtime rather
		// than this plugin's output — it read as "one loader" only while GA4
		// was the only gtag vendor. What the plugin owns is the markup it
		// serves, so that is what this pins.
		const served = await ( await request.get( '/' ) ).text();

		// Exactly one loader, and it is the GA4 property's — two gtag vendors
		// are configured and they share one bootstrap rather than loading the
		// library twice. Stricter than the count it replaces, which would have
		// passed on any single loader for any ID.
		expect(
			served.match( /googletagmanager\.com\/gtag\/js\?id=[^"'&]+/g ) ?? []
		).toEqual( [ 'googletagmanager.com/gtag/js?id=G-E2E12345' ] );

		expect( served ).toContain( "gtag('config', 'G-E2E12345')" );

		// A bare 'GTM-E2E1234' substring check would pass even if the
		// noscript body fallback (GtmTransport::emit_noscript(), on
		// wp_body_open) were malformed or missing entirely, because the same
		// ID already appears in the unrelated head bootstrap script
		// (GtmTransport::emit_primary()). Pin the literal noscript/iframe
		// fragment instead — same reasoning and same remedy as the Meta Pixel
		// test below: a DOM locator can't see it (browsers parse <noscript>
		// content as inert raw text when scripting is enabled), so this is the
		// only assertion that actually exercises the body half's output.
		expect( served ).toContain(
			'<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-E2E1234" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>'
		);
	} );

	test( 'Meta Pixel base code and noscript fallback', async ( { page } ) => {
		await page.goto( '/' );

		const html = await page.content();
		expect( html ).toContain( 'connect.facebook.net/en_US/fbevents.js' );
		expect( html ).toContain( "fbq('init', '123456789012345')" );

		// A DOM locator can't see the <noscript> fallback (Meta Pixel <img>,
		// same for GTM's <iframe>): confirmed empirically that
		// `page.locator('noscript img[...]')` resolves to 0 elements here
		// even though the exact markup IS present in page.content() below.
		// That is NOT the theme failing to fire wp_body_open — it does fire
		// (twentytwentyfive is a block theme; core's template-canvas.php
		// calls wp_body_open() itself, no classic header.php required) and
		// MetaPixelOutput::print_body() does print. It's a browser parsing
		// rule: per the HTML spec, <noscript> content is parsed as inert raw
		// text (not child elements) whenever scripting is enabled, which it
		// is for a normal Playwright page — so the <img> genuinely never
		// exists as a DOM node for a locator to find. Asserting on
		// page.content() is the only way to see this half of the render, so
		// that's the assertion here rather than a relaxed `toContain()`
		// stand-in for a failing locator.
		expect( html ).toContain(
			'<noscript><img height="1" width="1" style="display:none" alt="" src="https://www.facebook.com/tr?id=123456789012345&#038;ev=PageView&#038;noscript=1" /></noscript>'
		);
	} );

	test( 'Google Ads and Bing UET snippets', async ( { page } ) => {
		await page.goto( '/' );

		const html = await page.content();

		// GA4 and Google Ads are separate registry entries sharing one
		// transport, so the count-of-1 assertion in the GA4 test above is what
		// proves the shared bootstrap: two gtag vendors, one gtag.js.
		expect( html ).toContain( "gtag('config', 'AW-123456789')" );
		expect( html ).toContain( 'https://bat.bing.com/bat.js' );
		expect( html ).toContain( 'ti:"12345678"' );
	} );

	test( 'the override filter replaces the whole tag set', async ( { page } ) => {
		await page.goto( '/?taseo_tags=replace' );

		const html = await page.content();

		expect( html ).toContain( "gtag('config', 'G-OVERRIDE1')" );
		expect( html ).not.toContain( 'G-E2E12345' );
		expect( html ).not.toContain( 'GTM-E2E1234' );
		expect( html ).not.toContain( 'fbevents.js' );
		expect( html ).not.toContain( 'bat.bing.com' );
	} );

	test( 'the override filter can emit nothing at all', async ( { page } ) => {
		await page.goto( '/?taseo_tags=off' );

		const html = await page.content();

		await expect( page.locator( 'script[src*="gtag/js"]' ) ).toHaveCount( 0 );
		expect( html ).not.toContain( 'googletagmanager.com' );
		expect( html ).not.toContain( 'fbevents.js' );
		expect( html ).not.toContain( 'bat.bing.com' );

		// Nothing means nothing: no empty wrapper left behind either.
		expect( html ).not.toContain( '<noscript><iframe' );
		expect( html ).not.toContain( '<noscript><img height="1"' );
	} );

	test( 'markup supplied through the override filter never reaches the page', async ( { page } ) => {
		await page.goto( '/?taseo_tags=markup' );

		const html = await page.content();

		expect( html ).not.toContain( 'taseoBreakout' );
		expect( await page.evaluate( () => 'taseoBreakout' in window ) ).toBe( false );
		await expect( page.locator( 'script[src*="gtag/js"]' ) ).toHaveCount( 0 );
	} );
} );

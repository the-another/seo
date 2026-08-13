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

	test( 'GA4 and Tag Manager snippets', async ( { page } ) => {
		await page.goto( '/' );

		await expect(
			page.locator( 'script[src*="googletagmanager.com/gtag/js"]' )
		).toHaveCount( 1 );
		await expect(
			page.locator( 'script[src*="id=G-E2E12345"]' )
		).toHaveCount( 1 );

		const html = await page.content();
		expect( html ).toContain( "gtag('config', 'G-E2E12345')" );

		// A bare 'GTM-E2E1234' substring check would pass even if the
		// noscript body fallback (print_gtm_body(), on wp_body_open) were
		// malformed or missing entirely, because the same ID already appears
		// in the unrelated head bootstrap script (print_gtm_head()). Pin the
		// literal noscript/iframe fragment instead — same reasoning and same
		// remedy as the Meta Pixel test below: a DOM locator can't see it
		// (browsers parse <noscript> content as inert raw text when
		// scripting is enabled), so this is the only assertion that
		// actually exercises print_gtm_body()'s output.
		expect( html ).toContain(
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
} );

/**
 * Per-domain verification: the Webmaster Tools domain switcher, isolated
 * saves, and host-scoped verification file serving.
 *
 * The multi-brand plugin is not installed in this e2e environment.
 * tests/e2e/functional/environment/serve-wp.sh's
 * taseo-domains-fixture.php mu-plugin stands in for it, registering
 * brandtwo.test through the same taseo_verification_domains filter seam the
 * real plugin uses, with a per-domain record seeded via `wp option patch
 * insert taseo_settings verification_domains`.
 *
 * Host-header approach: DETERMINED EMPIRICALLY, by running this spec against
 * a live server (`make test-e2e`, Playwright 1.61.1, tests/e2e/Dockerfile's
 * Ubuntu 24.04 image). Two Playwright-native routes were tried and both
 * observably failed before a third, non-Playwright route was used:
 *
 *  - Option 1 — `request.get( path, { headers: { Host: '...' } } )` on the
 *    shared `request` fixture — observed 404 (expected 200): does not work.
 *  - Option 2 — a dedicated `request.newContext( { extraHTTPHeaders:
 *    { Host: '...' } } )` — also observed 404, same failure as option 1:
 *    does not work either. `APIRequestContext` overrides the Host header
 *    from the request's target origin either way.
 *  - Option 3 — a raw `node:http` request (used below). Node's own http
 *    client sends a caller-supplied Host header verbatim, and PHP's
 *    built-in server (`wp server`, no vhost matching) answers whatever Host
 *    it is given, so this is what the two host-scoped assertions use.
 *
 * A bare `localhost` Host does not match this environment's `home_url()`
 * (`http://localhost:$WP_E2E_PORT`), which makes core's `redirect_canonical`
 * (also on template_redirect, priority 10) fire a 301 before the request
 * can ever reach a 404 — see the inline comment on the negative assertion
 * below, which is why that assertion sends the port explicitly.
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { request as httpRequest } from 'node:http';

const WP_E2E_PORT = Number( process.env.WP_E2E_PORT ) || 8881;

/**
 * Fetch a path from the local e2e WordPress with an explicit Host header.
 *
 * Playwright's `APIRequestContext` cannot do this — see the header comment
 * above — so this drops to Node's own http client, which sends a
 * caller-supplied Host verbatim and is answered by PHP's built-in server
 * (no vhost matching) as if the request had arrived on that domain.
 *
 * @param path Request path, leading slash included.
 * @param host Host header value.
 * @return Promise< { status: number, body: string } > Response status and
 *         full body text.
 */
function fetchWithHost(
	path: string,
	host: string
): Promise< { status: number; body: string } > {
	return new Promise( ( resolve, reject ) => {
		const req = httpRequest(
			{
				host: '127.0.0.1',
				port: WP_E2E_PORT,
				path,
				headers: { Host: host },
			},
			( res ) => {
				let body = '';
				res.setEncoding( 'utf8' );
				res.on( 'data', ( chunk ) => {
					body += chunk;
				} );
				res.on( 'end', () =>
					resolve( { status: res.statusCode ?? 0, body } )
				);
			}
		);
		req.on( 'error', reject );
		req.end();
	} );
}

const WEBMASTER_TAB =
	'/wp-admin/options-general.php?page=taseo&tab=webmaster';

test.describe( 'per-domain verification', () => {
	test.beforeEach( async ( { admin } ) => {
		await admin.visitAdminPage( 'options-general.php', 'page=taseo&tab=webmaster' );
	} );

	test( 'the domain nav lists the default first and the brand domain second', async ( {
		page,
	} ) => {
		const nav = page.locator( 'ul.subsubsub' );

		await expect( nav ).toBeVisible();
		await expect( nav.locator( 'a' ).first() ).toContainText( '(default)' );
		await expect( nav.locator( 'a' ) ).toContainText( [
			/\(default\)/,
			'brandtwo.test',
		] );
	} );

	test( 'switching to the brand domain loads that domain\'s values', async ( {
		page,
	} ) => {
		await page.goto( `${ WEBMASTER_TAB }&domain=brandtwo.test` );

		await expect(
			page.locator( 'input[name="taseo_settings[verify_google]"]' )
		).toHaveValue( 'brandtwoe2etoken' );

		await expect( page.locator( 'input[name="domain"]' ) ).toHaveValue(
			'brandtwo.test'
		);

		// The feature's central claim: verification codes NEVER inherit the
		// default, unlike tracking IDs (asserted in the next test). The fixture
		// record for brandtwo.test omits verify_bing while the default domain
		// has BINGE2ETOKEN, so this field must be blank AND advertise nothing
		// but the generic hint. A regression flipping $inherit on for codes
		// would show 'BINGE2ETOKEN (inherited)' here — handing a brand domain
		// the default's code guarantees a failed verification instead of an
		// obvious blank field.
		const bingInput = page.locator(
			'input[name="taseo_settings[verify_bing]"]'
		);
		await expect( bingInput ).toHaveValue( '' );
		await expect( bingInput ).toHaveAttribute(
			'placeholder',
			'Verification code'
		);
	} );

	test( 'a blank brand tracking field advertises the inherited default', async ( {
		page,
	} ) => {
		await page.goto( `${ WEBMASTER_TAB }&domain=brandtwo.test` );

		// The fixture record seeds analytics_ga4_id but deliberately omits
		// analytics_gtm_id for brandtwo.test (see serve-wp.sh), so this field
		// is blank on the brand domain and must fall back to advertising the
		// default domain's GTM-E2E1234 as a placeholder — value stays empty,
		// only the placeholder communicates what will actually fire.
		const gtmInput = page.locator(
			'input[name="taseo_settings[analytics_gtm_id]"]'
		);
		await expect( gtmInput ).toHaveValue( '' );
		await expect( gtmInput ).toHaveAttribute(
			'placeholder',
			'GTM-E2E1234 (inherited)'
		);
	} );

	test( 'saving the brand domain leaves the default domain untouched', async ( {
		page,
	} ) => {
		await page.goto( `${ WEBMASTER_TAB }&domain=brandtwo.test` );

		await page
			.locator( 'input[name="taseo_settings[verify_yandex]"]' )
			.fill( 'brandtwoyandexupdated' );

		// force: true and a single click per session — see the note at the
		// top of webmaster-admin.spec.ts.
		await page.locator( '#submit' ).click( { force: true } );

		await expect( page ).toHaveURL( /domain=brandtwo\.test/ );
		await expect(
			page.locator( 'input[name="taseo_settings[verify_yandex]"]' )
		).toHaveValue( 'brandtwoyandexupdated' );

		await page.goto( WEBMASTER_TAB );

		// The default domain's own verify_yandex (seeded 'yandexe2etoken' by
		// serve-wp.sh) must be exactly what it was before the brand-domain
		// save above — proving the two domains' records don't bleed into
		// each other, not merely that the brand domain kept its own value.
		await expect(
			page.locator( 'input[name="taseo_settings[verify_yandex]"]' )
		).toHaveValue( 'yandexe2etoken' );

		// Same proof for the field the brand domain DOES override: the default
		// still shows its own googlee2etoken, not brandtwo.test's
		// brandtwoe2etoken, after that domain has been visited and saved. On
		// its own — asserted on a pristine default tab, as it used to be — this
		// would pass with the whole domain feature deleted; it only means
		// anything here, downstream of the brand-domain save.
		await expect(
			page.locator( 'input[name="taseo_settings[verify_google]"]' )
		).toHaveValue( 'googlee2etoken' );
	} );

	test( 'a brand domain file is served on that host only', async () => {
		const onBrand = await fetchWithHost(
			'/googlebrandtwo.html',
			'brandtwo.test'
		);

		expect( onBrand.status ).toBe( 200 );
		expect( onBrand.body ).toBe(
			'google-site-verification: googlebrandtwo.html'
		);

		// Same path, this environment's own default Host (serve-wp.sh's
		// WP_SITE_URL is http://localhost:$PORT) — the brand domain's file
		// must not leak onto the site's own host.
		//
		// The port is part of the Host value on purpose: a bare 'localhost'
		// does not match home_url() ('http://localhost:8881'), so core's
		// redirect_canonical fires a 301 before the request ever reaches
		// VerificationFileServer's fall-through 404. Including the port is
		// what lets the request genuinely reach the file server and prove
		// it declines to serve the brand domain's file on this host — don't
		// "simplify" this back to a bare 'localhost'.
		const onDefault = await fetchWithHost(
			'/googlebrandtwo.html',
			`localhost:${ WP_E2E_PORT }`
		);

		expect( onDefault.status ).toBe( 404 );
	} );

	test( 'the default domain file is unaffected', async ( { request } ) => {
		const response = await request.get( '/googlee2efile.html' );

		expect( response.status() ).toBe( 200 );
		expect( await response.text() ).toBe(
			'google-site-verification: googlee2efile.html'
		);
	} );
} );

/**
 * Template-driven <title>/meta description and Open Graph / Twitter Card
 * tags on a managed singular front-end page.
 *
 * Verified against real output (includes/Meta/MetaOutput.php,
 * includes/Social/SocialOutput.php, includes/Meta/CurrentContext.php): the
 * default title template `%%title%% %%sep%% %%sitename%%` resolves with
 * Settings::get_separator()'s default value, an en dash (`–`), e.g.
 * "SEO Target Post – TASEO E2E" — hence the loose `.+` in the title regex
 * below rather than hard-coding the separator glyph.
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'meta and social tags', () => {
	test.beforeEach( async ( { page } ) => {
		await page.goto( '/seo-target-post/' );
	} );

	test( 'templated title and meta description', async ( { page } ) => {
		// Default title template: %%title%% %%sep%% %%sitename%%.
		await expect( page ).toHaveTitle( /SEO Target Post .+ TASEO E2E/ );

		// Default description template: %%excerpt%%.
		await expect(
			page.locator( 'meta[name="description"]' )
		).toHaveAttribute(
			'content',
			'A deterministic excerpt for meta tags.'
		);
	} );

	test( 'canonical URL points at the permalink', async ( { page } ) => {
		await expect(
			page.locator( 'link[rel="canonical"]' )
		).toHaveAttribute( 'href', /\/seo-target-post\/$/ );
	} );

	test( 'Open Graph tags', async ( { page } ) => {
		await expect(
			page.locator( 'meta[property="og:type"]' )
		).toHaveAttribute( 'content', 'website' );
		await expect(
			page.locator( 'meta[property="og:title"]' )
		).toHaveAttribute( 'content', /SEO Target Post/ );
		await expect(
			page.locator( 'meta[property="og:site_name"]' )
		).toHaveAttribute( 'content', 'TASEO E2E' );
		await expect(
			page.locator( 'meta[property="og:url"]' )
		).toHaveAttribute( 'content', /\/seo-target-post\/$/ );
	} );

	test( 'Twitter Card tags', async ( { page } ) => {
		await expect(
			page.locator( 'meta[name="twitter:card"]' )
		).toHaveAttribute( 'content', 'summary_large_image' );
		await expect(
			page.locator( 'meta[name="twitter:title"]' )
		).toHaveAttribute( 'content', /SEO Target Post/ );
	} );
} );

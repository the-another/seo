/**
 * Breadcrumbs block server-side rendering on the front end.
 *
 * Verified against real output (curl of /crumbs/ inside the e2e container;
 * source: includes/Breadcrumbs/BreadcrumbRenderer.php's render()):
 * the trail is wrapped in `<nav class="taseo-breadcrumbs" aria-label="Breadcrumb">`,
 * each non-current crumb is an `<a>`, and — since
 * Settings::breadcrumb_link_current() defaults to false — the current
 * (last) crumb renders as plain text: `<span aria-current="page">Crumbs</span>`,
 * not a link. The brief's draft selector (`nav` with `hasText: 'Crumbs'`)
 * is replaced with the actual stable class/attributes the renderer emits.
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'breadcrumbs block', () => {
	test( 'renders a trail on a page containing the block', async ( {
		page,
	} ) => {
		await page.goto( '/crumbs/' );

		const nav = page.locator( 'nav.taseo-breadcrumbs' );
		await expect( nav ).toBeVisible();
		await expect( nav ).toHaveAttribute( 'aria-label', 'Breadcrumb' );

		// Trail starts at Home, a real link back to the site root.
		await expect(
			nav.getByRole( 'link', { name: 'Home' } )
		).toHaveAttribute( 'href', /\/$/ );

		// The current page is the last crumb, rendered as non-link text
		// (breadcrumb_link_current defaults to false).
		await expect( nav.locator( '[aria-current="page"]' ) ).toHaveText(
			'Crumbs'
		);
	} );
} );

/**
 * Plugin activation and admin-surface smoke checks.
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'activation', () => {
	test( 'plugin is active', async ( { requestUtils } ) => {
		const plugins = await requestUtils.rest<
			Array< { plugin: string; status: string } >
		>( {
			method: 'GET',
			path: '/wp/v2/plugins',
		} );

		const ours = plugins.find( ( p ) =>
			p.plugin.includes( 'the-another-seo' )
		);
		expect( ours ).toBeDefined();
		expect( ours!.status ).toBe( 'active' );
	} );

	test( 'plugin can be deactivated and reactivated through wp-admin', async ( {
		page,
		requestUtils,
	} ) => {
		await requestUtils.deactivatePlugin( 'the-another-seo' );

		await page.goto( '/wp-admin/plugins.php' );
		const row = page.locator( 'tr[data-slug="the-another-seo"]' );
		await row.getByRole( 'link', { name: 'Activate' } ).click();

		await expect( page.locator( '#message.updated' ) ).toContainText(
			'activated'
		);

		const plugins = await requestUtils.rest<
			Array< { plugin: string; status: string } >
		>( {
			method: 'GET',
			path: '/wp/v2/plugins',
		} );
		const ours = plugins.find( ( p ) =>
			p.plugin.includes( 'the-another-seo' )
		);
		expect( ours ).toBeDefined();
		expect( ours!.status ).toBe( 'active' );
	} );

	test( 'frontend responds without fatals', async ( { page } ) => {
		const response = await page.goto( '/' );
		expect( response!.status() ).toBe( 200 );
		await expect( page.locator( 'body' ) ).not.toContainText(
			'Fatal error'
		);
	} );

	test( 'settings page renders', async ( { page } ) => {
		await page.goto( '/wp-admin/options-general.php?page=taseo' );
		// Real page title per includes/Admin/SettingsPage.php's
		// add_options_page() call (~line 89): "SEO — The Another", not
		// "The Another SEO" as originally drafted.
		await expect(
			page.getByRole( 'heading', { name: 'SEO — The Another' } )
		).toBeVisible();
	} );
} );

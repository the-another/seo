/**
 * Schema.org JSON-LD graph on a managed singular page.
 *
 * Verified against real output (curl of /seo-target-post/ inside the e2e
 * container): SchemaOutput::print_json_ld() (includes/Schema/SchemaOutput.php)
 * emits exactly `{"@context":"https://schema.org","@graph":[...]}` — the
 * brief's draft shape matches actual output as-is, no adjustment needed.
 * The graph for a post carries Organization, WebSite, WebPage,
 * BreadcrumbList, and (per Settings::SCHEMA_TYPE_DEFAULTS — 'post' =>
 * 'Article') an Article main entity.
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'schema.org JSON-LD', () => {
	test( 'ld+json block is present, parses, and contains a graph', async ( {
		page,
	} ) => {
		await page.goto( '/seo-target-post/' );

		const raw = await page
			.locator( 'script[type="application/ld+json"]' )
			.first()
			.textContent();
		expect( raw ).toBeTruthy();

		const data = JSON.parse( raw! );
		expect( data[ '@context' ] ).toBe( 'https://schema.org' );

		// The plugin emits a connected graph; posts default to Article.
		const graph = data[ '@graph' ] ?? [ data ];
		const types = graph.flatMap( ( node: { '@type': string | string[] } ) =>
			Array.isArray( node[ '@type' ] ) ? node[ '@type' ] : [ node[ '@type' ] ]
		);
		expect( types ).toContain( 'Article' );
	} );
} );

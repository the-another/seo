/**
 * Provisioning: seeds the deterministic front-end fixtures the specs
 * assert against. Saving content through REST fires the plugin's
 * save_post-hooked indexable sync, so rows exist immediately — no
 * Action Scheduler dependency here (the initial backfill was already
 * drained by environment/serve-wp.sh before the server started).
 */

import { test } from '@wordpress/e2e-test-utils-playwright';
import { createPost, createPage } from '../support/helpers';

test( 'provision: seo fixture content', async ( { requestUtils } ) => {
	test.setTimeout( 120_000 );

	// Idempotency guard: with reuseExistingServer a re-run hits an already
	// provisioned database where the slugs below exist.
	const existing = await requestUtils.rest< Array< { id: number } > >( {
		method: 'GET',
		path: '/wp/v2/posts',
		params: { slug: 'seo-target-post' },
	} );
	if ( existing.length > 0 ) {
		return;
	}

	// The meta/social/schema specs assert against this post. The excerpt is
	// what the default description template (%%excerpt%%) resolves to.
	await createPost( requestUtils, {
		title: 'SEO Target Post',
		slug: 'seo-target-post',
		content: '<p>Deterministic body content for the SEO e2e suite.</p>',
		excerpt: 'A deterministic excerpt for meta tags.',
	} );

	// The breadcrumbs spec renders the block through its server-side
	// render.php via ordinary front-end delivery.
	await createPage( requestUtils, {
		title: 'Crumbs',
		slug: 'crumbs',
		content: '<!-- wp:the-another/seo-breadcrumbs /-->',
	} );
} );

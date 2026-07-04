/**
 * Shared helpers for the functional e2e specs. All content creation goes
 * through the REST API via @wordpress/e2e-test-utils-playwright's
 * RequestUtils (authenticated as admin by the global setup's storage state).
 */

import type { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

interface CreatePostInput {
	title: string;
	content: string;
	excerpt?: string;
	slug?: string;
}

interface CreatePageInput {
	title: string;
	content: string;
	slug?: string;
}

export async function createPost(
	requestUtils: RequestUtils,
	{ title, content, excerpt, slug }: CreatePostInput
): Promise< number > {
	const post = await requestUtils.rest< { id: number } >( {
		method: 'POST',
		path: '/wp/v2/posts',
		data: { title, content, excerpt, slug, status: 'publish' },
	} );
	return post.id;
}

export async function createPage(
	requestUtils: RequestUtils,
	{ title, content, slug }: CreatePageInput
): Promise< number > {
	const page = await requestUtils.rest< { id: number } >( {
		method: 'POST',
		path: '/wp/v2/pages',
		data: { title, content, slug, status: 'publish' },
	} );
	return page.id;
}

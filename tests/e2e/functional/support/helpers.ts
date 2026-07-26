/**
 * Shared helpers for the functional e2e specs. All content creation goes
 * through the REST API via @wordpress/e2e-test-utils-playwright's
 * RequestUtils (authenticated as admin by the global setup's storage state).
 */

import type { Locator, Page } from '@playwright/test';
import type { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

/**
 * The chip editing surface standing in for one template input.
 *
 * The Titles & Templates inputs are hidden once their surface mounts — they
 * still submit, and are still the thing whose `value` a spec reads back, but
 * they are no longer what a spec types into.
 *
 * @param page      Playwright page, on the templates tab.
 * @param inputName The template input's `name` attribute.
 * @return The surface locator.
 */
export function templateSurface( page: Page, inputName: string ): Locator {
	return page.locator( `[data-taseo-template-surface="${ inputName }"]` );
}

/**
 * Replace a template field's whole value by typing it into its surface.
 *
 * Every keystroke goes through page.keyboard rather than a locator action, so
 * the surface is clicked exactly once: this suite has a documented Chromium
 * wedge on the second and later click-family actions in a session (see
 * saveWebmasterSettings() in specs/webmaster-admin.spec.ts), and one forced
 * click per call is the smallest exposure to it.
 *
 * @param page      Playwright page, on the templates tab.
 * @param inputName The template input's `name` attribute.
 * @param value     Template text to type; '' just clears the field.
 * @return Promise< void >
 */
export async function fillTemplate(
	page: Page,
	inputName: string,
	value: string
): Promise< void > {
	await fillTemplateWithoutClosing( page, inputName, value );

	// Typing %% opens the variable autocomplete; close it so a later key or
	// click in the same test cannot be swallowed by the open list.
	await page.keyboard.press( 'Escape' );
}

/**
 * fillTemplate() without dismissing the autocomplete afterwards, for the
 * specs whose whole subject is the list the typed fragment opened.
 *
 * @param page      Playwright page, on the templates tab.
 * @param inputName The template input's `name` attribute.
 * @param value     Template text to type; '' just clears the field.
 * @return Promise< void >
 */
export async function fillTemplateWithoutClosing(
	page: Page,
	inputName: string,
	value: string
): Promise< void > {
	const surface = templateSurface( page, inputName );

	await surface.click( { force: true } );
	await page.keyboard.press( 'ControlOrMeta+a' );
	await page.keyboard.press( 'Backspace' );

	if ( '' !== value ) {
		await page.keyboard.type( value, { delay: 20 } );
	}
}

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

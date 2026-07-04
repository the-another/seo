import type { FullConfig } from '@playwright/test';
import { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

// Logs in as admin (RequestUtils' default admin/password credentials —
// serve-wp.sh installs WordPress with exactly those) and persists the
// authenticated storage state to STORAGE_STATE_PATH, where the config's
// use.storageState and the per-worker requestUtils fixture pick it up.
export default async function globalSetup( config: FullConfig ) {
	const { baseURL } = config.projects[ 0 ].use as { baseURL: string };

	const requestUtils = await RequestUtils.setup( {
		baseURL,
		storageStatePath: process.env.STORAGE_STATE_PATH,
	} );
	await requestUtils.setupRest();
}

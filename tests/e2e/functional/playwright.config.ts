import { defineConfig } from '@playwright/test';
import * as path from 'node:path';

// This config lives WITH the functional suite it drives; everything that
// must resolve against the repo root does so explicitly via ROOT below.
const ROOT = path.resolve( __dirname, '../../..' );

const PORT = Number( process.env.WP_E2E_PORT ) || 8881;
const BASE_URL = `http://localhost:${ PORT }`;

// RequestUtils from @wordpress/e2e-test-utils-playwright reads WP_BASE_URL
// (defaults to localhost:8889). Override it so it matches our e2e port.
process.env.WP_BASE_URL = BASE_URL;

// Written by global-setup.ts (RequestUtils.setup + setupRest). Both the
// browser contexts (use.storageState) and the per-worker requestUtils
// fixture (which reads this env var) start authenticated as admin from it.
const STORAGE_STATE_PATH = path.join(
	ROOT,
	'artifacts/storage-states/admin.json'
);
process.env.STORAGE_STATE_PATH = STORAGE_STATE_PATH;

export default defineConfig( {
	testDir: '.',
	fullyParallel: false,
	forbidOnly: !! process.env.CI,
	timeout: process.env.CI ? 60_000 : 30_000,
	// Gutenberg's pattern: retries only in CI. Locally, flakiness should
	// surface, not be masked by silent re-runs.
	retries: process.env.CI ? 2 : 0,
	// One shared WordPress install → one worker (the ecosystem standard;
	// Gutenberg and the @wordpress/scripts base config do the same).
	workers: 1,
	reporter: 'list',
	// Keep failure artifacts at the repo root: .gitignore's /test-results/
	// and CI's upload-artifact path both point there.
	outputDir: path.join( ROOT, 'test-results' ),
	use: {
		baseURL: BASE_URL,
		storageState: STORAGE_STATE_PATH,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'on',
		launchOptions: process.env.CHROMIUM_EXECUTABLE_PATH
			? {
					executablePath: process.env.CHROMIUM_EXECUTABLE_PATH,
					args: [ '--no-sandbox' ],
			  }
			: {},
	},
	projects: [
		{
			name: 'setup',
			testMatch: 'setup/**/*.setup.ts',
			retries: 0,
		},
		{
			name: 'default',
			testMatch: 'specs/**/*.spec.ts',
			dependencies: [ 'setup' ],
		},
	],
	globalSetup: './setup/global-setup.ts',
	webServer: {
		// Native-PHP WordPress (real PHP 8.3 + the official SQLite
		// drop-in) — see environment/serve-wp.sh. The script finishes installing
		// WordPress BEFORE the server binds the port, so a plain URL
		// readiness poll is truthful here (no Playground login-redirect
		// or readiness-window workarounds needed anymore).
		command: 'sh tests/e2e/functional/environment/serve-wp.sh',
		// Playwright defaults webServer.cwd to this config file's
		// directory; pin the repo root so the script path resolves.
		cwd: ROOT,
		url: BASE_URL,
		reuseExistingServer: ! process.env.CI,
		timeout: 120_000,
	},
} );

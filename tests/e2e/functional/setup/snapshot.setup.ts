/**
 * Snapshot the SQLite database so specs/zz-admin-tour.spec.ts can reset the
 * shared WordPress install before it tours the admin.
 *
 * ORDERING IS LOAD-BEARING. This file must run AFTER setup/global-setup.ts
 * has logged in, because WordPress validates the browser's auth cookie
 * against a session token stored in usermeta. A snapshot taken before that
 * login restores a database with no such token, and every admin navigation
 * in the tour would silently redirect to wp-login.php.
 *
 * Two things make that ordering hold today: globalSetup is config-level and
 * runs before any project, and within the setup project Playwright runs
 * files in path order, so provision.setup.ts (content fixtures) precedes
 * snapshot.setup.ts. If either ever changes, the tour's own
 * still-authenticated assertion is what will catch it.
 */

import * as fs from 'node:fs';
import * as path from 'node:path';
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

const ROOT = path.resolve( __dirname, '../../../..' );
const WP_DIR_FILE = path.join( ROOT, 'artifacts/e2e-wp-dir.txt' );

test( 'snapshot: database after login and content provisioning', async () => {
	expect(
		fs.existsSync( WP_DIR_FILE ),
		`${ WP_DIR_FILE } is missing — tests/e2e/functional/environment/serve-wp.sh writes it just before exec'ing the server`
	).toBe( true );

	const wpDir = fs.readFileSync( WP_DIR_FILE, 'utf8' ).trim();
	const live = path.join( wpDir, 'wp-content/database' );
	const snapshot = path.join( wpDir, '.taseo-db-snapshot' );

	expect(
		fs.existsSync( live ),
		`${ live } is missing — the SQLite drop-in keeps the database there`
	).toBe( true );

	// Copy the whole directory, never a single file: the SQLite drop-in may
	// keep -wal and -shm companions alongside the database, and restoring
	// the main file next to stale journals yields a corrupt or
	// half-rolled-back state.
	fs.rmSync( snapshot, { recursive: true, force: true } );
	fs.cpSync( live, snapshot, { recursive: true } );

	expect(
		fs.readdirSync( snapshot ).length,
		'snapshot directory should not be empty'
	).toBeGreaterThan( 0 );
} );

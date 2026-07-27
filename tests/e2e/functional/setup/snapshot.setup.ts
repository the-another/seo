/**
 * Snapshot the SQLite database so specs/zz-admin-tour.spec.ts can reset the
 * shared WordPress install before it tours the admin, and restore it again
 * afterwards.
 *
 * This snapshot is taken ONCE and kept as the permanent baseline across
 * runs — see the existsSync guard below, which skips the copy when a
 * snapshot already exists. playwright.config.ts sets
 * reuseExistingServer: !process.env.CI, so a second local `make test-e2e`
 * reuses the already-provisioned site instead of re-seeding it. If this
 * file re-copied unconditionally on every run, that second run would
 * overwrite the baseline with whatever the FIRST run's tour left behind —
 * the tour permanently changes verify_google and a post title template —
 * and every spec asserting the original seeded values would fail.
 *
 * ORDERING IS LOAD-BEARING, in two independent ways, and each is caught
 * differently:
 *
 * - This file must run AFTER setup/global-setup.ts has logged in, because
 *   WordPress validates the browser's auth cookie against a session token
 *   stored in usermeta. A snapshot taken before that login restores a
 *   database with no such token, and every admin navigation in the tour
 *   would silently redirect to wp-login.php. The tour's own
 *   still-authenticated assertion is what catches this failure.
 *
 * - This file must also run AFTER setup/provision.setup.ts has seeded
 *   fixture content. A snapshot taken before that would simply lack the
 *   fixture posts — the tour never touches them, so it would still pass,
 *   and the still-authenticated assertion above would ALSO still pass,
 *   since it has nothing to do with content provisioning. That failure is
 *   silent at the tour layer, so it is NOT what the paragraph above catches.
 *   The guard below, which asserts the fixture content exists before
 *   copying, is what catches it instead.
 *
 * Both orderings hold today because globalSetup is config-level and runs
 * before any project, and within the setup project Playwright runs files in
 * path order, so provision.setup.ts sorts before snapshot.setup.ts.
 *
 * Database-only: this does not cover anything the plugin writes under
 * wp-content/uploads/, such as the generated static sitemap chunk files —
 * those are outside the reset/restore cycle entirely.
 */

import * as fs from 'node:fs';
import * as path from 'node:path';
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

const ROOT = path.resolve( __dirname, '../../../..' );
const WP_DIR_FILE = path.join( ROOT, 'artifacts/e2e-wp-dir.txt' );

test( 'snapshot: database after login and content provisioning', async ( {
	requestUtils,
} ) => {
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

	// Guards the ordering failure the still-authenticated assertion in
	// zz-admin-tour.spec.ts does NOT catch: a snapshot taken before
	// provision.setup.ts seeds fixture content. Reuses provision.setup.ts's
	// own existence check for the same fixture post.
	const fixturePosts = await requestUtils.rest< Array< { id: number } > >( {
		method: 'GET',
		path: '/wp/v2/posts',
		params: { slug: 'seo-target-post' },
	} );
	expect(
		fixturePosts.length,
		'seo-target-post fixture not found — setup/snapshot.setup.ts must run after setup/provision.setup.ts'
	).toBeGreaterThan( 0 );

	if ( fs.existsSync( snapshot ) ) {
		// A snapshot from a previous run is the permanent baseline, not
		// something to refresh — see the header comment above. Do not "fix"
		// this back to an unconditional copy: that reintroduces the drift
		// across local re-runs that this guard exists to prevent.
		return;
	}

	// Copy the whole directory, never a single file: the SQLite drop-in may
	// keep -wal and -shm companions alongside the database, and restoring
	// the main file next to stale journals yields a corrupt or
	// half-rolled-back state.
	fs.cpSync( live, snapshot, { recursive: true } );

	expect(
		fs.readdirSync( snapshot ).length,
		'snapshot directory should not be empty'
	).toBeGreaterThan( 0 );
} );

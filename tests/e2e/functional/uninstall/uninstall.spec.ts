/**
 * Deleting the plugin leaves nothing behind.
 *
 * This spec is destructive and irreversible: it uninstalls the plugin from
 * the shared WordPress install every other spec runs against, so it lives in
 * its own directory outside specs/ and its own Playwright project, which
 * declares `dependencies: [ 'default' ]`. That dependency is the ordering
 * guarantee — Playwright finishes every other test before starting this one.
 *
 * Naming was tried first and does not work. An earlier draft sat in specs/
 * as `zzz-uninstall.spec.ts`, on the reasoning that `fullyParallel: false`
 * plus `workers: 1` runs files in alphabetical order; Playwright scheduled
 * it ahead of `zz-admin-tour.spec.ts` anyway, which then failed against an
 * install whose plugin had just been deleted. Do not move this back into
 * specs/ and rename it.
 *
 * `wp plugin uninstall` — not `wp plugin delete` — is what WordPress itself
 * runs for the "Delete" link in wp-admin: it defines WP_UNINSTALL_PLUGIN and
 * includes uninstall.php before removing any files. `delete` skips that
 * entirely and would assert nothing.
 */

import { execFileSync } from 'node:child_process';
import { existsSync, readdirSync } from 'node:fs';
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

/**
 * Locate the ephemeral WordPress install serve-wp.sh created
 * (tests/e2e/lib/provision-wp.sh: `mktemp -d /tmp/taseo-e2e-wp.XXXXXX`),
 * by the same fixed naming convention specs/sitemap.spec.ts uses.
 */
function findWpDir(): string | null {
	let entries: string[];

	try {
		entries = readdirSync( '/tmp' );
	} catch {
		return null;
	}

	const dirs = entries
		.filter( ( name ) => name.startsWith( 'taseo-e2e-wp.' ) )
		.sort();

	return dirs.length > 0 ? `/tmp/${ dirs[ dirs.length - 1 ] }` : null;
}

function wpCli( args: string[], wpDir: string ): string {
	return execFileSync(
		'wp',
		[ ...args, `--path=${ wpDir }`, '--allow-root' ],
		{ encoding: 'utf8' }
	).trim();
}

/**
 * Whether a table is still queryable.
 *
 * Asked through $wpdb rather than `wp db query`, which shells out to the
 * mysql client this SQLite-drop-in install does not have. Errors are
 * suppressed so a missing table comes back as a null result instead of a
 * notice on stdout.
 */
function tableExists( table: string, wpDir: string ): boolean {
	const out = wpCli(
		[
			'eval',
			'global $wpdb; $wpdb->suppress_errors( true ); ' +
				`$r = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}${ table }" ); ` +
				'echo null === $r ? "GONE" : "PRESENT";',
		],
		wpDir
	);

	return out.endsWith( 'PRESENT' );
}

function optionExists( option: string, wpDir: string ): boolean {
	const out = wpCli(
		[
			'eval',
			`echo false === get_option( '${ option }' ) ? "GONE" : "PRESENT";`,
		],
		wpDir
	);

	return out.endsWith( 'PRESENT' );
}

function sitemapDir( wpDir: string ): string {
	return `${ wpDir }/wp-content/uploads/taseo-sitemaps`;
}

/**
 * Force the sweep so chunk files exist on disk regardless of which specs
 * ran before this one — the same WP-CLI dispatch sitemap.spec.ts documents
 * at length in specs/sitemap.spec.ts (the Action Scheduler claim query
 * does not fire on its own
 * against the SQLite drop-in).
 */
function forceSitemapSweep( wpDir: string ): void {
	wpCli(
		[
			'eval',
			"$sweeper = \\TheAnother\\Plugin\\SEO\\Container::get_instance()->get('sitemap_sweeper'); " +
				'$sweeper->dispatch_full_regeneration();',
		],
		wpDir
	);

	const ids = wpCli(
		[
			'action-scheduler',
			'action',
			'list',
			'--hook=taseo_sitemap_sweep',
			'--status=pending',
			'--format=ids',
		],
		wpDir
	);

	if ( '' !== ids ) {
		wpCli(
			[ 'action-scheduler', 'action', 'run', ...ids.split( /\s+/ ) ],
			wpDir
		);
	}
}

test.describe( 'uninstall', () => {
	test( 'deleting the plugin removes its tables, options and sitemap files', () => {
		const wpDir = findWpDir();
		expect(
			wpDir,
			'No /tmp/taseo-e2e-wp.* install found; this spec only runs inside the e2e container.'
		).not.toBeNull();

		forceSitemapSweep( wpDir! );

		// Everything must actually be there first, or "it is gone
		// afterwards" proves nothing.
		expect( existsSync( sitemapDir( wpDir! ) ) ).toBe( true );
		expect(
			readdirSync( sitemapDir( wpDir! ) ).filter( ( f ) =>
				f.endsWith( '.xml' )
			).length
		).toBeGreaterThan( 0 );
		expect( tableExists( 'taseo_indexables', wpDir! ) ).toBe( true );
		expect( tableExists( 'taseo_sitemap_files', wpDir! ) ).toBe( true );
		expect( optionExists( 'taseo_settings', wpDir! ) ).toBe( true );
		expect( optionExists( 'taseo_db_version', wpDir! ) ).toBe( true );

		wpCli(
			[ 'plugin', 'uninstall', 'the-another-seo', '--deactivate' ],
			wpDir!
		);

		expect(
			existsSync( sitemapDir( wpDir! ) ),
			'uploads/taseo-sitemaps/ survived deletion; the webserver would keep serving stale XML from it.'
		).toBe( false );
		expect( tableExists( 'taseo_indexables', wpDir! ) ).toBe( false );
		expect( tableExists( 'taseo_sitemap_files', wpDir! ) ).toBe( false );

		for ( const option of [
			'taseo_settings',
			'taseo_db_version',
			'taseo_sitemap_db_version',
			'taseo_needs_backfill',
			'taseo_needs_rewrite_flush',
			'taseo_backfill_progress',
			'taseo_verification_method_migrated',
			'taseo_verification_migration_notice',
		] ) {
			expect( optionExists( option, wpDir! ), `${ option } survived` ).toBe(
				false
			);
		}
	} );
} );

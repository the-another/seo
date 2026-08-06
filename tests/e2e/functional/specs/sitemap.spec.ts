/**
 * Sitemap tree: live root index at /sitemap.xml referencing chunk files
 * that themselves serve valid XML.
 *
 * Chunk *assignment* is synchronous — every REST save fires
 * `taseo_indexable_synced`, which SitemapAssignment (includes/Sitemap/
 * SitemapAssignment.php) listens on to claim/create a chunk slot inline.
 * The chunk's *physical file*, though, is only written by SitemapSweeper's
 * Action Scheduler action (includes/Sitemap/SitemapSweeper.php) — and
 * `SitemapServer::render_root_index()` deliberately skips any chunk whose
 * `generated_at` is still empty, so a never-swept chunk is invisible to
 * both the index and direct requests (404).
 *
 * environment/serve-wp.sh already drains the Action Scheduler queue once,
 * but that happens BEFORE this suite's provision.setup.ts creates the
 * `seo-target-post` / `crumbs` fixtures over REST, so their chunk never
 * gets that boot-time drain. Verified empirically inside the e2e
 * container: after provisioning, /sitemap.xml came back with zero
 * `<sitemap>` entries, and neither triggering the admin "Regenerate now"
 * action nor hitting /wp-cron.php directly caused the pending
 * `taseo_sitemap_sweep` action to actually run within 10+ seconds — same
 * SQLite-drop-in/Action-Scheduler-claim-query incompatibility serve-wp.sh's
 * own comments document (its `wp action-scheduler run` note). Even on MySQL,
 * chunk files for content saved after boot would not exist yet: ordinary saves
 * only mark chunks dirty, and the physical write waits for the recurring 300s
 * sweep tick — forcing dispatch_full_regeneration() (the admin 'Regenerate now'
 * path) is required for determinism regardless of the DB backend. The only
 * thing that reliably runs it is serve-wp.sh's own workaround: driving a
 * specific action ID through WP-CLI directly (bypasses the claim query
 * entirely). beforeAll below does the same, scoped to the
 * `taseo_sitemap_sweep` hook, so this spec exercises real written sitemap
 * files instead of racing a job that never fires on its own here.
 *
 * This is deliberately a single pass, not serve-wp.sh's loop-until-empty:
 * `taseo_sitemap_sweep` is a *recurring* action (SitemapSweeper::init()
 * re-arms it every 'init' under an admin/cron context — activation.spec.ts's
 * wp-admin visits, which run first alphabetically, already do this), and
 * WP-CLI's `action run` executes under a doing-cron context that causes a
 * recurring action to immediately reschedule its next occurrence as a new
 * pending row. Looping "until no pending rows remain" against that never
 * terminates — confirmed empirically (20 passes, still not empty, while
 * the action itself had long since gone to 'complete'). One explicit
 * dispatch + one run is both necessary and sufficient: the fixture set
 * (3 chunks) is far under SitemapSweeper::BATCH_SIZE (20), so
 * handle_sweep() never itself re-chains.
 */

import { execFileSync } from 'node:child_process';
import { readdirSync } from 'node:fs';
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

/**
 * Locate the ephemeral WordPress install serve-wp.sh created
 * (tests/e2e/lib/provision-wp.sh: `mktemp -d /tmp/taseo-e2e-wp.XXXXXX`).
 * Not exported anywhere reachable from this process, so it's discovered by
 * the same fixed naming convention.
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
 * Force a "Regenerate all sitemap files now" (the exact action
 * SettingsPage's sitemap tab button dispatches — includes/Admin/
 * SettingsPage.php's handle_sitemap_regenerate()) via the container, then
 * run whatever `taseo_sitemap_sweep` action is now pending, once. Forcing
 * the dispatch ourselves (rather than relying on an earlier admin visit
 * having already armed the recurring one) keeps this deterministic
 * regardless of spec run order.
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

test.describe( 'sitemap', () => {
	test.beforeAll( () => {
		const wpDir = findWpDir();

		// Only reachable inside the e2e container this suite is designed
		// for; outside it there is nothing this step can do, and the
		// assertions below will surface the real state on their own.
		if ( null !== wpDir ) {
			forceSitemapSweep( wpDir );
		}
	} );

	test( 'root index responds with a sitemapindex', async ( { request } ) => {
		const res = await request.get( '/sitemap.xml' );
		expect( res.status() ).toBe( 200 );
		const body = await res.text();
		expect( body ).toContain( '<sitemapindex' );
		expect( body ).toContain( '-sitemap-' );
	} );

	test( 'first referenced chunk serves a urlset containing the seeded post', async ( {
		request,
	} ) => {
		const index = await ( await request.get( '/sitemap.xml' ) ).text();

		// Chunk URLs match ^([a-z0-9_-]+)-sitemap-([0-9]+)\.xml$ at site root.
		const loc = index.match( /<loc>([^<]+-sitemap-\d+\.xml)<\/loc>/ )?.[ 1 ];
		expect( loc ).toBeTruthy();

		const chunkPath = new URL( loc! ).pathname;
		const res = await request.get( chunkPath );
		expect( res.status() ).toBe( 200 );
		const body = await res.text();
		expect( body ).toContain( '<urlset' );

		// The post-type chunk must list the seeded post's pretty permalink.
		const postChunk = index.includes( 'post-sitemap-' );
		expect( postChunk ).toBe( true );
		if ( postChunk ) {
			const postRes = await request.get( '/post-sitemap-1.xml' );
			expect( postRes.status() ).toBe( 200 );
			expect( await postRes.text() ).toContain( '/seo-target-post/' );
		}
	} );

	test( 'core wp-sitemap is disabled while the plugin serves its own', async ( {
		request,
	} ) => {
		const res = await request.get( '/wp-sitemap.xml' );
		expect( res.status() ).not.toBe( 200 );
	} );

	test( 'externally pushed family appears in the index with image tags', async ( {
		request,
	} ) => {
		const index = await ( await request.get( '/sitemap.xml' ) ).text();
		expect( index ).toContain( 'e2e_family-sitemap-' );

		const res = await request.get( '/e2e_family-sitemap-1.xml' );
		expect( res.status() ).toBe( 200 );
		const body = await res.text();
		expect( body ).toContain( '/e2e-family-page/' );
		expect( body ).toContain(
			'xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"'
		);
		expect( body ).toContain( '<image:loc>' );
		expect( body ).toContain( 'e2e-image.jpg' );
	} );

	test( 'family toggle removes and restores the chunk', async ( {
		admin,
		page,
		request,
	} ) => {
		const wpDir = findWpDir();
		test.skip( null === wpDir, 'requires the e2e container' );

		// Disable the family.
		await admin.visitAdminPage( 'options-general.php', 'page=taseo&tab=sitemap' );
		await page.uncheck( 'input[name="taseo_settings[sitemap_families][]"][value="e2e_family"]' );
		await page.locator( '#submit' ).click( { force: true } );

		const disabledIndex = await ( await request.get( '/sitemap.xml' ) ).text();
		expect( disabledIndex ).not.toContain( 'e2e_family-sitemap-' );
		expect( ( await request.get( '/e2e_family-sitemap-1.xml' ) ).status() ).toBe( 404 );

		// Re-enable and drain the (async) rebuild the same way beforeAll does.
		// force: true on both actions below: the first save above already
		// drove a native form submission on this page, which is what
		// webmaster-admin.spec.ts's saveWebmasterSettings() documents as
		// wedging every later click-family action's actionability wait
		// (check/uncheck included, not just #submit) for the rest of this
		// page's life.
		await admin.visitAdminPage( 'options-general.php', 'page=taseo&tab=sitemap' );
		await page.check( 'input[name="taseo_settings[sitemap_families][]"][value="e2e_family"]', {
			force: true,
		} );
		await page.locator( '#submit' ).click( { force: true } );

		forceSitemapSweep( wpDir! );

		const restoredIndex = await ( await request.get( '/sitemap.xml' ) ).text();
		expect( restoredIndex ).toContain( 'e2e_family-sitemap-' );
		expect( ( await request.get( '/e2e_family-sitemap-1.xml' ) ).status() ).toBe( 200 );
	} );

	test( 'emptying a chunk tombstones it (410), and re-pushing resurrects it', async ( {
		request,
	} ) => {
		const wpDir = findWpDir();
		test.skip( null === wpDir, 'requires the e2e container' );

		// Tombstoning happens inline off the taseo_indexable_deleting action —
		// no sweep needed to observe the 410.
		wpCli(
			[ 'eval', "taseo_sitemap_delete_url( 'e2e_family', 1 );" ],
			wpDir!
		);

		const emptiedIndex = await ( await request.get( '/sitemap.xml' ) ).text();
		expect( emptiedIndex ).not.toContain( 'e2e_family-sitemap-' );
		expect( ( await request.get( '/e2e_family-sitemap-1.xml' ) ).status() ).toBe( 410 );

		// Resurrect: re-pushing the same family/id reclaims the tombstoned
		// chunk (find_lowest_open_chunk()'s ordinary claim path), and the
		// sweep writes its file again.
		wpCli(
			[
				'eval',
				"taseo_sitemap_sync_url( 'e2e_family', 1, array( 'permalink' => home_url( '/e2e-family-page/' ) ) );",
			],
			wpDir!
		);
		forceSitemapSweep( wpDir! );

		const resurrectedIndex = await ( await request.get( '/sitemap.xml' ) ).text();
		expect( resurrectedIndex ).toContain( 'e2e_family-sitemap-' );
		expect( ( await request.get( '/e2e_family-sitemap-1.xml' ) ).status() ).toBe( 200 );
	} );
} );

#!/usr/bin/env node
/**
 * Plugin Check (PCP) suite — runs WordPress.org's official Plugin Check
 * against the packaged release zip in a fresh, natively-provisioned
 * WordPress (real PHP + the SQLite drop-in; see provision-pcp-wp.sh), via
 * Plugin Check's WP-CLI runner.
 *
 * Why WP-CLI and not the wp-admin AJAX flow: PCP's AJAX flow swaps the
 * whole table set (users, options included) to a freshly-installed pc_-
 * prefixed environment on runtime-check requests, and nothing carries the
 * requester's auth (user row, salts, roles) into it — so its 5 runtime
 * checks always die unauthenticated ("0"). The WP-CLI runner is upstream's
 * only behat-tested path for runtime checks and needs no auth at all. See
 * the "Plugin Check runtime checks" gotcha in CLAUDE.md.
 *
 * Structure (preserved from the Playground-era suite):
 * - PCP installs BEFORE our zip; the object-cache.php drop-in (PCP's
 *   early-init hack) is re-placed before EACH run (PCP cleanup deletes it).
 * - Run 1 = PCP's full default check set. Run 2 = the 5 runtime checks
 *   explicitly: if early-init ever regresses, PCP reports those slugs as
 *   nonexistent and this run errors — a loud canary against the silent
 *   under-coverage failure mode the AJAX-era suite had. Each run also
 *   carries pcp-early-init-marker.php (--require), which prints
 *   pcp_early_init=yes|no at shutdown.
 *
 * Pass/fail: ERROR-type findings gate; WARNING-type findings are reported
 * but don't fail the suite. Structural failures (missing runs,
 * early_init=no, fatals, unparseable report lines) always gate. `wp plugin
 * check`'s own exit code is NOT trusted for findings (it may be non-zero
 * when findings exist) — parsed output is the source of truth; only spawn
 * failures and the provisioning script's exit code gate directly.
 */

import { spawnSync } from 'node:child_process';
import { existsSync, rmSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname( fileURLToPath( import.meta.url ) );
const ROOT = path.resolve( HERE, '../../..' );
const PLUGIN_SLUG = 'the-another-seo';
const ZIP_PATH = path.join( ROOT, 'build', `${ PLUGIN_SLUG }-test.zip` );
const RESULTS_FILE = path.join( ROOT, 'build', 'plugin-check-results.txt' );
const MARKER_REQUIRE = path.join( HERE, 'pcp-early-init-marker.php' );

const RUNTIME_CHECKS = [
	'enqueued_scripts_size',
	'enqueued_styles_size',
	'enqueued_styles_scope',
	'enqueued_scripts_scope',
	'non_blocking_scripts',
];

const failures = [];

function fail( message ) {
	failures.push( message );
	console.error( `✗ ${ message }` );
}

if ( ! existsSync( ZIP_PATH ) ) {
	console.error(
		`✗ Missing ${ path.relative( ROOT, ZIP_PATH ) } — run via scripts/run-e2e.sh plugin-check (or make check-plugin), which builds it.`
	);
	process.exit( 1 );
}

// A stale results file from a previous run must not survive an early exit
// below and mislead a post-mortem (e.g. CI's failure-artifact upload).
rmSync( RESULTS_FILE, { force: true } );

console.log( 'Provisioning ephemeral WordPress (native PHP + SQLite drop-in)…' );
const prov = spawnSync( 'sh', [ path.join( HERE, 'provision-pcp-wp.sh' ) ], {
	cwd: ROOT,
	encoding: 'utf8',
	timeout: 5 * 60_000,
	maxBuffer: 64 * 1024 * 1024,
} );
const provLog = `${ prov.stdout ?? '' }${ prov.stderr ?? '' }`;
if ( prov.error || prov.status !== 0 ) {
	console.error( provLog );
	console.error(
		`✗ Provisioning failed${ prov.error ? `: ${ prov.error.message }` : ` (exit ${ prov.status })` }`
	);
	process.exit( 1 );
}
const WP_DIR = ( prov.stdout ?? '' ).match( /^WP_DIR=(.+)$/m )?.[ 1 ];
if ( ! WP_DIR ) {
	console.error( provLog );
	console.error( '✗ provision-pcp-wp.sh did not report WP_DIR.' );
	process.exit( 1 );
}

const DROPIN_SRC = path.join(
	WP_DIR,
	'wp-content/plugins/plugin-check/drop-ins/object-cache.copy.php'
);
const DROPIN_DST = path.join( WP_DIR, 'wp-content/object-cache.php' );

/**
 * Places the early-init drop-in and runs one `wp plugin check` pass.
 *
 * @param {string[]} extraArgs Extra wp-cli args (e.g. --checks=…).
 * @return {?Object} { cmd, stdout, stderr, earlyInit } or null on spawn failure.
 */
function runCheck( extraArgs ) {
	// PCP's per-run cleanup deletes the drop-in — re-place before EACH run.
	const cp = spawnSync( 'cp', [ DROPIN_SRC, DROPIN_DST ], { encoding: 'utf8' } );
	if ( cp.status !== 0 ) {
		fail( `Could not place PCP's object-cache drop-in: ${ cp.stderr }` );
		return null;
	}

	const args = [
		'plugin',
		'check',
		PLUGIN_SLUG,
		'--format=json',
		`--require=${ MARKER_REQUIRE }`,
		`--path=${ WP_DIR }`,
		'--allow-root',
		// wp-cli colorizes its own stderr (e.g. WP_CLI::error()'s "Error:"
		// prefix) with ANSI SGR codes even without a TTY, which would
		// otherwise defeat the plain `/^Error:/` gate below.
		'--no-color',
		...extraArgs,
	];
	const cmd = `wp ${ args.join( ' ' ) }`;
	console.log( `Running: ${ cmd }` );
	const run = spawnSync( 'wp', args, {
		cwd: ROOT,
		encoding: 'utf8',
		timeout: 10 * 60_000,
		maxBuffer: 64 * 1024 * 1024,
	} );
	if ( run.error ) {
		fail( `Failed to run wp-cli: ${ run.error.message }` );
		return null;
	}
	return {
		cmd,
		stdout: run.stdout ?? '',
		stderr: run.stderr ?? '',
		earlyInit: /pcp_early_init=yes/.test( run.stdout ?? '' ),
	};
}

const runs = [
	runCheck( [] ),
	runCheck( [ `--checks=${ RUNTIME_CHECKS.join( ',' ) }` ] ),
].filter( Boolean );

// Keep the CI failure artifact (uploaded by .github/workflows/e2e.yml).
writeFileSync(
	RESULTS_FILE,
	runs
		.map(
			( r ) =>
				`===RUN=== early_init=${ r.earlyInit ? 'yes' : 'no' } cmd=${ r.cmd }\n${ r.stdout }\n--- stderr ---\n${ r.stderr }\n===END===\n`
		)
		.join( '' )
);

if ( runs.length !== 2 ) {
	fail(
		`Expected 2 Plugin Check runs (full set + runtime canary), completed ${ runs.length }.`
	);
}

const canary = runs.find( ( r ) => r.cmd.includes( '--checks=' ) );
if ( ! canary ) {
	fail( 'The runtime-checks canary run (--checks=…) is missing.' );
} else {
	for ( const slug of RUNTIME_CHECKS ) {
		if ( ! canary.cmd.includes( slug ) ) {
			fail( `Runtime canary run does not include the "${ slug }" check.` );
		}
	}
}

for ( const run of runs ) {
	if ( ! run.earlyInit ) {
		// Without early init, PCP silently omits all runtime checks from
		// the full run (and errors on the canary) — exactly the silent
		// under-coverage this suite exists to prevent.
		fail(
			`Plugin Check did NOT early-initialize for "${ run.cmd }" — runtime checks cannot have run.`
		);
	}
	// Fatals on stderr cut a run short — always gate. wp-cli's own phar
	// deprecation notices under PHP 8.3 are expected noise, not failures.
	// Also gate wp-cli's own `Error:` lines (WP_CLI::error()'s stderr
	// prefix): e.g. a future PCP_VERSION bump that renames/removes a
	// runtime check slug makes the canary run print
	// "Error: Invalid check slugs…" and exit nonzero — but exit codes are
	// deliberately untrusted, early_init is still yes, and stdout parses to
	// zero findings, so without this gate the suite would pass silently.
	for ( const line of run.stderr.split( '\n' ) ) {
		if ( /Fatal error/.test( line ) && ! /Deprecated/.test( line ) ) {
			fail( `PHP fatal error during "${ run.cmd }": ${ line.slice( 0, 300 ) }` );
		}
		if ( /^Error:/.test( line.trim() ) ) {
			fail( `wp-cli error during "${ run.cmd }": ${ line.slice( 0, 300 ) }` );
		}
	}
}

/**
 * Parses one run's stdout into findings.
 *
 * Body format (from `wp plugin check --format=json`):
 *   FILE: includes/foo.js
 *   [{"line":0,...,"type":"WARNING","code":"...","message":"..."}]
 *
 * @param {string} body Run stdout.
 * @param {string} cmd  The wp-cli command (for failure messages).
 * @return {Array<Object>} Findings with a `file` property added.
 */
function parseFindings( body, cmd ) {
	const findings = [];
	let currentFile = '(unknown file)';

	for ( const rawLine of body.split( '\n' ) ) {
		const line = rawLine.replace( /<br\s*\/?>/g, '' ).trim();

		const fileMatch = line.match( /^FILE: (.+)$/ );
		if ( fileMatch ) {
			currentFile = fileMatch[ 1 ].trim();
			continue;
		}

		if ( line.startsWith( '[' ) ) {
			try {
				for ( const finding of JSON.parse( line ) ) {
					findings.push( { file: currentFile, ...finding } );
				}
			} catch {
				fail(
					`Unparseable report line from "${ cmd }" (Plugin Check output format drift?): ${ line.slice( 0, 200 ) }`
				);
			}
			continue;
		}

		// A fatal anywhere means the run was cut short — always gate.
		if ( /Fatal error/.test( line ) ) {
			fail( `PHP fatal error during "${ cmd }": ${ line }` );
			continue;
		}

		/*
		 * PHP problems raised by THIS PLUGIN's code while Plugin Check
		 * exercised it (the URL-aware runtime checks actually render
		 * pages) are real defects that never appear as PCP findings —
		 * gate on them. Notices from other code (wp-cli's phar
		 * deprecations, PCP itself, core) are upstream noise we can't
		 * act on, so they're deliberately not matched.
		 */
		if (
			/(Warning|Notice|Deprecated)(<\/b>)?:/.test( line ) &&
			line.includes( `plugins/${ PLUGIN_SLUG }/` )
		) {
			fail( `PHP problem in plugin code during "${ cmd }": ${ line.slice( 0, 300 ) }` );
		}
	}

	return findings;
}

const allFindings = runs.flatMap( ( r ) => parseFindings( r.stdout, r.cmd ) );

// The canary's findings are a subset of the full run's — dedupe.
const seen = new Set();
const findings = allFindings.filter( ( f ) => {
	const key = `${ f.file }|${ f.code }|${ f.line }|${ f.column }|${ f.message }`;
	if ( seen.has( key ) ) {
		return false;
	}
	seen.add( key );
	return true;
} );

report(
	findings.filter( ( f ) => f.type === 'ERROR' ),
	findings.filter( ( f ) => f.type !== 'ERROR' )
);

/**
 * Prints the summary and exits with the suite's pass/fail status.
 *
 * @param {Array<Object>} errors   ERROR-type findings (gate).
 * @param {Array<Object>} warnings Everything else (reported only).
 */
function report( errors, warnings ) {
	console.log(
		`\nPlugin Check: ${ errors.length } error(s), ${ warnings.length } warning(s)`
	);
	for ( const f of [ ...errors, ...warnings ] ) {
		console.log(
			`  [${ f.type }] ${ f.file }:${ f.line } ${ f.code } — ${ f.message }`
		);
	}

	if ( errors.length > 0 ) {
		fail( 'Plugin Check found ERROR-level issues (see above).' );
	}

	if ( failures.length > 0 ) {
		console.error( `\n✗ Plugin Check suite FAILED (${ failures.length } failure(s)).` );
		process.exit( 1 );
	}
	console.log( '\n✓ Plugin Check suite passed.' );
	process.exit( 0 );
}

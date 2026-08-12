#!/bin/sh
# Boot a real, ephemeral WordPress for the functional e2e suite. Invoked by
# playwright.config.ts's webServer.command; requires the tests/e2e/Dockerfile
# image, including its baked wp-cli server-command package (the `wp server`
# subcommand this script execs). Provisioning (baked core, SQLite drop-in,
# config, install) lives in the shared tests/e2e/lib/provision-wp.sh — this
# script adds only the functional-suite specifics: the packaged -test zip,
# pretty permalinks, the plugin's deferred-backfill drain, and the server.
#
# Installation completes BEFORE the server binds the port — that ordering is
# what makes Playwright's plain webServer.url readiness check truthful.
set -e

PORT="${WP_E2E_PORT:-8881}"
REPO_ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"

ZIP="$REPO_ROOT/build/the-another-seo-test.zip"
if [ ! -f "$ZIP" ]; then
	echo "$ZIP missing — run via scripts/tests/e2e.sh (or make test-e2e), which builds it" >&2
	exit 1
fi

WP_SITE_URL="http://localhost:$PORT"
. "$REPO_ROOT/tests/e2e/lib/provision-wp.sh"
provision_wp

# The same packaged artifact the check-plugin suite gates — never a
# file-by-file source copy, so packaging bugs (missing file, wrong
# autoloader, bad .distignore exclusion) fail functionally too. The zip's
# inner dirname is already the real slug (dist-archive's --plugin-dirname).
wp plugin install "$ZIP" --activate --path="$WP_DIR" --allow-root

# A fixture plugin registering one custom page, so the Titles & Templates
# spec has something to assert against. It registers ONLY the page and not a
# context claim: taseo_custom_page_context runs before the built-in branches
# and would otherwise take over real requests, disturbing every other spec.
mkdir -p "$WP_DIR/wp-content/mu-plugins"
cat > "$WP_DIR/wp-content/mu-plugins/taseo-custom-page-fixture.php" <<'PHP'
<?php
/**
 * Plugin Name: TASEO custom page fixture
 * Description: Registers a custom page so the e2e suite can exercise the registry.
 */

add_filter(
	'taseo_custom_pages',
	static function ( $pages ) {
		$pages['e2e_checkout'] = 'E2E Checkout';

		return $pages;
	}
);

add_filter(
	'taseo_sitemap_families',
	static function ( $families ) {
		$families['e2e_family'] = 'E2E Family';

		return $families;
	}
);

// Push once, on init (the SEO plugin boots on plugins_loaded, so its API
// functions exist by init). The option guard keeps re-runs idempotent.
add_action(
	'init',
	static function () {
		if ( ! function_exists( 'taseo_sitemap_sync_url' ) || get_option( 'taseo_e2e_family_pushed' ) ) {
			return;
		}

		taseo_sitemap_sync_url(
			'e2e_family',
			1,
			array(
				'permalink' => home_url( '/e2e-family-page/' ),
				'images'    => array( home_url( '/wp-content/e2e-image.jpg' ) ),
			)
		);

		update_option( 'taseo_e2e_family_pushed', '1' );
	},
	20
);
PHP

# A second verification domain, standing in for the multi-brand plugin (not
# installed in this e2e environment). It registers through the same
# taseo_verification_domains filter seam the real plugin uses, so
# webmaster-domains.spec.ts can exercise per-domain verification end to end.
cat > "$WP_DIR/wp-content/mu-plugins/taseo-domains-fixture.php" <<'PHP'
<?php
/**
 * Plugin Name: TASEO e2e verification domains fixture
 *
 * Registers a second verification domain, standing in for the multi-brand
 * plugin, which is not installed in the e2e environment.
 */

add_filter(
	'taseo_verification_domains',
	static function ( $domains ) {
		$domains[] = 'brandtwo.test';

		return $domains;
	}
);
PHP

# Pin home/siteurl against wp server's own router.php, which otherwise
# defeats per-domain host resolution entirely. Root-caused by hand: WP-CLI's
# server-command bundles a router.php that adds
# `add_filter( 'option_home', fn () => 'http://' . $_SERVER['HTTP_HOST'], 20 )`
# (and the same for option_siteurl) "to trick WordPress into using the URL
# set by `wp server`, especially on multisite". That means ANY request's
# Host header becomes home_url() for that request — so a request carrying
# `Host: brandtwo.test` makes DomainRegistry::default_host() (which reads
# wp_parse_url( home_url(), PHP_URL_HOST )) resolve to 'brandtwo.test'
# too, collapsing the very default-vs-brand distinction the feature depends
# on and serving the DEFAULT domain's file under the brand domain's request
# (confirmed empirically: without this pin, requesting a brand-only
# verification file with Host: brandtwo.test 404s, because
# Settings::get_domain_value() sees host === default_host() and reads the
# flat/default keys instead of the per-domain record). No real webserver in
# front of PHP-FPM rewrites option_home from an incoming Host header this
# way; this is purely router.php's own dev convenience for reaching the
# built-in server via alternate hostnames/IPs. WordPress's own WP_HOME/
# WP_SITEURL constant support (_config_wp_home()/_config_wp_siteurl() in
# wp-includes/functions.php) is NOT a fix here — those hook the same
# option_home/option_siteurl filters at the default priority (10), so
# router.php's priority-20 closure still overrides them. The only thing
# that wins is a filter on the same hooks at a HIGHER priority, which is
# what this does — restoring the fixed, stable home_url() a production
# install actually has, regardless of which Host a request arrives on.
cat > "$WP_DIR/wp-content/mu-plugins/taseo-e2e-pin-home-url.php" <<PHP
<?php
/**
 * Plugin Name: TASEO e2e — pin home/siteurl against wp server's router
 * Description: Neutralizes wp-cli-server-command's router.php, which
 * otherwise rewrites option_home/option_siteurl to match each request's
 * Host header (priority 20) and so makes home_url() track the incoming
 * Host instead of staying fixed — see the comment in serve-wp.sh above
 * this heredoc.
 */

add_filter(
	'option_home',
	static function () {
		return '$WP_SITE_URL';
	},
	21
);

add_filter(
	'option_siteurl',
	static function () {
		return '$WP_SITE_URL';
	},
	21
);
PHP

# Keep Action Scheduler's queue OFF the HTTP path. Its claim step
# (ActionScheduler_DBStore::claim_actions) issues the MySQL-only
# `UPDATE ... JOIN (...) ... FOR UPDATE` documented at the drain loop below,
# which the SQLite translator cannot parse — and over HTTP that failure is an
# *uncaught* RuntimeException ("Unable to claim actions"), i.e. a PHP fatal
# that kills the built-in server worker mid-transaction (BEGIN with no
# matching COMMIT/ROLLBACK). Sibling requests then hit a wedged database and
# WordPress serves them through dead_db() — an HTTP 503 — so a request from
# whichever spec happens to be running fails, with the victim moving between
# runs. Two independent HTTP entry points reach that queue, so both are shut:
#
#   1. ActionScheduler_AsyncRequest_QueueRunner — dispatched on `shutdown`
#      (QueueRunner::hook_dispatch_async_request) as a loopback POST to
#      admin-ajax.php after ANY request that leaves pending actions due.
#      `allow()` ends in the filter below, which is the supported off switch.
#   2. The `action_scheduler_run_queue` WP-Cron event, spawned by ordinary
#      frontend requests — DISABLE_WP_CRON stops that.
#
# This removes only the *implicit* runners. Everything that must actually
# execute is driven explicitly through WP-CLI — the drain loop below and
# sitemap.spec.ts's forceSitemapSweep() — via `action-scheduler action run
# <id>`, whose Run_Command calls the runner's process_action() per ID and so
# never goes near claim_actions(). Determinism improves rather than suffers:
# no action can now run at a moment no test asked for.
wp config set DISABLE_WP_CRON true --raw --path="$WP_DIR" --allow-root
cat > "$WP_DIR/wp-content/mu-plugins/taseo-e2e-no-async-queue.php" <<'PHP'
<?php
/**
 * Plugin Name: TASEO e2e — no async Action Scheduler queue
 * Description: Keeps Action Scheduler's queue runner off the HTTP path; see serve-wp.sh.
 */

add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
PHP

# Pretty permalinks: the sitemap rewrites (^sitemap\.xml$ and the chunk
# pattern) need real path URLs. A direct option write via wp-cli (unlike the
# admin UI, it doesn't sanitize the structure based on server rewrite
# support); wp server's router handles the actual /pretty/paths at request
# time. These wp-cli invocations also fire init, which is where the plugin
# dispatches its activation-deferred backfill chain and flushes rewrites
# (Installer sets taseo_needs_backfill / taseo_needs_rewrite_flush flags;
# Plugin::start() consumes them on the next request — wp-cli counts).
wp rewrite structure '/%postname%/' --path="$WP_DIR" --allow-root
wp rewrite flush --path="$WP_DIR" --allow-root

# Seed verification and tracking settings so the webmaster spec has
# deterministic values to assert against. `option patch insert` writes one
# key inside the serialized taseo_settings array without clobbering the rest.
#
# taseo_settings does not exist yet at this point — the plugin only creates
# it lazily when the settings page is saved (Settings::update()). `wp option
# patch insert` on a genuinely missing option fetches WordPress's own
# get_option() default of boolean `false` as the "current value" and fails
# with `Cannot create key "..." on data type boolean` when it tries to patch
# a key into that. Seed an empty array first so the inserts below have an
# array to patch into.
wp option add taseo_settings --format=json '{}' --path="$WP_DIR" --allow-root || true
wp option patch insert taseo_settings verify_google 'googlee2etoken' --path="$WP_DIR" --allow-root
wp option patch insert taseo_settings verify_bing 'BINGE2ETOKEN' --path="$WP_DIR" --allow-root
wp option patch insert taseo_settings verify_yandex 'yandexe2etoken' --path="$WP_DIR" --allow-root
wp option patch insert taseo_settings verify_yahoo 'yahooe2etoken' --path="$WP_DIR" --allow-root
wp option patch insert taseo_settings verify_facebook 'metae2etoken' --path="$WP_DIR" --allow-root
wp option patch insert taseo_settings verify_google_file 'googlee2efile.html' --path="$WP_DIR" --allow-root
wp option patch insert taseo_settings verify_bing_file 'BINGFILETOKEN' --path="$WP_DIR" --allow-root
wp option patch insert taseo_settings analytics_ga4_id 'G-E2E12345' --path="$WP_DIR" --allow-root
wp option patch insert taseo_settings analytics_gtm_id 'GTM-E2E1234' --path="$WP_DIR" --allow-root
wp option patch insert taseo_settings meta_pixel_id '123456789012345' --path="$WP_DIR" --allow-root

# The brand domain's own per-domain record (Settings::DOMAINS_KEY), keyed by
# the normalized host the taseo-domains-fixture.php mu-plugin above pushes
# through the taseo_verification_domains filter. verify_google/_file are set
# so webmaster-domains.spec.ts can assert per-domain codes and files carry no
# inheritance; analytics_gtm_id is deliberately left OUT of this record (only
# analytics_ga4_id is set) so that same spec can assert a blank GTM field on
# the brand domain inherits the default domain's analytics_gtm_id instead.
wp option patch insert taseo_settings verification_domains --format=json \
	'{"brandtwo.test":{"verify_google":"brandtwoe2etoken","verify_google_file":"googlebrandtwo.html","analytics_ga4_id":"G-BRAND2E2E"}}' \
	--path="$WP_DIR" --allow-root

# Drain the Action Scheduler queue: the initial indexable backfill runs as a
# chain of async taseo_backfill_batch actions (each batch re-enqueues the
# next). Draining it here makes indexable rows and static sitemap chunk
# files exist BEFORE any spec runs — otherwise the sitemap spec would race
# WP-cron. Bounded loop: each pass runs everything currently due; the chain
# for this tiny site is a handful of batches, 20 passes is generous.
#
# `wp action-scheduler run --force` is unusable here: its claim step
# (ActionScheduler_DBStore::claim_actions) issues a MySQL-only
# `UPDATE ... JOIN (...) ... FOR UPDATE` that the SQLite drop-in's
# translator cannot parse. `action list`/`action run <id>` avoid the claim
# path entirely — the latter calls the runner's process_action() per ID
# directly (ActionScheduler_WPCLI_QueueRunner / Action_Command's
# Run_Command) — so this drives the same due actions through plain,
# translatable queries instead.
i=0
while true; do
	PENDING_IDS="$(wp action-scheduler action list --status=pending --format=ids --path="$WP_DIR" --allow-root)"
	if [ -z "$PENDING_IDS" ]; then
		break
	fi
	if [ $i -ge 20 ]; then
		echo "Action Scheduler queue did not drain after $i passes" >&2
		exit 1
	fi
	wp action-scheduler action run $PENDING_IDS --path="$WP_DIR" --allow-root
	i=$((i + 1))
done

# Publish WP_DIR for the Playwright process. provision_wp() mints it with
# mktemp, so nothing outside this shell can discover it, and
# setup/snapshot.setup.ts needs it to locate the SQLite database.
# artifacts/ is already gitignored (.gitignore) and excluded from the
# release zip (.distignore); global-setup.ts writes the admin storage
# state into the same directory.
mkdir -p "$REPO_ROOT/artifacts"
printf '%s\n' "$WP_DIR" > "$REPO_ROOT/artifacts/e2e-wp-dir.txt"

# Multiple built-in-server workers so WordPress's own loopback requests
# (cron spawn, site health) can't deadlock the single PHP process. The
# running server's output is spooled to a file rather than Playwright's
# console: php -S logs every request (Accepted/Closing/status lines), which
# drowns the test output. Boot-phase output above still reaches the console,
# and real PHP errors still surface on-page via WP_DEBUG_DISPLAY (and thus
# in failure screenshots); the spool file covers the rest if a run needs a
# post-mortem inside the container.
echo "TASEO e2e WordPress ready: serving $WP_DIR on port $PORT (server log: $WP_DIR/php-server.log)"
PHP_CLI_SERVER_WORKERS=6 exec wp server --host=0.0.0.0 --port="$PORT" \
	--path="$WP_DIR" --allow-root >>"$WP_DIR/php-server.log" 2>&1

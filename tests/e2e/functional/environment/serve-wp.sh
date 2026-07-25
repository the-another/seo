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

#!/bin/sh
# Provision the ephemeral WordPress for the Plugin Check suite: shared
# native-PHP provisioning (tests/e2e/lib/provision-wp.sh), then Plugin
# Check (provisioned by scripts/setup/e2e.sh, pinned — /opt/plugin-check.zip)
# installed BEFORE our -test zip: the reverse order broke PCP's activation
# with a persistent "database tables are unavailable" error (verified
# empirically in the source plugin's Playground-era suite; root cause never
# pinned). No server is started — PCP's WP-CLI runner makes no HTTP requests.
#
# Prints WP_DIR=<path> as its last line; run-plugin-check.mjs parses it.
set -e

REPO_ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"

. "$REPO_ROOT/tests/e2e/lib/provision-wp.sh"
provision_wp

wp plugin install /opt/plugin-check.zip --activate --path="$WP_DIR" --allow-root
wp plugin install "$REPO_ROOT/build/the-another-seo-test.zip" \
	--activate --path="$WP_DIR" --allow-root

echo "WP_DIR=$WP_DIR"

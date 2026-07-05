# Shared native-PHP WordPress provisioning for both e2e suites. POSIX sh,
# meant to be SOURCED (callers: functional/environment/serve-wp.sh,
# check-plugin/provision-pcp-wp.sh). Requires the tests/e2e/Dockerfile
# image. scripts/setup/e2e.sh provisions these to the canonical /opt paths
# in both environments (baked into the Docker image locally; a workflow step
# in CI): WP core at /opt/wp-core, SQLite drop-in at
# /opt/sqlite-database-integration.
#
# Contract: provision_wp() creates a fresh ephemeral install and sets
# WP_DIR. Ordering inside is load-bearing: the SQLite drop-in
# (wp-content/db.php) must exist before `wp core install`, or install
# tries to reach MySQL. Site URL comes from $WP_SITE_URL (default
# http://localhost:8881); admin credentials are exactly admin/password —
# @wordpress/e2e-test-utils-playwright RequestUtils' hardcoded defaults.

provision_wp() {
	# Fresh temp copy of the baked core: clean site every run.
	WP_DIR="$(mktemp -d /tmp/taseo-e2e-wp.XXXXXX)"
	cp -a /opt/wp-core/. "$WP_DIR"/

	# SQLite drop-in: plugin files first, then db.php generated from the
	# plugin's own db.copy template (its documented manual-install
	# procedure).
	cp -a /opt/sqlite-database-integration "$WP_DIR/wp-content/plugins/"
	sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$WP_DIR/wp-content/plugins/sqlite-database-integration#" \
		-e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
		"$WP_DIR/wp-content/plugins/sqlite-database-integration/db.copy" \
		> "$WP_DIR/wp-content/db.php"

	# DB credentials are dummies — the drop-in ignores them (hence
	# --skip-check).
	wp config create --path="$WP_DIR" --dbname=wordpress --dbuser=wordpress \
		--dbpass=wordpress --skip-check --allow-root
	# Ephemeral test instance: PHP errors straight onto the page is pure
	# upside (turns investigations into "check the screenshot").
	wp config set WP_DEBUG true --raw --path="$WP_DIR" --allow-root
	wp config set WP_DEBUG_DISPLAY true --raw --path="$WP_DIR" --allow-root

	wp core install --path="$WP_DIR" --url="${WP_SITE_URL:-http://localhost:8881}" \
		--title="TASEO E2E" --admin_user=admin --admin_password=password \
		--admin_email=admin@example.com --skip-email --allow-root
}

#!/bin/sh
# Everything the two e2e suites (functional Playwright + Plugin Check) need,
# on top of the unit/lint toolchain: PHP sqlite/gd extensions, a
# version-pinned WordPress core at /opt/wp-core, the official SQLite drop-in
# at /opt/sqlite-database-integration, WordPress.org Plugin Check at
# /opt/plugin-check.zip, wp-cli's server-command, project npm deps, and
# Playwright's own Chromium + ffmpeg.
#
# Runs IDENTICALLY in tests/e2e/Dockerfile (root) and on GitHub Actions
# ubuntu-24.04 runners (non-root + sudo). The /opt paths are the contract
# tests/e2e/lib/provision-wp.sh and run-plugin-check.mjs rely on, in both
# environments. Idempotent: each /opt artifact is skipped if present
# (rm -rf it to force a re-provision after changing a pin outside Docker).
#
# Must run from a directory containing package.json (repo root, or the
# Dockerfile's /setup scratch dir): `npx playwright install` has to resolve
# the lockfile-pinned Playwright version.
set -e

# All e2e version pins live HERE (env-overridable), not in any Dockerfile.
WP_VERSION="${WP_VERSION:-7.0}"
SQLITE_PLUGIN_VERSION="${SQLITE_PLUGIN_VERSION:-2.2.23}"
# v2.0.15, not the newest tag: same constraint as dist-archive-command in
# setup/unit.sh — v2.0.16+ declare wp-cli ^2.13, which has not been released.
WP_CLI_SERVER_COMMAND_VERSION="${WP_CLI_SERVER_COMMAND_VERSION:-v2.0.15}"
PCP_VERSION="${PCP_VERSION:-2.0.0}"

SETUP_DIR="$(cd "$(dirname "$0")" && pwd)"

if [ ! -f package.json ]; then
	echo "FATAL: setup/e2e.sh must run from a directory containing package.json" >&2
	exit 1
fi

# Base toolchain first (defines as_root, runs apt-get update).
. "$SETUP_DIR/unit.sh"

# sqlite3 (covers pdo_sqlite too) for the SQLite drop-in; gd for WP image
# handling.
as_root apt-get install -y --no-install-recommends \
	php8.3-sqlite3 \
	php8.3-gd

# Version-pinned WordPress core, downloaded once to the canonical path.
# memory_limit=-1 defends against restrictive php.ini overrides — wp-cli's
# PharData extractor needs headroom for core's several thousand files.
if [ ! -d /opt/wp-core ]; then
	TMP_WP="$(mktemp -d)"
	php -d memory_limit=-1 "$(command -v wp)" core download \
		--version="$WP_VERSION" --path="$TMP_WP/wp-core" --allow-root
	as_root mv "$TMP_WP/wp-core" /opt/wp-core
	rmdir "$TMP_WP"
fi

if [ ! -d /opt/sqlite-database-integration ]; then
	curl -fsSL "https://downloads.wordpress.org/plugin/sqlite-database-integration.${SQLITE_PLUGIN_VERSION}.zip" \
		-o /tmp/sqlite.zip
	unzip -q /tmp/sqlite.zip -d /tmp/sqlite-extract
	as_root mv /tmp/sqlite-extract/sqlite-database-integration /opt/sqlite-database-integration
	rm -rf /tmp/sqlite.zip /tmp/sqlite-extract
fi

# WordPress.org Plugin Check, pinned — the check-plugin suite installs it
# from here instead of downloading at test time (reproducible runs; new
# upstream checks arrive via deliberate pin bumps).
if [ ! -f /opt/plugin-check.zip ]; then
	curl -fsSL "https://downloads.wordpress.org/plugin/plugin-check.${PCP_VERSION}.zip" \
		-o /tmp/plugin-check.zip
	as_root mv /tmp/plugin-check.zip /opt/plugin-check.zip
fi

if ! wp package list --format=csv --fields=name --allow-root 2>/dev/null | grep -q '^wp-cli/server-command$'; then
	wp package install "https://github.com/wp-cli/server-command/archive/refs/tags/${WP_CLI_SERVER_COMMAND_VERSION}.zip" --allow-root
fi

# Playwright's own (glibc) Chromium + ffmpeg, versioned by the lockfile.
# --with-deps installs the browser's system library dependencies via apt
# (Playwright uses sudo itself when not root). ffmpeg is listed explicitly:
# the functional suite records video ('on'), and `install chromium` alone
# does not fetch Playwright's ffmpeg build.
npm ci --no-audit --no-fund
npx playwright install --with-deps chromium ffmpeg

echo "setup/e2e.sh: e2e environment ready (wp-core $WP_VERSION at /opt/wp-core, sqlite drop-in $SQLITE_PLUGIN_VERSION, PCP $PCP_VERSION)"

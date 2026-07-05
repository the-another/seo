#!/bin/sh
# Plugin Check suite — no Playwright/browser: PCP runs via its WP-CLI
# runner (see tests/e2e/check-plugin/run-plugin-check.mjs). Environment
# prerequisites come from scripts/setup/e2e.sh. Referenced by
# `make check-plugin` and .github/workflows/*.yml alike.
set -e

cd "$(dirname "$0")/../.."

. scripts/tests/lib/build-test-zip.sh

node tests/e2e/check-plugin/run-plugin-check.mjs

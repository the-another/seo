#!/bin/sh
# Shared entrypoint for both e2e Make targets (test-e2e, check-plugin), run
# inside the e2e image (tests/e2e/Dockerfile). Keeping this logic in exactly
# one script — instead of duplicated across two Make recipes — is what
# guarantees the functional suite and the Plugin Check suite can never drift
# from what CI actually runs.
#
# Usage: sh scripts/run-e2e.sh <functional|plugin-check>
set -e

SUITE="$1"

if [ "$SUITE" != "functional" ] && [ "$SUITE" != "plugin-check" ]; then
	echo "Usage: run-e2e.sh <functional|plugin-check>" >&2
	exit 1
fi

npm ci --no-audit --no-fund

# Both suites test the SAME packaged artifact: build the -test zip fresh
# every run (a stale zip would silently test old code). `composer build`
# inside this pipeline (install --no-dev + optimized autoload) is also what
# provides vendor/ on fresh CI checkouts — no separate vendor bootstrap.
# Side effect: a local vendor/ is left in no-dev state afterwards
# (`make install-dev` restores dev tooling for lint/test).
rm -f build/the-another-seo-test.zip
npm run plugin-zip:check

if [ "$SUITE" = "functional" ]; then
	npx playwright test --config tests/e2e/functional/playwright.config.ts
else
	# No Playwright/browser here: Plugin Check runs via its WP-CLI runner —
	# see tests/e2e/check-plugin/run-plugin-check.mjs.
	node tests/e2e/check-plugin/run-plugin-check.mjs
fi

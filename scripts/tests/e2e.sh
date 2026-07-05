#!/bin/sh
# Functional e2e suite (native-PHP WordPress + Playwright). Environment
# prerequisites come from scripts/setup/e2e.sh (locally that's baked into
# the tests/e2e/Dockerfile image; on GitHub runners it runs as a workflow
# step). Referenced by `make test-e2e` and .github/workflows/*.yml alike.
set -e

cd "$(dirname "$0")/../.."

. scripts/tests/lib/build-test-zip.sh

npx playwright test --config tests/e2e/functional/playwright.config.ts

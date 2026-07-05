# Shared pre-flight for BOTH e2e suites (sourced by tests/e2e.sh and
# tests/plugin-check.sh — keeping it in exactly one file is what guarantees
# the functional suite and the Plugin Check suite can never drift).
#
# Both suites test the SAME packaged artifact: build the -test zip fresh
# every run (a stale zip would silently test old code). `composer build`
# inside this pipeline (install --no-dev + optimized autoload) is also what
# provides vendor/ on fresh CI checkouts — no separate vendor bootstrap.
# Side effect: a local vendor/ is left in no-dev state afterwards
# (`make install-dev` restores dev tooling for lint/test).
#
# Expects: CWD = repo root, `set -e` active in the sourcing script.

npm ci --no-audit --no-fund

rm -f build/the-another-seo-test.zip
npm run plugin-zip:check

# The breadcrumbs block loads its editor script from dist/breadcrumbs/ (see
# blocks/breadcrumbs/block.json). A packaging regression that stripped
# dist/ from the zip would leave the block silently unregisterable at
# runtime — no gate downstream reliably fails on it. Assert the built block
# bundle is present in the artifact both suites test, right after it is built.
ZIP="build/the-another-seo-test.zip"
for required in dist/breadcrumbs/index.js dist/breadcrumbs/index.asset.php; do
	if ! unzip -l "$ZIP" | grep -qF "$required"; then
		echo "FATAL: packaged zip is missing required block asset: $required" >&2
		exit 1
	fi
done

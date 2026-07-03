.PHONY: docker-build docker-build-e2e install install-dev require update dump-autoload lint format test coverage test-e2e release check-plugin version-patch version-minor version-major all clean

# Docker image names
DOCKER_IMAGE = the-another-seo-runner:latest
DOCKER_RUN = docker run --rm -v $(PWD):/app -w /app $(DOCKER_IMAGE)

# Separate, Chromium-capable image for the e2e/Plugin Check Make targets —
# kept apart from DOCKER_IMAGE so lint/test/release stay small and fast.
DOCKER_IMAGE_E2E = the-another-seo-e2e-runner:latest
# -e CI forwards the host's CI value (unset locally, "true" in GitHub
# Actions) into the container — playwright.config.ts's retries/timeout/
# forbidOnly all key off process.env.CI, so without this the container never
# sees it and CI silently runs with local (non-CI) settings.
DOCKER_RUN_E2E = docker run --rm -e CI -v $(PWD):/app -w /app $(DOCKER_IMAGE_E2E)

# Build the e2e Docker image (Dockerfile lives with the e2e suites; build
# context stays the repo root — the image copies no project files anyway)
docker-build-e2e:
	docker build -f tests/e2e/Dockerfile -t $(DOCKER_IMAGE_E2E) .

# Build Docker image (Dockerfile lives with the unit tests it primarily
# serves; also used for lint/release/version-bump tooling)
docker-build:
	docker build -f tests/Unit/Dockerfile -t $(DOCKER_IMAGE) .

# Install composer dependencies without dev dependencies (runs in Docker)
install: docker-build
	$(DOCKER_RUN) composer install --no-dev

# Install composer dependencies including dev (needed for lint/test; runs in Docker)
install-dev: docker-build
	$(DOCKER_RUN) composer install

# Require new composer package (runs in Docker)
# Usage: make require PACKAGE="vendor/package"
require: docker-build
	$(DOCKER_RUN) composer require $(PACKAGE)

# Update composer dependencies (runs in Docker)
update: docker-build
	$(DOCKER_RUN) composer update

# Dump autoloader without dev dependencies (runs in Docker)
dump-autoload: docker-build
	$(DOCKER_RUN) composer dump-autoload --no-dev --optimize

# Run PHPCS linter (runs in Docker)
lint: docker-build
	$(DOCKER_RUN) composer phpcs

# Format code using PHPCBF (WARNING: This MODIFIES source code, runs in Docker)
format: docker-build
	$(DOCKER_RUN) composer phpcbf

# Run PHPUnit tests (runs in Docker; clears the result cache first so stale
# ordering can never mask a failure)
test: docker-build
	$(DOCKER_RUN) sh -c "rm -rf .phpunit.cache && php ./vendor/bin/phpunit"

# Run PHPUnit with coverage (runs in Docker; loads xdebug only for this
# invocation, see tests/Unit/Dockerfile). Prints a per-file text report and
# writes Clover XML to build/coverage/ for tooling.
coverage: docker-build
	$(DOCKER_RUN) sh -c "rm -rf .phpunit.cache && mkdir -p build/coverage && php -dzend_extension=xdebug.so -dxdebug.mode=coverage ./vendor/bin/phpunit --coverage-text --coverage-clover=build/coverage/clover.xml"

# Package plugin for distribution: lint + test gates, then zip into build/
# (everything runs inside Docker). Note: the zip step reinstalls composer
# without dev dependencies, so run `make install-dev` before the next
# lint/test cycle.
release: install-dev lint test
	$(DOCKER_RUN) sh -c "npm install --no-audit --no-fund && npm run plugin-zip"

# Run the functional native-PHP + Playwright suite (activation, meta/social
# tags, schema, breadcrumbs, sitemap) inside Docker. Both this target and
# check-plugin below call the same shared script — see scripts/run-e2e.sh.
test-e2e: docker-build-e2e
	$(DOCKER_RUN_E2E) sh scripts/run-e2e.sh functional

# Build a throwaway release zip (labeled -test, never the real version —
# see scripts/version-zip.js's --label flag) and run WordPress.org's
# official Plugin Check against it in a fresh WordPress instance installed
# FROM that zip — catches packaging bugs (missing files, wrong autoloader)
# a source-directory mount would never surface. Entirely inside Docker via
# the same shared script as test-e2e.
check-plugin: docker-build-e2e
	$(DOCKER_RUN_E2E) sh scripts/run-e2e.sh plugin-check

# Bump version (package.json, composer.json, plugin header, VERSION constant,
# readme.txt stable tag + changelog stub, lock files) — runs in Docker, no
# git commit; review and commit the result yourself.
version-patch: docker-build
	$(DOCKER_RUN) sh -c "npm install --no-audit --no-fund && npm run version:patch"

version-minor: docker-build
	$(DOCKER_RUN) sh -c "npm install --no-audit --no-fund && npm run version:minor"

version-major: docker-build
	$(DOCKER_RUN) sh -c "npm install --no-audit --no-fund && npm run version:major"

# Run all: install-dev, lint, test (all in Docker)
all: install-dev lint test

# Clean vendor, node_modules, caches, and build output (dist/ is gitignored
# build output here — the zip pipeline rebuilds it fresh every run)
clean:
	rm -rf vendor/ node_modules/ build/ dist/ .phpunit.cache/

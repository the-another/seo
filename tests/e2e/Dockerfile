FROM alpine:3.24.1

# PHP 8.3 toolchain, matching tests/Unit/Dockerfile exactly — this image
# builds the release zip itself (composer build + wp dist-archive) before
# running Plugin Check against it, so it needs the same PHP/Composer/wp-cli
# stack, plus Node for Playwright and Alpine's own Chromium for the browser.
RUN apk add --no-cache \
	php83 \
	php83-cli \
	php83-common \
	php83-ctype \
	php83-curl \
	php83-dom \
	php83-fileinfo \
	php83-iconv \
	php83-json \
	php83-mbstring \
	php83-openssl \
	php83-phar \
	php83-session \
	php83-simplexml \
	php83-tokenizer \
	php83-xml \
	php83-xmlreader \
	php83-xmlwriter \
	php83-zip \
	nodejs \
	npm \
	make \
	git \
	zip \
	curl

RUN ln -sf /usr/bin/php83 /usr/local/bin/php && \
	curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php && \
	php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer && \
	rm /tmp/composer-setup.php

# wp-cli + dist-archive-command, pinned to the same version as
# tests/Unit/Dockerfile for the same reason (see that file's comment:
# v3.2.x requires wp-cli ^2.13, which has not been released).
RUN curl -sSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp && \
	chmod +x /usr/local/bin/wp && \
	wp package install https://github.com/wp-cli/dist-archive-command/archive/refs/tags/v3.1.0.zip --allow-root

# --- Native-PHP WordPress for the functional e2e suite -----------------------
# (see tests/e2e/functional/environment/serve-wp.sh). Core and the official SQLite drop-in
# are baked in at build time, version-pinned, so test runs make no network
# fetches. Bumping WP_VERSION is a deliberate one-line change that CI fully
# exercises. Credentials/DB config happen at boot time, not here.
ARG WP_VERSION=7.0
ARG SQLITE_PLUGIN_VERSION=2.2.23
# v2.0.15, not the newest tag (v2.0.19): same constraint as the
# dist-archive-command pin above — v2.0.16+ declare wp-cli/wp-cli ^2.13,
# which has not been released, so they refuse to install against the 2.12.0
# wp-cli.phar baked in here. v2.0.15 is the newest release requiring ^2.12.
ARG WP_CLI_SERVER_COMMAND_VERSION=v2.0.15
ARG PCP_VERSION=2.0.0

# Extensions the SQLite drop-in and WordPress need at runtime (pdo_sqlite,
# sqlite3), plus gd for WP image handling and unzip for the plugin download.
RUN apk add --no-cache \
	php83-pdo \
	php83-pdo_sqlite \
	php83-sqlite3 \
	php83-gd \
	unzip

# `-d memory_limit=-1`: Alpine's php83 package defaults to a compiled-in
# 128M memory_limit with no php.ini override. wp-cli's PharData-based
# Extractor needs more than that to unpack WordPress core's several
# thousand files in one pass and dies with "Allowed memory size... exhausted"
# at the default limit — confirmed by reproducing it, then rerunning
# unlimited. Scoped to just this invocation rather than a global php.ini
# change, since nothing else in this image extracts an archive this size.
RUN php -d memory_limit=-1 /usr/local/bin/wp core download --version=${WP_VERSION} --path=/opt/wp-core --allow-root && \
	curl -fsSL "https://downloads.wordpress.org/plugin/sqlite-database-integration.${SQLITE_PLUGIN_VERSION}.zip" -o /tmp/sqlite.zip && \
	unzip -q /tmp/sqlite.zip -d /opt && \
	rm /tmp/sqlite.zip && \
	wp package install "https://github.com/wp-cli/server-command/archive/refs/tags/${WP_CLI_SERVER_COMMAND_VERSION}.zip" --allow-root

# WordPress.org Plugin Check, pinned — the check-plugin suite installs it
# from here instead of downloading at test time (reproducible CI, zero
# network in the run; new upstream checks arrive via deliberate ARG bumps).
RUN curl -fsSL "https://downloads.wordpress.org/plugin/plugin-check.${PCP_VERSION}.zip" -o /opt/plugin-check.zip

# Alpine's own Chromium build (musl-compatible). PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD
# stops `npm install`/`npx playwright install` from fetching Playwright's own
# glibc-linked Chromium, which does not run here. The functional suite's
# tests/e2e/functional/playwright.config.ts points launchOptions.executablePath
# at CHROMIUM_EXECUTABLE_PATH when it's set, so an unmodified host run
# (macOS/glibc Linux, this var unset) still uses Playwright's own downloaded
# browser. (The check-plugin suite is browserless — wp-cli based.)
RUN apk add --no-cache \
	chromium \
	nss \
	freetype \
	freetype-dev \
	harfbuzz \
	ca-certificates \
	ttf-freefont

ENV PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1
ENV CHROMIUM_EXECUTABLE_PATH=/usr/bin/chromium-browser

# The functional suite's Playwright config sets video: 'on', which
# shells out to an ffmpeg binary Playwright resolves from its own registry
# cache (~/.cache/ms-playwright/ffmpeg-<revision>/ffmpeg-linux) — there is no
# launchOptions-style override for it like there is for the browser itself.
# PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD (above) also blocks that binary from ever
# being fetched, and Playwright's own build is glibc-linked (won't run here)
# regardless. Fix: install Alpine's musl-native ffmpeg package and pre-seed
# the exact path Playwright's registry expects with a symlink to it, so the
# lookup's plain file-access check succeeds and the real system ffmpeg runs.
# Revision "1011" is playwright-core 1.61.1's pinned ffmpeg revision per
# package-lock.json (see node_modules/playwright-core/browsers.json after
# `npm install`) — bump this if that lockfile version ever changes.
RUN apk add --no-cache ffmpeg && \
	mkdir -p /root/.cache/ms-playwright/ffmpeg-1011 && \
	ln -sf /usr/bin/ffmpeg /root/.cache/ms-playwright/ffmpeg-1011/ffmpeg-linux && \
	touch /root/.cache/ms-playwright/ffmpeg-1011/INSTALLATION_COMPLETE

# The project directory is always bind-mounted to /app at run time, so the
# image deliberately contains no project files.
WORKDIR /app

CMD ["sh"]

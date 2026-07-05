FROM ubuntu:24.04

# Same distro as the GitHub ubuntu-24.04 runners, so scripts/setup/e2e.sh is
# the single environment definition for both the local `make` flow (this
# image) and CI's native jobs. All version pins (WP core, SQLite drop-in,
# Plugin Check, wp-cli packages) live in that script, not here.
ENV DEBIAN_FRONTEND=noninteractive

# package.json + package-lock.json only (not the source tree): the setup
# script needs them to `npm ci` and bake the lockfile-pinned Playwright
# Chromium + ffmpeg into /root/.cache/ms-playwright at build time. The
# scratch node_modules is discarded — the real one is created in the
# bind-mounted /app at run time — so this layer only busts when the
# lockfile or the setup scripts change.
COPY package.json package-lock.json /setup/
COPY scripts/setup /setup/scripts/setup
WORKDIR /setup
RUN sh scripts/setup/e2e.sh && rm -rf /setup/node_modules /var/lib/apt/lists/*

# Chromium refuses its sandbox as root, and this container runs as root.
# playwright.config.ts passes --no-sandbox exactly when this is set; host
# runs (macOS) and CI's non-root runner stay sandboxed.
ENV THE_ANOTHER_SEO_CHROMIUM_NO_SANDBOX=1

# The project directory is always bind-mounted to /app at run time.
WORKDIR /app

CMD ["sh"]

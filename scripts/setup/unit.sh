#!/bin/sh
# Toolchain for lint / unit tests / release-zip builds: PHP 8.3 CLI (+ the
# extensions the composer/phpcs/phpunit/wp-cli stack needs), Composer,
# Node >= 24, wp-cli + dist-archive-command.
#
# Runs IDENTICALLY in both environments that need this toolchain:
#   - tests/Unit/Dockerfile (ubuntu:24.04 base, as root) — the local `make` flow
#   - GitHub Actions ubuntu-24.04 runners (non-root, passwordless sudo)
# Idempotent: a present-and-adequate tool is left alone, so re-runs are cheap
# and the GH runner's preinstalled toolchain is reused where it fits.
#
# All version pins for this toolchain live HERE (env-overridable), not in
# any Dockerfile — the Dockerfiles just run this script.
set -e

# dist-archive-command v3.1.0: the newest release installable against
# wp-cli 2.12 (the latest wp-cli that exists) — 3.2.x declares wp-cli ^2.13,
# which has not been released. Revisit when wp-cli 2.13 ships.
DIST_ARCHIVE_COMMAND_VERSION="${DIST_ARCHIVE_COMMAND_VERSION:-v3.1.0}"

export DEBIAN_FRONTEND=noninteractive

# Root (Docker build) runs commands directly; non-root (GH runner) gets sudo.
as_root() {
	if [ "$(id -u)" = "0" ]; then
		"$@"
	else
		sudo "$@"
	fi
}

as_root apt-get update

# php8.3-* explicitly (not plain `php`): Ubuntu 24.04's native series is 8.3
# today, but pinning the package names makes a distro-default drift loud
# instead of silent. php8.3-xml covers dom/simplexml/xmlreader/xmlwriter.
as_root apt-get install -y --no-install-recommends \
	ca-certificates \
	curl \
	git \
	unzip \
	zip \
	php8.3-cli \
	php8.3-curl \
	php8.3-mbstring \
	php8.3-xml \
	php8.3-zip \
	php8.3-xdebug

# The plugin targets PHP 8.3; running the toolchain on any other series
# would silently lint/test against the wrong runtime. ubuntu-24.04 (runner
# label AND Docker base) is what guarantees this passes.
if ! php -v | head -n 1 | grep -q "PHP 8\.3\."; then
	echo "FATAL: PHP 8.3.x required, got: $(php -v | head -n 1)" >&2
	exit 1
fi

# Ubuntu auto-enables xdebug on install, which would slow every lint/test
# run. Disable it; `make coverage` loads it explicitly per invocation
# (php -dzend_extension=xdebug.so -dxdebug.mode=coverage).
as_root phpdismod -v 8.3 xdebug 2>/dev/null || true

if ! command -v composer >/dev/null 2>&1; then
	curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
	as_root php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
	rm -f /tmp/composer-setup.php
fi

# Node >= 24 (the version the aucteeno pipelines standardized on). The GH
# runner ships a current Node — leave it alone; the Docker base's apt Node
# is too old, so install from NodeSource there.
NODE_MAJOR="$(node -e 'console.log(process.versions.node.split(".")[0])' 2>/dev/null || echo 0)"
if [ "$NODE_MAJOR" -lt 24 ]; then
	curl -fsSL https://deb.nodesource.com/setup_24.x -o /tmp/nodesource-setup.sh
	as_root bash /tmp/nodesource-setup.sh
	rm -f /tmp/nodesource-setup.sh
	as_root apt-get install -y nodejs
fi

if ! command -v wp >/dev/null 2>&1; then
	curl -sSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /tmp/wp-cli.phar
	as_root install -m 0755 /tmp/wp-cli.phar /usr/local/bin/wp
	rm -f /tmp/wp-cli.phar
fi

if ! wp package list --format=csv --fields=name --allow-root 2>/dev/null | grep -q '^wp-cli/dist-archive-command$'; then
	wp package install "https://github.com/wp-cli/dist-archive-command/archive/refs/tags/${DIST_ARCHIVE_COMMAND_VERSION}.zip" --allow-root
fi

echo "setup/unit.sh: toolchain ready (php $(php -r 'echo PHP_VERSION;'), node $(node --version), composer $(composer --version --no-ansi 2>/dev/null | head -n 1))"

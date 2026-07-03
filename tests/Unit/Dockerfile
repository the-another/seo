FROM alpine:3.24.1

# Base is pinned to the current latest Alpine release (bump deliberately, not
# via :latest). PHP 8.3 toolchain for composer/phpcs/phpunit — php83 packages
# match the plugin's production PHP target regardless of the distro default.
# Node is for the npm script runner (plugin-zip / version-bump pipeline).
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
	php83-pecl-xdebug \
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

# Alpine's php83-pecl-xdebug package ships with its zend_extension line
# commented out in conf.d, so xdebug is installed but NOT loaded by default —
# `make lint`/`make test` are unaffected. `make coverage` loads it explicitly
# per-invocation via `php -dzend_extension=xdebug.so -dxdebug.mode=coverage`.

# Alpine's `composer` package depends on the distro's default PHP (8.5 on
# 3.24+), which would silently run the toolchain on a different PHP series
# than production. Pin the CLI to php83 and install Composer under it.
RUN ln -sf /usr/bin/php83 /usr/local/bin/php && \
	curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php && \
	php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer && \
	rm /tmp/composer-setup.php

# wp-cli + dist-archive-command: the release zip is built from .distignore
# via `wp dist-archive`. The command is pinned to v3.1.0, the newest release
# installable against wp-cli 2.12 (the latest wp-cli release that exists):
# dist-archive-command 3.2.x declares wp-cli ^2.13, a version that has not
# been released — revisit the pin when wp-cli 2.13 ships.
RUN curl -sSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp && \
	chmod +x /usr/local/bin/wp && \
	wp package install https://github.com/wp-cli/dist-archive-command/archive/refs/tags/v3.1.0.zip --allow-root

# The project directory is always bind-mounted to /app at run time, so the
# image deliberately contains no project files (a COPY here would only bloat
# the image and bust the cache on every source change).
WORKDIR /app

CMD ["sh"]

FROM ubuntu:24.04

# Same distro as the GitHub ubuntu-24.04 runners, so scripts/setup/unit.sh
# is the single toolchain definition for both environments (PHP 8.3 is
# Ubuntu 24.04's native series — the plugin's production target). All
# version pins live in the setup script, not here.
ENV DEBIAN_FRONTEND=noninteractive

COPY scripts/setup /setup/scripts/setup
RUN sh /setup/scripts/setup/unit.sh && rm -rf /var/lib/apt/lists/*

# The project directory is always bind-mounted to /app at run time, so the
# image deliberately contains no project files (a COPY here would only bloat
# the image and bust the cache on every source change).
WORKDIR /app

CMD ["sh"]

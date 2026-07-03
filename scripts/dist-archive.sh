#!/bin/sh
# Build the distribution zip from .distignore via wp-cli dist-archive.
#
# The tree is first staged without .git/node_modules/build to a temp dir:
# our pinned dist-archive-command v3.1.0 has a path-handling bug when the
# source contains a .git directory, and the staged copy also keeps those
# dirs out of the archive scanner entirely. (The pin exists because
# dist-archive-command v3.2.x requires wp-cli ^2.13, and the latest
# released wp-cli is 2.12 — see tests/Unit/Dockerfile.) .distignore is part of
# the staged tree, so all other exclusions still come from it.
set -e

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT

tar cf - --exclude='.git' --exclude='node_modules' --exclude='build' . | tar xf - -C "$STAGE"

wp dist-archive "$STAGE" "$(pwd)/the-another-seo.zip" \
	--plugin-dirname=the-another-seo --force --allow-root > /dev/null

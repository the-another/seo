#!/bin/sh
# PHPCS lint (errors gate, warnings don't). Ensures dev composer deps itself:
# vendor/ may be missing (fresh CI checkout) or left in no-dev state by a
# zip build.
set -e

cd "$(dirname "$0")/../.."

if [ ! -x vendor/bin/phpcs ]; then
	composer install --no-interaction --no-progress
fi

composer phpcs

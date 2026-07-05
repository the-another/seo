#!/bin/sh
# PHPUnit unit suite. Clears the result cache first so stale ordering can
# never mask a failure. Ensures dev composer deps itself: vendor/ may be
# missing (fresh CI checkout) or left in no-dev state by a zip build.
set -e

cd "$(dirname "$0")/../.."

if [ ! -x vendor/bin/phpunit ]; then
	composer install --no-interaction --no-progress
fi

rm -rf .phpunit.cache
php ./vendor/bin/phpunit

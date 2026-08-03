#!/usr/bin/env bash
#
# Runs the unit suite inside the Nextcloud container, which already has PHP 8.5,
# intl and the OCP interfaces the repository is typed against. This app has no PHP
# dependencies of its own, so there is no composer.json and no vendor/ — just
# PHPUnit, fetched once.
#
# Usage: scripts/test.sh [phpunit args…]     (override with EPI_CONTAINER=…)
set -euo pipefail

CONTAINER="${EPI_CONTAINER:-apsconecta-gestion-nextcloud-1}"
HERE="$(cd "$(dirname "$0")/.." && pwd)"
PHPUNIT_VERSION=11

if [ ! -f "$HERE/tools/phpunit.phar" ]; then
	printf 'Downloading PHPUnit %s…\n' "$PHPUNIT_VERSION"
	mkdir -p "$HERE/tools"
	curl -sSL -o "$HERE/tools/phpunit.phar" "https://phar.phpunit.de/phpunit-$PHPUNIT_VERSION.phar"
fi

exec docker exec -w /var/www/html/custom_apps/epidemiologia "$CONTAINER" \
	php tools/phpunit.phar "$@"

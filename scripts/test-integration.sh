#!/usr/bin/env bash
#
# Runs the integration suite — same container as scripts/test.sh, its own
# phpunit config: the mapper tests boot the real Nextcloud and build each
# test an in-memory SQLite database (see tests/integration/).
#
# Usage: scripts/test-integration.sh [phpunit args…]   (override with EPI_CONTAINER=…)
set -euo pipefail

CONTAINER="${EPI_CONTAINER:-aps-conecta-nextcloud}"
HERE="$(cd "$(dirname "$0")/.." && pwd)"
PHPUNIT_VERSION=11

if [ ! -f "$HERE/tools/phpunit.phar" ]; then
	printf 'Downloading PHPUnit %s…\n' "$PHPUNIT_VERSION"
	mkdir -p "$HERE/tools"
	curl -sSL -o "$HERE/tools/phpunit.phar" "https://phar.phpunit.de/phpunit-$PHPUNIT_VERSION.phar"
fi

exec docker exec -w /var/www/html/custom_apps/epidemiologia "$CONTAINER" \
	php tools/phpunit.phar --config phpunit.integration.xml "$@"

#!/usr/bin/env bash
#
# Regenerates openapi.json from the attributes and docblocks on ApiController.
#
# The spec is the contract the front webpage reads, so it must never be written by
# hand: a hand-edited spec drifts from the code silently, which is worse than no
# spec at all. It is generated, committed, and regenerated whenever the controller
# changes.
#
# The generator is a *dev* dependency, fetched into tools/ like PHPUnit and
# gitignored the same way. The app itself still has no PHP dependencies and no
# composer autoloader at runtime — that property is deliberate and unchanged.
#
# Usage: scripts/openapi.sh     (override with EPI_CONTAINER=…)
set -euo pipefail

CONTAINER="${EPI_CONTAINER:-apsconecta-gestion-nextcloud-1}"
APP=/var/www/html/custom_apps/epidemiologia

run() { docker exec -w "$APP" -u www-data "$CONTAINER" "$@"; }

if ! run test -f tools/composer.phar; then
	printf 'Descargando Composer…\n'
	run mkdir -p tools
	run sh -c 'curl -sSL -o tools/composer.phar https://getcomposer.org/composer-stable.phar'
fi

if ! run test -f tools/openapi/vendor/bin/generate-spec; then
	printf 'Instalando nextcloud/openapi-extractor…\n'
	run mkdir -p tools/openapi
	run sh -c 'cd tools/openapi && COMPOSER_HOME=/tmp/composer php ../composer.phar \
		require --quiet --no-interaction nextcloud/openapi-extractor'
fi

printf 'Generando openapi.json…\n'
run sh -c 'php tools/openapi/vendor/bin/generate-spec . openapi.json'

printf '\n'
run sh -c 'php -r "\$s=json_decode(file_get_contents(\"openapi.json\"),true);
	printf(\"  %s v%s — %d rutas\n\", \$s[\"info\"][\"title\"], \$s[\"info\"][\"version\"], count(\$s[\"paths\"]));
	foreach (array_keys(\$s[\"paths\"]) as \$p) { printf(\"    %s\n\", \$p); }"'

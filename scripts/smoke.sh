#!/usr/bin/env bash
#
# Proves the wiring that no unit test can see: the app enables, the contract route
# answers with real data through the real cache, the daily probe is registered, and
# — the reason this script exists — the response actually permits the tablero's
# frame domain.
#
# That CSP line is one line in a controller. Without it the tablero tab renders
# blank, which looks to everyone like MINSAL being down rather than like us having
# broken it. Nothing else in the suite can catch its absence.
#
# Output of every command is captured before it is matched, never piped into
# `grep -q`: grep exits on the first match, the writer dies of SIGPIPE, and under
# `pipefail` a passing check reports as a failure.
#
# Usage: scripts/smoke.sh     (override with EPI_CONTAINER=… EPI_USER=…)
set -uo pipefail

CONTAINER="${EPI_CONTAINER:-apsconecta-gestion-nextcloud-1}"
USER_ID="${EPI_USER:-admin}"
APP=epidemiologia
BASE="http://localhost/apps/$APP"
# OCS: the contract other apps and the future dashboard read. The header is not
# optional — without it Nextcloud answers 412 before reaching the controller.
OCS="http://localhost/ocs/v2.php/apps/$APP/api/v1"
OCSH="OCS-APIRequest: true"
TOKEN_NAME=epi-smoke

occ() { docker exec -u www-data "$CONTAINER" php occ "$@" 2>/dev/null; }
web() { docker exec -u www-data "$CONTAINER" curl -sS -u "$USER_ID:$TOKEN" "$@" 2>/dev/null; }

failures=0
pass() { printf '  ok    %s\n' "$1"; }
fail() { printf '  FALLA %s\n' "$1"; failures=$((failures + 1)); }

# $1 haystack, $2 needle, $3 message on success, $4 message on failure
expect() {
	case "$1" in
		*"$2"*) pass "$3" ;;
		*) fail "$4" ;;
	esac
}

# Revoke the throwaway credential however we leave.
cleanup() {
	[ -n "${TOKEN_ID:-}" ] && occ user:auth-tokens:delete "$USER_ID" "$TOKEN_ID" >/dev/null 2>&1
	return 0
}
trap cleanup EXIT

printf '\nEpidemiología — verificación en %s\n\n' "$CONTAINER"

occ app:enable "$APP" >/dev/null 2>&1
expect "$(occ app:list --enabled)" " $APP:" \
	"la app está instalada y habilitada" \
	"la app no aparece como habilitada"

# info.xml registers this on install/update only, and enabling an already-enabled
# app is a no-op — so the fix is a disable followed by an enable, not a re-run.
expect "$(occ background-job:list)" 'ProbeJob' \
	"el trabajo diario de sondeo está registrado" \
	"ProbeJob no está registrado: no se detectarán cambios en MINSAL.
        Corrígelo con: occ app:disable $APP && occ app:enable $APP"

# A throwaway app password, minted and revoked here, so running this needs no
# credentials from whoever runs it.
TOKEN="$(occ user:add-app-password "$USER_ID" --name="$TOKEN_NAME" -n | tail -1 | tr -d '\r')"
# tail, not head: earlier runs may have left rows with the same name, and deleting
# the oldest of those would leak a credential on every run.
TOKEN_ID="$(occ user:auth-tokens:list "$USER_ID" | grep -F "| $TOKEN_NAME " | tail -1 \
	| awk -F'|' '{gsub(/[^0-9]/, "", $2); print $2}')"
if [ -z "$TOKEN" ]; then
	fail "no se pudo crear una contraseña de aplicación para $USER_ID"
	printf '\n%d comprobación(es) fallida(s)\n\n' "$failures"
	exit 1
fi

alerts="$(web -H "$OCSH" "$OCS/alerts?format=json")"
# grep -c counts matching *lines*, and the whole payload is one line.
count="$(printf '%s' "$alerts" | grep -o '"title"' | wc -l | tr -d ' ')"
[ "$count" -ge 1 ] \
	&& pass "/ocs/…/api/v1/alerts responde con $count alertas" \
	|| fail "/ocs/…/api/v1/alerts no devolvió ninguna alerta"

expect "$alerts" '"degraded":false' \
	"los datos vienen de MINSAL sin degradación" \
	"el feed está degradado — MINSAL no respondió, o dejó de parsearse"

expect "$(web -H "$OCSH" "$OCS/reports?format=json")" '"se"' \
	"/ocs/…/api/v1/reports expone la semana epidemiológica" \
	"/ocs/…/api/v1/reports no expone la semana epidemiológica"

# The envelope is the contract, not an implementation detail: a consumer parses
# ocs.data, so losing it is a breaking change even if the payload is intact.
expect "$alerts" '"ocs"' \
	"la respuesta viene envuelta en el sobre OCS" \
	"la respuesta NO trae el sobre OCS"

# Guards the header requirement itself. 412 is Nextcloud refusing before the
# controller runs; a 200 here would mean the OCS protection is not in effect.
expect "$(web -o /dev/null -w '%{http_code}' "$OCS/alerts?format=json")" '412' \
	"sin la cabecera OCS-APIRequest la API responde 412" \
	"sin la cabecera OCS-APIRequest la API NO responde 412"

# The old plain-Controller route is gone on purpose. If it answers again, some
# routes.php came back and we have two contracts.
expect "$(web -o /dev/null -w '%{http_code}' "$BASE/api/v1/alerts")" '404' \
	"la ruta antigua /apps/…/api/v1 ya no existe" \
	"la ruta antigua sigue respondiendo — hay dos contratos"

# The navigation endpoint reports an icon path whether or not the file exists, so
# it proves nothing. What matters is that the path actually serves an SVG: without
# it the app menu falls back to core's generic placeholder.
# Note the assets live under /custom_apps/, not /apps/ — the latter 404s with an
# HTML error page that negative assertions pass against quite happily.
icon="$(web -o /dev/null -w '%{http_code} %{content_type} %{size_download}' \
	"http://localhost/custom_apps/$APP/img/$APP.svg")"
case "$icon" in
	'200 image/svg+xml'*) pass "el icono de la app se sirve ($icon)" ;;
	*) fail "el icono no se sirve ($icon) — el menú mostraría el marcador genérico" ;;
esac

# The bundle in js/ is committed — that IS the deployment. So the failure this
# guards is src/ moving without a rebuild: the running app would then not be the
# code in this repo, and nothing else here can see that. Upstream Nextcloud runs
# the same assertion in CI (rebuild, then require a clean tree); we have no CI, so
# it lives here. Skipped when node is absent, because that is a dev-machine tool
# and this script must still be runnable on a box that only has docker.
if command -v npm >/dev/null 2>&1 && [ -d node_modules ]; then
	npm run build --silent >/dev/null 2>&1
	drift="$(git status --porcelain js/ css/ 2>/dev/null)"
	if [ -z "$drift" ]; then
		pass "el bundle de js/ coincide con src/"
	else
		fail "js/ está desactualizado respecto a src/ — ejecuta 'npm run build' y haz commit"
		printf '%s\n' "$drift" | sed 's/^/        /'
	fi
else
	printf '  --    sin node_modules: no se comprueba si el bundle está al día\n'
fi

# The one this script is really for. Read the CSP header specifically, so that a
# stray mention of the host anywhere else on the page cannot make this pass.
csp="$(web -D - -o /dev/null "$BASE/" | tr -d '\r' | grep -i '^content-security-policy:')"
expect "$csp" 'app.powerbi.com' \
	"la CSP permite el tablero (app.powerbi.com)" \
	"la CSP NO permite app.powerbi.com — el tablero se vería en blanco"

if [ "$failures" -eq 0 ]; then
	printf '\nTodo correcto.\n\n'
else
	printf '\n%d comprobación(es) fallida(s)\n\n' "$failures"
	exit 1
fi

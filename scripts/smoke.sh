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

alerts="$(web "$BASE/api/v1/alerts")"
# grep -c counts matching *lines*, and the whole payload is one line.
count="$(printf '%s' "$alerts" | grep -o '"title"' | wc -l | tr -d ' ')"
[ "$count" -ge 1 ] \
	&& pass "/api/v1/alerts responde con $count alertas" \
	|| fail "/api/v1/alerts no devolvió ninguna alerta"

expect "$alerts" '"degraded":false' \
	"los datos vienen de MINSAL sin degradación" \
	"el feed está degradado — MINSAL no respondió, o dejó de parsearse"

expect "$(web "$BASE/api/v1/reports")" '"se"' \
	"/api/v1/reports expone la semana epidemiológica" \
	"/api/v1/reports no expone la semana epidemiológica"

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

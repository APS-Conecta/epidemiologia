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
fail() { printf '  FAIL  %s\n' "$1"; failures=$((failures + 1)); }

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

printf '\nEpidemiología — checking %s\n\n' "$CONTAINER"

occ app:enable "$APP" >/dev/null 2>&1
expect "$(occ app:list --enabled)" " $APP:" \
	"the app is installed and enabled" \
	"the app does not show as enabled"

# info.xml registers this on install/update only, and enabling an already-enabled
# app is a no-op — so the fix is a disable followed by an enable, not a re-run.
expect "$(occ background-job:list)" 'ProbeJob' \
	"the daily probe job is registered" \
	"ProbeJob is not registered: changes to the sources will go undetected.
        Fix it with: occ app:disable $APP && occ app:enable $APP"

# A throwaway app password, minted and revoked here, so running this needs no
# credentials from whoever runs it.
TOKEN="$(occ user:add-app-password "$USER_ID" --name="$TOKEN_NAME" -n | tail -1 | tr -d '\r')"
# tail, not head: earlier runs may have left rows with the same name, and deleting
# the oldest of those would leak a credential on every run.
TOKEN_ID="$(occ user:auth-tokens:list "$USER_ID" | grep -F "| $TOKEN_NAME " | tail -1 \
	| awk -F'|' '{gsub(/[^0-9]/, "", $2); print $2}')"
if [ -z "$TOKEN" ]; then
	fail "could not create an app password for $USER_ID"
	printf '\n%d check(s) failed\n\n' "$failures"
	exit 1
fi

sources="$(web -H "$OCSH" "$OCS/sources?format=json")"
# grep -c counts matching *lines*, and the whole payload is one line.
count="$(printf '%s' "$sources" | grep -o '"title"' | wc -l | tr -d ' ')"
[ "$count" -ge 1 ] \
	&& pass "/ocs/…/api/v1/sources answers with $count items" \
	|| fail "/ocs/…/api/v1/sources returned no items at all"

# Every source keyed by its own id. A missing key means Sources::ALL and the
# payload have drifted apart, which is the failure the registry exists to stop.
for source in alerts reports sanitary_alerts; do
	expect "$sources" "\"$source\"" \
		"source $source is in the response" \
		"source $source is NOT in the response"
done

expect "$sources" '"degraded":false' \
	"the data arrives undegraded" \
	"some source is degraded — it did not answer, or stopped parsing"

expect "$sources" '"se"' \
	"the reports expose the semana epidemiológica" \
	"the reports do not expose the semana epidemiológica"

# The envelope is the contract, not an implementation detail: a consumer parses
# ocs.data, so losing it is a breaking change even if the payload is intact.
expect "$sources" '"ocs"' \
	"the response comes wrapped in the OCS envelope" \
	"the response does NOT carry the OCS envelope"

# Guards the header requirement itself. 412 is Nextcloud refusing before the
# controller runs; a 200 here would mean the OCS protection is not in effect.
expect "$(web -o /dev/null -w '%{http_code}' "$OCS/sources?format=json")" '412' \
	"without the OCS-APIRequest header the API answers 412" \
	"without the OCS-APIRequest header the API does NOT answer 412"

# The old plain-Controller route is gone on purpose. If it answers again, some
# routes.php came back and we have two contracts.
expect "$(web -o /dev/null -w '%{http_code}' "$BASE/api/v1/alerts")" '404' \
	"the old /apps/…/api/v1 route is gone" \
	"the old route still answers — there are two contracts"

# And the per-source routes 0.2.0 published are gone too, with no aliases — see
# ADR-0006. A 200 here means someone re-added a contract we deliberately broke.
for gone in alerts reports; do
	expect "$(web -H "$OCSH" -o /dev/null -w '%{http_code}' "$OCS/$gone?format=json")" '404' \
		"0.2.0's /ocs/…/api/v1/$gone route is gone" \
		"/ocs/…/api/v1/$gone still answers — there are two contracts"
done

# The navigation endpoint reports an icon path whether or not the file exists, so
# it proves nothing. What matters is that the path actually serves an SVG: without
# it the app menu falls back to core's generic placeholder.
# Note the assets live under /custom_apps/, not /apps/ — the latter 404s with an
# HTML error page that negative assertions pass against quite happily.
icon="$(web -o /dev/null -w '%{http_code} %{content_type} %{size_download}' \
	"http://localhost/custom_apps/$APP/img/$APP.svg")"
case "$icon" in
	'200 image/svg+xml'*) pass "the app icon is served ($icon)" ;;
	*) fail "the icon is not served ($icon) — the menu would show the generic placeholder" ;;
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
		pass "the js/ bundle matches src/"
	else
		fail "js/ is behind src/ — run 'npm run build' and commit the result"
		printf '%s\n' "$drift" | sed 's/^/        /'
	fi
else
	printf '  --    no node_modules: not checking whether the bundle is current\n'
fi

# The one this script is really for. Read the CSP header specifically, so that a
# stray mention of the host anywhere else on the page cannot make this pass.
csp="$(web -D - -o /dev/null "$BASE/" | tr -d '\r' | grep -i '^content-security-policy:')"
expect "$csp" 'app.powerbi.com' \
	"the CSP allows the tablero (app.powerbi.com)" \
	"the CSP does NOT allow app.powerbi.com — the tablero would render blank"

if [ "$failures" -eq 0 ]; then
	printf '\nAll good.\n\n'
else
	printf '\n%d check(s) failed\n\n' "$failures"
	exit 1
fi

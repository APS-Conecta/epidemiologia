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

# stderr is KEPT, not discarded. `2>/dev/null` here meant a container that is down, or
# an occ that errored, was reported as "the app does not show as enabled" — asserting a
# cause nobody checked. That is B-003 in the platform's BUGS.md, recurring in this script.
occ() { docker exec -u www-data "$CONTAINER" php occ "$@"; }

# The credential arrives on STDIN, as a curl config file, so it is never a command-line
# argument: argv is readable from /proc/*/cmdline by any local user for the length of the
# run. Not the environment either — /proc/*/environ is the same shape of leak, one step
# narrower. App passwords are alphanumeric, so nothing here needs quoting beyond this.
#
# --max-time is not tuning, it is the difference between a gate that fails and a gate that
# hangs. /sources walks 24 ISP feeds plus up to 2 backfill pages, each bounded at 15 s in
# IspRepository — 435 s of wall clock in the worst case, and an observed run took 363 s
# with this script printing nothing at all meanwhile. 600 is IspRepository::LOCK_FOR: the
# window that class already reserves for one walk. Anything under ~440 s would kill a slow
# but succeeding walk and report it as a defect of ours.
web() {
	printf 'user = "%s:%s"\n' "$USER_ID" "$TOKEN" \
		| docker exec -i -u www-data "$CONTAINER" curl -sS --max-time 600 -K - "$@" 2>/dev/null
}

failures=0
pass() { printf '  ok    %s\n' "$1"; }
fail() { printf '  FAIL  %s\n' "$1"; failures=$((failures + 1)); }
# The third outcome, in the shape the bundle check below already prints it: a check
# that could not be made, named, and counted against nothing.
skip() { printf '  --    %s\n' "$1"; }

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

# Three of the assertions below can only be made when MINSAL and the ISP answered.
# The envelope says when they did not — degraded:true, served with the last good copy
# — and a release gate that failed there would be calling a foreign publisher's outage
# a regression of ours. It names what it could not check instead.
#
# This reads the RESPONSE, not the network: a web() that hit its own --max-time returns
# nothing, matches nothing here, and every assertion still runs and still fails. An
# outage is theirs; a hang is ours, and only one of the two is allowed to be quiet.
degraded=''
case "$sources" in
	*'"degraded":true'*) degraded=1 ;;
esac

# grep -c counts matching *lines*, and the whole payload is one line.
count="$(printf '%s' "$sources" | grep -o '"title"' | wc -l | tr -d ' ')"
if [ -n "$degraded" ]; then
	skip "a fuente is degraded: not checking how many items /ocs/…/api/v1/sources answers with"
elif [ "$count" -ge 1 ]; then
	pass "/ocs/…/api/v1/sources answers with $count items"
else
	fail "/ocs/…/api/v1/sources returned no items at all"
fi

# Every source keyed by its own id. A missing key means Sources::ALL and the
# payload have drifted apart, which is the failure the registry exists to stop.
for source in alerts reports sanitary_alerts; do
	expect "$sources" "\"$source\"" \
		"source $source is in the response" \
		"source $source is NOT in the response"
done

# --- narrowing (#51) --------------------------------------------------------
# The whole point of the parameters is that a narrowed answer is SMALLER and still
# the same envelope. Asserting only that it answers would pass against a route that
# ignored them, which is the failure worth catching.
narrowed="$(web -H "$OCSH" "$OCS/sources?format=json&source=sanitary_alerts")"
expect "$narrowed" '"sanitary_alerts"' \
	"?source= returns the asked source" \
	"?source= did not return the asked source"
if printf '%s' "$narrowed" | grep -q '"alerts"'; then
	fail "?source=sanitary_alerts also returned the other sources — the parameter is being ignored"
else
	pass "?source= excludes the sources that were not asked for"
fi

# An unknown id must not reach Sources::get()'s default-less match, which would
# throw UnhandledMatchError and answer 500 to what is a caller's typo.
#
# Asserted on the STATUS, not on the body. This read the body and checked only that
# it did not contain "alerts" — which a 500 error page does not either, nor does an
# empty response, nor does a request that never completed. The check passed on
# precisely the failure the paragraph above it describes, which is the one thing it
# existed to catch. The %{http_code} idiom is the one three checks below already use.
expect "$(web -H "$OCSH" -o /dev/null -w '%{http_code}' "$OCS/sources?format=json&source=no-such-source")" '404' \
	"an unknown source id answers 404" \
	"an unknown source id does NOT answer 404 — a 500 here is UnhandledMatchError"

# olderThan=1 is before anything the ISP has published, so a route that honours it
# returns no items and one that ignores it returns the archive.
empty="$(web -H "$OCSH" "$OCS/sources?format=json&source=sanitary_alerts&olderThan=1")"
oldcount="$(printf '%s' "$empty" | grep -o '"title"' | wc -l | tr -d ' ')"
[ "$oldcount" -eq 0 ] \
	&& pass "olderThan= narrows to nothing when nothing is older" \
	|| fail "olderThan= returned $oldcount items when nothing is older than epoch 1"

# Not the same statement as the guard above: this one also fails on a payload that
# publishes no degraded key at all, which is the envelope losing a documented field.
if [ -n "$degraded" ]; then
	skip "a fuente is degraded: not checking that the data arrives undegraded"
	skip "a fuente is degraded: not checking the semana epidemiológica"
else
	expect "$sources" '"degraded":false' \
		"the data arrives undegraded" \
		"some source is degraded — it did not answer, or stopped parsing"

	expect "$sources" '"se"' \
		"the reports expose the semana epidemiológica" \
		"the reports do not expose the semana epidemiológica"
fi

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

# What Apache hands out to ANYONE. These paths answer before Nextcloud is involved, so
# "authenticated staff only" was never the audience — measured with no credentials at all.
# The source map is the one that mattered: `npm run build` writes 4,9 MB beside the bundle
# carrying sourcesContent for all 350 sources, and it was in neither .htaccess list.
#
# Unauthenticated on purpose: an authenticated probe would pass while the hole was open.
# Bounded like web(), but nowhere near as generously: these four paths are answered by
# Apache out of .htaccess, before Nextcloud is involved, so there is no feed walk behind
# them and nothing here can legitimately take minutes. 000 is curl's own "never answered",
# and it gets its own line — reported as "check .htaccess" it would name a cause nobody
# checked, which is the defect this script's header is about.
for path in js/$APP-main.mjs.map vitest.config.js vite.config.js src/state.js; do
	code="$(docker exec -u www-data "$CONTAINER" \
		curl -sS --max-time 30 -o /dev/null -w '%{http_code}' "http://localhost/custom_apps/$APP/$path")"
	case "$code" in
		404|403) pass "$path is not served to anonymous callers" ;;
		000) fail "$path did not answer within 30 s — that is the instance, not the .htaccess" ;;
		*) fail "$path answers $code with NO credentials — check .htaccess" ;;
	esac
done

# The bundle in js/ is committed — that IS the deployment. So the failure this
# guards is src/ moving without a rebuild: the running app would then not be the
# code in this repo.
#
# CI now runs this same assertion and does NOT skip (#39), which matters because
# the skip below triggers exactly where the check is most needed: a fresh clone
# has no node_modules. This copy stays anyway — it costs nothing when node is
# present, and it answers the question locally before a push rather than after.
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

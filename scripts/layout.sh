#!/usr/bin/env bash
#
# Layout assertions against the running instance, at three viewports (#40).
#
# Half the defects this app has fixed were CSS or markup, and every one was found
# by a person looking at a screen. This is the part of that a machine can do.
#
# It runs BY HAND, like smoke.sh and for the same reason: the assertions need a
# provisioned instance with the app enabled, and their value is that they run
# against a real one. Faking that in CI would test the fake. CI runs the unit
# suite and the bundle check instead — see .github/workflows/ci.yml.
#
# Usage: scripts/layout.sh     (override with EPI_CONTAINER=… EPI_USER=…)
set -uo pipefail

CONTAINER="${EPI_CONTAINER:-apsconecta-gestion-nextcloud-1}"
USER_ID="${EPI_USER:-admin}"
# The port the host reaches the instance on, NOT the one inside the container:
# the browser runs here, not in there.
BASE="${EPI_BASE:-http://localhost:8180}"
TOKEN_NAME=epi-layout

HERE="$(cd "$(dirname "$0")/.." && pwd)"
occ() { docker exec -u www-data "$CONTAINER" php occ "$@" 2>/dev/null; }

# Revoke the throwaway credential however we leave.
cleanup() {
	[ -n "${TOKEN_ID:-}" ] && occ user:auth-tokens:delete "$USER_ID" "$TOKEN_ID" >/dev/null 2>&1
	return 0
}
trap cleanup EXIT

if [ ! -d "$HERE/node_modules/playwright" ]; then
	printf 'playwright is not installed. Run:\n  npm install && npx playwright install chromium\n' >&2
	exit 1
fi

# Basic auth serves the app HTML directly — measured, 94 KB with the initial
# state in it — so there is no login form to automate and no session to carry.
TOKEN="$(occ user:add-app-password "$USER_ID" --name="$TOKEN_NAME" -n | tail -1 | tr -d '\r')"
TOKEN_ID="$(occ user:auth-tokens:list "$USER_ID" | grep -F "| $TOKEN_NAME " | tail -1 \
	| awk -F'|' '{gsub(/[^0-9]/, "", $2); print $2}')"
if [ -z "$TOKEN" ]; then
	printf 'could not create an app password for %s\n' "$USER_ID" >&2
	exit 1
fi

node "$HERE/scripts/layout.mjs" "$BASE" "$USER_ID" "$TOKEN"

#!/usr/bin/env bash
#
# Smoke-tests an application created by create-app.sh over real HTTP.
# Usage: tests/integration/smoke.sh <app-dir> [php-binary] [port]
set -euo pipefail

APP="${1:?application directory required}"
PHP="${2:-php}"
PORT="${3:-8765}"
BASE="http://127.0.0.1:$PORT"
JAR="$(mktemp)"
PASS=0

cd "$APP"
cleanup() { [[ -n "${SERVER:-}" ]] && kill "$SERVER" 2>/dev/null || true; rm -f "$JAR"; }
trap cleanup EXIT

check() { # description, condition
    if eval "$2"; then PASS=$((PASS + 1)); echo "  ok  $1"; else echo "  FAIL $1"; exit 1; fi
}

serve() {
    [[ -n "${SERVER:-}" ]] && kill "$SERVER" 2>/dev/null && sleep 0.5
    "$PHP" artisan serve --host=127.0.0.1 --port="$PORT" >/dev/null 2>&1 &
    SERVER=$!
    for _ in $(seq 1 50); do curl -s -o /dev/null "$BASE" && return; sleep 0.2; done
}

status() { curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" "$@"; }
body() { curl -s -b "$JAR" -c "$JAR" "$@"; }

echo "Package discovery"
check "service provider auto-discovered" "'$PHP' artisan package:discover | grep -q inertiapixel/laravel-log-viewer"
check "routes registered" "'$PHP' artisan route:list --path=log-viewer | grep -q inertiapixel.log-viewer.index"

serve
echo "Defaults (no published config)"
check "guests are rejected (401, no login route)" "[[ \$(status $BASE/log-viewer) == 401 ]]"
check "assets are public" "[[ \$(status $BASE/log-viewer/assets/log-viewer.js) == 200 ]]"
body "$BASE/ipx-seed" >/dev/null
status -L "$BASE/ipx-login" >/dev/null
check "signed-in user sees the dashboard (local env)" "body $BASE/log-viewer | grep -q 'Integration failure'"
check "results fragment works" "body '$BASE/log-viewer/results?levels[]=warning' | grep -q 'Integration warning entry'"
check "secrets are redacted" "! body '$BASE/log-viewer/results?q=Integration' | grep -q hunter2"
check "responses are not cacheable" "curl -s -D - -o /dev/null -b $JAR $BASE/log-viewer | grep -qi 'cache-control: .*no-store'"

echo "Production without a gate"
sed -i.bak 's/^APP_ENV=.*/APP_ENV=production/' .env
serve
check "authenticated user without gate gets 403" "[[ \$(status $BASE/log-viewer) == 403 ]]"
mv .env.bak .env

echo "Caching and publishing"
"$PHP" artisan config:cache -q && "$PHP" artisan route:cache -q
serve
check "works with cached config and routes" "body $BASE/log-viewer | grep -q 'Integration failure'"
"$PHP" artisan optimize:clear -q
"$PHP" artisan vendor:publish --tag=inertiapixel-log-viewer-config -q
check "config publishes" "[[ -f config/log-viewer.php ]]"
"$PHP" artisan vendor:publish --tag=inertiapixel-log-viewer-assets -q
check "assets publish" "[[ -f public/vendor/inertiapixel/log-viewer/inertiapixel-log-viewer.js ]]"
serve
check "works with the published config" "body $BASE/log-viewer | grep -q 'Integration failure'"

echo "$PASS checks passed."

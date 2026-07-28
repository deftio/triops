#!/usr/bin/env bash
#
# triops smoke test — bash and curl, no test framework.
#
# Starts php -S against app/, walks every endpoint, and checks status codes and
# response content. Runs in CI and locally:
#
#   ./dev/smoke.sh
#
set -u

PORT="${PORT:-8799}"
BASE="http://127.0.0.1:${PORT}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
JAR="${TMP}/cookies.txt"
DATA="${TMP}/data"

pass=0
fail=0

cleanup() {
    [ -n "${SRV:-}" ] && kill "$SRV" 2>/dev/null
    rm -rf "$TMP"
    rm -f "${ROOT}/app/config.php"
}
trap cleanup EXIT

ok()   { pass=$((pass+1)); printf '  ok   %s\n' "$1"; }
bad()  { fail=$((fail+1)); printf '  FAIL %s\n     expected: %s\n     actual:   %s\n' "$1" "$2" "$3"; }

# $1 label  $2 expected-status  $3 url  [$4 curl args...]
check_status() {
    local label="$1" want="$2" url="$3"; shift 3
    local got
    got="$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" "$@" "${BASE}${url}")"
    [ "$got" = "$want" ] && ok "$label ($want)" || bad "$label" "status $want" "status $got"
}

# $1 label  $2 substring  $3 url  [$4 curl args...]
check_body() {
    local label="$1" want="$2" url="$3"; shift 3
    local got
    got="$(curl -s -c "$JAR" -b "$JAR" "$@" "${BASE}${url}")"
    case "$got" in
        *"$want"*) ok "$label" ;;
        *) bad "$label" "body containing '$want'" "$(printf '%s' "$got" | head -c 160)" ;;
    esac
}

# Point the data dir at a throwaway location so a local install is untouched.
mkdir -p "$DATA"
cat > "${ROOT}/app/config.php" <<EOF
<?php
return ['data_dir' => '${DATA}'];
EOF

php -S "127.0.0.1:${PORT}" -t "${ROOT}/app" >"${TMP}/server.log" 2>&1 &
SRV=$!

for _ in $(seq 1 40); do
    curl -s -o /dev/null "${BASE}/api/version.php" && break
    sleep 0.25
done

echo "triops smoke test — ${BASE}"
echo
echo "primitives"
check_body   "timestamp is a date"      "$(date +%Y)"        "/timestamp.php"
check_body   "sum adds"                 "3.5"                "/sum.php?a=1&b=2.5"
check_body   "sum ignores non-numeric"  "3"                  "/sum.php?a=1&b=2&c=abc"
check_body   "ip reports peer"          "127.0.0.1"          "/ip.php"
check_body   "echo replays body"        "hello-echo"         "/echo.php" -X POST -d "hello-echo"
check_body   "echo shows headers"       "X-Probe"            "/echo.php" -H "X-Probe: 1"
check_body   "headers lists host"       "Host"               "/headers.php"
check_body   "delay reports wait"       "waited 100ms"       "/delay.php?ms=100"
check_status "code honours request"     "503"                "/code.php?c=503"
check_status "code rejects nonsense"    "200"                "/code.php?c=99999"
check_body   "bytes returns n bytes"    "0123456789"         "/bytes.php?n=16"

echo
echo "api — unauthenticated"
check_status "version is public"        "200"                "/api/version.php"
check_body   "version reports api int"  '"api": 1'           "/api/version.php"
check_status "ingest accepts post"      "200"                "/api/ingest.php?channel=smoke" -X POST -d '{"t":1}'
check_status "read needs setup/auth"    "503"                "/api/read.php?channel=smoke"
check_status "index redirects to setup" "302"                "/index.php"

echo
echo "setup and auth"
check_status "setup creates first user" "302"                "/setup.php" -X POST \
    -d "username=smoke&password=smoketest123&password2=smoketest123"
check_status "read works once authed"   "200"                "/api/read.php?channel=smoke"
check_body   "read returns the payload" '"body": "{\"t\":1}"' "/api/read.php?channel=smoke"
check_status "status works once authed" "200"                "/api/status.php"
check_body   "status names the driver"  '"driver"'           "/api/status.php"
check_status "setup closes after first" "302"                "/setup.php"
check_status "clear rejects GET"        "405"                "/api/clear.php?channel=smoke"
check_status "clear accepts POST"       "200"                "/api/clear.php?channel=smoke" -X POST

echo
echo "safety"
check_body   "channel traversal blocked" '"channel": "etcpasswd"' \
    "/api/ingest.php?channel=../../etc/passwd" -X POST -d "x"
check_status "oversize payload refused"  "413" "/api/ingest.php?channel=smoke" -X POST \
    --data-binary "@${ROOT}/app/assets/bitwrench.umd.min.js"

# Regression guard, tested directly on the mechanism.
#
# Data files are named .php so the exit line on row 1 is executed rather than
# served as text. Named .json or .ndjson they are static content and every
# bcrypt hash leaks to anyone who guesses the path on a host that ignores
# .htaccess — which is nginx, and php -S, and plenty of shared hosting.
printf '<?php exit; ?>\n{"canary":"MUST-NOT-APPEAR"}\n' > "${ROOT}/app/data/canary.php"
printf '<?php exit; ?>\n{"canary":"MUST-NOT-APPEAR"}\n' > "${ROOT}/app/data/canary.json"

guarded="$(curl -s "${BASE}/data/canary.php")"
case "$guarded" in
    *MUST-NOT-APPEAR*) bad "guarded .php data file is not served" "empty" "leaked contents" ;;
    *) ok "guarded .php data file is not served" ;;
esac

# The .json control proves the guard alone is not what protects you — the
# extension is. Documented, not asserted, since .htaccess would cover it.
unguarded="$(curl -s "${BASE}/data/canary.json")"
case "$unguarded" in
    *MUST-NOT-APPEAR*) ok "control: .json would leak (why data files end in .php)" ;;
    *) ok "control: .json also blocked by server config" ;;
esac

rm -f "${ROOT}/app/data/canary.php" "${ROOT}/app/data/canary.json"

echo
echo "html pages"
check_status "login renders"            "200"                "/login.php"
check_body   "login has a form"         'name="password"'    "/login.php"
check_status "view renders"             "200"                "/view.php"
check_status "users renders"            "200"                "/users.php"
check_body   "users has csrf token"     'name="csrf"'        "/users.php"
check_status "404 page returns 404"     "404"                "/404.php"

echo
printf 'passed %d, failed %d\n' "$pass" "$fail"
[ "$fail" -eq 0 ] || exit 1

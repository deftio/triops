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
    if [ -n "${SRV:-}" ]; then
        # PHP_CLI_SERVER_WORKERS forks children, and killing the parent leaves
        # them alive and still bound to the port. They then serve the next run
        # from a config file that has since been rewritten, which looks exactly
        # like a flaky test and is not one.
        pkill -P "$SRV" 2>/dev/null
        kill "$SRV" 2>/dev/null
        wait "$SRV" 2>/dev/null
    fi
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

# $1 label  $2 substring that must NOT appear  $3 url  [$4 curl args...]
check_absent() {
    local label="$1" forbidden="$2" url="$3"; shift 3
    local got
    got="$(curl -s -c "$JAR" -b "$JAR" "$@" "${BASE}${url}")"
    case "$got" in
        *"$forbidden"*) bad "$label" "no '$forbidden' anywhere" "$(printf '%s' "$got" | head -c 160)" ;;
        *) ok "$label" ;;
    esac
}

# Point the data dir at a throwaway location so a local install is untouched.
#
# STORE picks the driver. It has to be threaded through here rather than written
# to app/config.php by the caller, because this script *writes* that file — so a
# caller setting store=ndjson had it silently clobbered and tested sqlite twice.
mkdir -p "$DATA"
write_config() {
    cat > "${ROOT}/app/config.php" <<EOF
<?php
return [
    'data_dir' => '${DATA}',
    'store' => '${STORE:-auto}',
    'max_entries_per_channel' => ${MAX_ENTRIES:-512},
];
EOF
}
write_config

# PHP_CLI_SERVER_WORKERS is what makes the concurrency test mean anything: the
# built-in server handles one request at a time otherwise, so simultaneous posts
# would queue up neatly and never exercise the locking they exist to test.
start_server() {
    PHP_CLI_SERVER_WORKERS=8 php -S "127.0.0.1:${PORT}" -t "${ROOT}/app" >>"${TMP}/server.log" 2>&1 &
    SRV=$!
    for _ in $(seq 1 40); do
        curl -s -o /dev/null "${BASE}/api/version.php" && return
        sleep 0.25
    done
}

stop_server() {
    [ -n "${SRV:-}" ] || return
    pkill -P "$SRV" 2>/dev/null
    kill "$SRV" 2>/dev/null
    wait "$SRV" 2>/dev/null
    SRV=""
}

start_server

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

echo
echo "binary payloads"
# The promise is "triops stores the bytes your device sent". Bodies that are not
# valid UTF-8 cannot go through JSON at all: before 0.2.1 the ndjson driver wrote
# a blank line and lost the packet outright, and under sqlite the row stored but
# every later read of that channel came back with an empty body. Both silent.
printf '\xff\xfe\x01\x02' > "${TMP}/binary.bin"
printf 'ok\x00nul' > "${TMP}/nulbyte.bin"

check_status "binary body accepted"     "200"                     "/api/ingest.php?channel=binary" \
    -X POST --data-binary "@${TMP}/binary.bin"
check_body   "binary body is labelled"  '"body_encoding": "base64"' "/api/read.php?channel=binary"
check_body   "binary body survives"     '"body": "//4BAg=="'      "/api/read.php?channel=binary"
check_body   "binary byte count is raw" '"bytes": 4'              "/api/read.php?channel=binary"

check_status "null byte accepted"       "200"                     "/api/ingest.php?channel=nulbyte" \
    -X POST --data-binary "@${TMP}/nulbyte.bin"
check_body   "null byte body survives"  '"body": "b2sAbnVs"'      "/api/read.php?channel=nulbyte"

# A binary packet must not poison the channel for everything after it.
check_status "text after binary lands"  "200"                     "/api/ingest.php?channel=binary" \
    -X POST -d 'after-the-binary'
check_body   "channel still readable"   'after-the-binary'        "/api/read.php?channel=binary"
check_body   "text stays text"          '"body_encoding": "utf-8"' "/api/read.php?channel=binary"

echo
echo "credentials are not stored"
# A key pasted once to test a board should not outlive the test. The name stays
# so "a credential was sent and it was wrong" is still visible; the value goes.
check_status "post with credentials"    "200"                "/api/ingest.php?channel=creds&key=hunter2&token=abc123" \
    -X POST -d 'x' -H "Authorization: Bearer secret-bearer" -H "X-Triops-Key: secret-header"
check_body   "credential name kept"     '"key": "[redacted]"' "/api/read.php?channel=creds"
check_absent "query key not stored"     'hunter2'            "/api/read.php?channel=creds"
check_absent "query token not stored"   'abc123'             "/api/read.php?channel=creds"
check_absent "auth header not stored"   'secret-bearer'      "/api/read.php?channel=creds"
check_absent "ingest key not stored"    'secret-header'      "/api/read.php?channel=creds"

echo
echo "request headers are recorded"
check_status "post with a header"       "200"                "/api/ingest.php?channel=hdrs" \
    -X POST -d 'x' -H "X-Device-Id: esp32-01" -H "Content-Type: application/cbor"
check_body   "custom header stored"     'X-Device-Id'        "/api/read.php?channel=hdrs"
check_body   "header value stored"      'esp32-01'           "/api/read.php?channel=hdrs"
check_body   "content-length stored"    'Content-Length'     "/api/read.php?channel=hdrs"
check_status "setup closes after first" "302"                "/setup.php"
check_status "clear rejects GET"        "405"                "/api/clear.php?channel=smoke"
check_status "clear accepts POST"       "200"                "/api/clear.php?channel=smoke" -X POST

echo
echo "concurrent ingestion"
# Devices reconnect together and report together, so the inbox has to survive a
# burst.
#
# Be clear about what this proves. It is a guard against gross failure under
# load — packets dropped, the ring ignored, the newest write lost, the file left
# unreadable. It is NOT a detector for the specific compaction race 0.2.1 fixed:
# that needs an append to land in the window between compaction reading the file
# and renaming a replacement over it, and a burst of forty does not reliably hit
# a window that narrow. Verified by reverting the fix — this still passes. The
# fix stands on the reasoning, not on this test.
BURST=40

# Bare `wait` would also wait on the php -S server, which never exits. Wait on
# the curls by pid.
burst_post() {
    local channel="$1" prefix="$2" pids="" i
    for i in $(seq 1 $BURST); do
        # No cookie jar here: parallel curls sharing one jar file corrupt it,
        # and ingest does not need auth anyway.
        curl -s -o /dev/null -X POST -d "${prefix}-${i}" \
            "${BASE}/api/ingest.php?channel=${channel}" &
        pids="${pids} $!"
    done
    wait $pids
}

burst_post burst burst

landed="$(curl -s -c "$JAR" -b "$JAR" "${BASE}/api/read.php?channel=burst&n=200" | grep -c '"body": "burst-')"
[ "$landed" = "$BURST" ] \
    && ok "all ${BURST} concurrent posts landed" \
    || bad "concurrent posts landed" "${BURST} entries" "${landed} entries"

# Again, with the ring tightened so compaction actually runs mid-burst rather
# than after 2048 entries. The ring is meant to drop old entries; what it is not
# meant to do is corrupt the file or lose the newest write.
# Restart with the tight ring rather than rewriting config under a running
# server: workers pick up a config change on their own schedule, and a test that
# depends on when they do is a test that fails for reasons unrelated to locking.
stop_server
MAX_ENTRIES=5 write_config
start_server

burst_post squeeze squeeze
curl -s -o /dev/null -X POST -d "squeeze-last" "${BASE}/api/ingest.php?channel=squeeze"

squeezed="$(curl -s -c "$JAR" -b "$JAR" "${BASE}/api/read.php?channel=squeeze&n=200")"
kept="$(printf '%s' "$squeezed" | grep -c '"body": "squeeze')"
if [ "$kept" -ge 5 ] && [ "$kept" -le 20 ]; then
    ok "compaction under load holds the ring (${kept} entries)"
else
    bad "compaction under load holds the ring" "between 5 and 20 entries" "${kept} entries"
fi
case "$squeezed" in
    *'"body": "squeeze-last"'*) ok "newest entry survives compaction" ;;
    *) bad "newest entry survives compaction" "squeeze-last present" "missing" ;;
esac

stop_server
write_config
start_server

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

# The canary proves the mechanism. These prove it is actually applied to every
# file triops generates — which is the part that matters, and which an obscure
# filename is not a substitute for. Each runs under both drivers: when a file
# does not exist for the active driver the request 404s, which also passes.
#
# The data dir is $DATA, outside the webroot, so these are requested through the
# app/data/ path that a default install would expose.
check_status "post to name a data file" "200" "/api/ingest.php?channel=leak" -X POST -d 'LEAK-CANARY-BODY'

check_absent "ndjson data file not served"  'LEAK-CANARY-BODY' "/data/ch-leak.ndjson.php"
check_absent "ndjson lock file not served"  '<?php'            "/data/ch-leak.lock.php"
check_absent "users file not served"        'smoke'            "/data/users.php"
check_absent "password hash not served"     '$2y$'             "/data/users.php"
# The marker naming the sqlite database. 0.2.0 wrote it to a bare .dbname, which
# is served as text by anything ignoring .htaccess — handing out the one filename
# the random suffix exists to hide.
check_absent "db name marker not served"    '.sqlite'          "/data/dbname.php"
check_absent "legacy db marker is gone"     '.sqlite'          "/data/.dbname"
check_absent "data dir does not list"       'ch-leak'          "/data/"

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

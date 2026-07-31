#!/usr/bin/env bash
#
# Produce the README demo GIF.
#
#   ./dev/record-demo.sh            -> dist/triops-demo.gif
#
# Starts a throwaway triops, drives it in a real browser with Playwright,
# records the viewport, and converts the result with ffmpeg. Because it drives
# the actual application, the GIF cannot show a UI that does not exist.
#
# One-time setup:
#   cd dev && npm install && npx playwright install chromium
#
set -eu

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${PORT:-8790}"
BASE="http://127.0.0.1:${PORT}"
OUT="${ROOT}/dist"
DATA="$(mktemp -d)"

# Width the GIF is scaled to. GitHub renders README images at ~880px on a wide
# screen; 820 keeps text crisp — including the hex dump — at about 800 KB.
# These defaults are what the committed GIF was made with. Change them and the
# next person's re-record silently produces a different-sized image.
WIDTH="${WIDTH:-820}"
FPS="${FPS:-10}"

cleanup() {
    [ -n "${SRV:-}" ] && kill "$SRV" 2>/dev/null
    rm -rf "$DATA"
    rm -f "${ROOT}/app/config.php"
}
trap cleanup EXIT

command -v ffmpeg >/dev/null || { echo "ffmpeg required"; exit 1; }
[ -d "${ROOT}/dev/node_modules/playwright" ] || {
    echo "playwright missing. run: cd dev && npm install && npx playwright install chromium"
    exit 1
}

mkdir -p "$OUT"
rm -rf "${OUT}/demo"

# Throwaway data dir so a local install is untouched.
cat > "${ROOT}/app/config.php" <<EOF
<?php
return ['data_dir' => '${DATA}'];
EOF

php -S "127.0.0.1:${PORT}" -t "${ROOT}/app" >/dev/null 2>&1 &
SRV=$!
for _ in $(seq 1 40); do
    curl -s -o /dev/null "${BASE}/api/version.php" && break
    sleep 0.25
done

echo "recording…"
( cd "${ROOT}/dev" && BASE="$BASE" OUT="${OUT}/demo" node record-demo.mjs )

WEBM="$(find "${OUT}/demo" -name '*.webm' | head -1)"
[ -n "$WEBM" ] || { echo "no video produced"; exit 1; }

echo "converting…"
# Two passes: build a palette from the whole clip, then apply it. Straight
# webm->gif without this bands badly.
#
# 64 colours and no dithering, because the UI is flat: a handful of greys, two
# brand colours, and monospace text. Dithering exists to fake shades that are
# not in the palette, and on flat colour it only adds noise the encoder then has
# to store — it doubled the file for no visible gain. This is a README image, so
# every megabyte is someone's page load.
ffmpeg -y -loglevel error -i "$WEBM" \
    -vf "fps=${FPS},scale=${WIDTH}:-1:flags=lanczos,palettegen=max_colors=64:stats_mode=diff" \
    "${OUT}/palette.png"

GIF="${ROOT}/docs/triops-demo.gif"

ffmpeg -y -loglevel error -i "$WEBM" -i "${OUT}/palette.png" \
    -lavfi "fps=${FPS},scale=${WIDTH}:-1:flags=lanczos[x];[x][1:v]paletteuse=dither=none" \
    "$GIF"

rm -f "${OUT}/palette.png"
rm -rf "${OUT}/demo"

SIZE="$(du -h "$GIF" | cut -f1 | tr -d ' ')"
echo
echo "wrote docs/triops-demo.gif  (${SIZE}, ${WIDTH}px, ${FPS}fps)"
echo "referenced from README.md — re-run this after any UI change"
echo
echo "Too big? Fewer frames or a smaller width:"
echo "  FPS=10 WIDTH=800 ./dev/record-demo.sh"

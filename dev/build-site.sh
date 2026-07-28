#!/usr/bin/env bash
#
# Stage the GitHub Pages site.
#
# pages/ holds only what markdown cannot express: the landing page and the
# demo. Shared assets are copied from app/ at build time rather than duplicated
# in git, so the demo cannot drift into showing a UI that does not exist.
#
#   ./dev/build-site.sh     # populate pages/assets for local viewing
#
# CI runs this before publishing.
set -eu

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
mkdir -p "${ROOT}/pages/assets"

# Belt and braces. Publishing through the Actions path serves the artifact
# as-is and never invokes Jekyll, so this is only insurance against Pages being
# switched back to deploy-from-branch later — at which point Jekyll would
# otherwise try to build the site and choke on anything underscore-prefixed.
touch "${ROOT}/pages/.nojekyll"

cp "${ROOT}/app/assets/bitwrench.umd.min.js" "${ROOT}/pages/assets/"
cp "${ROOT}/app/assets/triops.js"            "${ROOT}/pages/assets/"
cp "${ROOT}/app/assets/triops-logo.png"      "${ROOT}/pages/assets/"
cp "${ROOT}/app/assets/favicon.ico"          "${ROOT}/pages/assets/"

echo "staged $(ls -1 "${ROOT}/pages/assets" | wc -l | tr -d ' ') assets into pages/assets/"
echo "open ${ROOT}/pages/index.html"

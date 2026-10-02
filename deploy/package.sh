#!/usr/bin/env bash
# Builds dist/: app-<sha>.zip (code + built assets), vendor-<lockhash>.zip, _deploy.php.
# Usage: deploy/package.sh <short-sha> <sha256 of the deploy token>
set -euo pipefail

SHA="$1"
TOKEN_HASH="$2"
[[ "$TOKEN_HASH" =~ ^[a-f0-9]{64}$ ]] || { echo "bad token hash" >&2; exit 1; }
test -f public/build/manifest.json || { echo "run npm run build first" >&2; exit 1; }

rm -rf dist && mkdir -p dist/_releases

APP_ZIP="app-${SHA}.zip"
zip -rq "dist/_releases/${APP_ZIP}" . \
  -x 'vendor/*' 'node_modules/*' '.git/*' 'tests/*' 'dist/*' 'storage/*' '.env' '.env.*' \
     'database/*.sqlite' '.github/*' 'tools/*' '*.log' 'server.env' 'deploy.json' 'artisan.json' 'public/hot'

LOCK="$(sha1sum composer.lock | cut -c1-16)"
VENDOR_ZIP="vendor-${LOCK}.zip"
zip -rq "dist/_releases/${VENDOR_ZIP}" vendor

sed "s/__TOKEN_HASH__/${TOKEN_HASH}/" deploy/agent.php > dist/_deploy.php
grep -q "${TOKEN_HASH}" dist/_deploy.php

echo "${APP_ZIP}" > dist/release.txt
echo "${VENDOR_ZIP}" > dist/vendor.txt
ls -la dist dist/_releases

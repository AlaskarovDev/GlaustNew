#!/usr/bin/env bash
# Uploads dist/ to the hosting over FTPS (explicit TLS), falling back to plain FTP.
# Only 2-3 files per deploy: the agent, the app zip, and the vendor zip when composer.lock changed.
# curl is used because it was verified against this host; lftp failed to connect from CI.
set -euo pipefail

APP_ZIP="$(cat dist/release.txt)"
VENDOR_ZIP="$(cat dist/vendor.txt)"
BASE="ftp://${FTP_HOST}"
TLS=(--ssl-reqd -k)

ftp() { curl -sS --fail --retry 3 --retry-delay 5 --connect-timeout 30 --user "${FTP_USER}:${FTP_PASSWORD}" "${TLS[@]}" "$@"; }

if ! ROOT_LIST="$(ftp -l "${BASE}/")"; then
  echo "FTPS failed, trying plain FTP"
  TLS=()
  ROOT_LIST="$(ftp -l "${BASE}/")"
fi

# The account may land in the site folder or directly in public_html.
if printf '%s\n' "$ROOT_LIST" | grep -qx 'public_html'; then DIR='public_html/'; else DIR=''; fi
echo "FTP web root: /${DIR}"

EXISTING="$(ftp -l "${BASE}/${DIR}_releases/" 2>/dev/null || true)"

time ftp --ftp-create-dirs -T "dist/_releases/${APP_ZIP}" "${BASE}/${DIR}_releases/${APP_ZIP}"
if printf '%s\n' "$EXISTING" | grep -qx "${VENDOR_ZIP}"; then
  echo "vendor archive already on the server"
else
  time ftp --ftp-create-dirs -T "dist/_releases/${VENDOR_ZIP}" "${BASE}/${DIR}_releases/${VENDOR_ZIP}"
fi
ftp -T dist/_deploy.php "${BASE}/${DIR}_deploy.php"

echo "--- on the server:"
ftp -l "${BASE}/${DIR}_releases/"

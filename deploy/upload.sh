#!/usr/bin/env bash
# Uploads dist/ to the hosting over FTP (FTPS when the server offers it).
# Only 2-3 files per deploy: the agent, the app zip, and the vendor zip when composer.lock changed.
set -euo pipefail

sudo apt-get -qq install -y lftp >/dev/null

APP_ZIP="$(cat dist/release.txt)"
VENDOR_ZIP="$(cat dist/vendor.txt)"

lf() {
  lftp -c "set net:timeout 40; set net:max-retries 4; set net:reconnect-interval-base 5;
           set ftp:ssl-allow yes; set ssl:verify-certificate no; set ftp:passive-mode yes;
           open -u \"${FTP_USER}\",\"${FTP_PASSWORD}\" \"${FTP_HOST}\"; $1"
}

# The FTP account may land in the site folder or in public_html itself.
ROOT_LIST="$(lf 'cls -1' || true)"
if printf '%s\n' "$ROOT_LIST" | grep -qx 'public_html/\?'; then DIR='public_html'; else DIR='.'; fi
echo "FTP web root: ${DIR}"

EXISTING="$(lf "cd ${DIR}; cls -1 _releases/" 2>/dev/null || true)"
CMDS="cd ${DIR}; mkdir -p _releases; put -O _releases dist/_releases/${APP_ZIP};"
if printf '%s\n' "$EXISTING" | grep -q "${VENDOR_ZIP}"; then
  echo "vendor archive already on the server"
else
  CMDS="${CMDS} put -O _releases dist/_releases/${VENDOR_ZIP};"
fi
CMDS="${CMDS} put dist/_deploy.php -o _deploy.php;"

time lf "${CMDS}"
lf "cd ${DIR}; cls -l _releases/ _deploy.php"

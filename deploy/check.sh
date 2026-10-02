#!/usr/bin/env bash
# Post-deploy checks against the live site. Every check reads a saved body/status
# (no `curl | grep -q` under pipefail: grep exiting early can fail curl and invert the test).
set -uo pipefail

SITE="${1%/}"
fail=0

status() { curl -s -o /dev/null -w '%{http_code}' --max-time 30 "$1"; }

check_status() { # url expected
  local got; got="$(status "$1")"
  if [ "$got" = "$2" ]; then echo "ok   $2  $1"; else echo "FAIL $got (want $2)  $1"; fail=1; fi
}

check_body() { # url needle
  curl -s --max-time 30 "$1" -o body.html
  if grep -q -- "$2" body.html; then echo "ok   contains '$2'  $1"; else echo "FAIL missing '$2'  $1"; head -c 600 body.html; echo; fail=1; fi
}

# The app boots and renders. Right after a deploy LiteSpeed may serve the first
# requests through its 404 handler until it re-reads .htaccess: wait for a real 200.
for i in $(seq 1 18); do [ "$(status "$SITE/login")" = "200" ] && [ "$(status "$SITE/up")" = "200" ] && break; sleep 5; done
check_status "$SITE/up" 200
check_status "$SITE/login" 200
check_body "$SITE/login" "Glaust"
check_body "$SITE/login" "/build/assets/app-"

# The CSS/JS referenced by the page are actually served.
css="$(grep -o '/build/assets/app-[A-Za-z0-9_-]*\.css' body.html | head -1)"
js="$(grep -o '/build/assets/app-[A-Za-z0-9_-]*\.js' body.html | head -1)"
[ -n "$css" ] && check_status "$SITE$css" 200 || { echo "FAIL no css link"; fail=1; }
[ -n "$js" ] && check_status "$SITE$js" 200 || { echo "FAIL no js link"; fail=1; }

# Guests are sent to the login page.
check_status "$SITE/" 302

# Internals are never served.
for p in _app/.env _app/composer.json _app_shared/.env _app_storage/logs/laravel.log _releases/ _app/artisan .env; do
  got="$(status "$SITE/$p")"
  if [ "$got" = "200" ]; then echo "FAIL $p is publicly readable"; fail=1; else echo "ok   $got  /$p blocked"; fi
done

# Security headers. The hosting CDN replaces our CSP header with its own, so the
# policy must reach the browser through the <meta http-equiv> tag.
curl -s -D headers.txt -o body.html "$SITE/login"
if grep -qi "content-security-policy:.*script-src" headers.txt || grep -q 'http-equiv="Content-Security-Policy" content="[^"]*script-src' body.html; then
  echo "ok   CSP with script-src reaches the browser"
else
  echo "FAIL no effective CSP (neither header nor meta)"; fail=1
fi
for h in x-content-type-options x-frame-options; do
  if grep -qi "^$h:" headers.txt; then echo "ok   header $h"; else echo "FAIL missing header $h"; fail=1; fi
done

exit $fail

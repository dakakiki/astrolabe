#!/usr/bin/env bash
#
# CI (job "Deploy scripts"): checks the server files in deploy/ without a server.
#
# 1. Every template renders with no placeholder left.
# 2. Nginx runs with the real site files on high ports, with a stand-in for PHP
#    that answers "php <uri>", and requests land where they should — above all the
#    portal's /sw.js, /manifest.webmanifest and /offline reach the app (Phase 9c),
#    only index.php runs, dot files stay hidden, other host names get nothing.
# 3. systemd accepts the units.
# 4. set_env / get_env keep values exactly.
#
# Needs nginx, curl, openssl and systemd-analyze (all on GitHub's Ubuntu runners).

# shellcheck source-path=SCRIPTDIR
set -euo pipefail

deploy="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
repo="$(cd "$deploy/.." && pwd)"
work="$(mktemp -d)"

APP_DIR="$repo"
HTTP_PORT=8080
HTTPS_PORT=8443
ACME_ROOT="$work/acme"
CERT_DIR="$work/cert"
NGINX_LOG_DIR="$work/log"
NGINX_SNIPPETS="$work/snippets"
RUN_USER="$(id -un)"
DEPLOY_USER="$(id -un)"
export APP_DIR HTTP_PORT HTTPS_PORT ACME_ROOT CERT_DIR NGINX_LOG_DIR NGINX_SNIPPETS RUN_USER DEPLOY_USER
# shellcheck source=SCRIPTDIR/../lib.sh
source "$deploy/lib.sh"

failures=0
fixtures=("$repo/public/build/ci-check.js" "$repo/public/ci-check.php")

cleanup() {
    if [ -f "$work/nginx.pid" ]; then
        nginx -e "$work/log/startup.log" -p "$work" -c "$work/nginx.conf" -s stop 2>/dev/null || true
    fi
    rm -f "${fixtures[@]}"
    rm -rf "$work"
}
trap cleanup EXIT

check() {
    local what=$1 expected=$2 actual=$3
    if [ "$expected" = "$actual" ]; then
        printf 'ok    %s\n' "$what"
    else
        printf 'FAIL  %s\n      expected: %s\n      got:      %s\n' "$what" "$expected" "$actual"
        failures=$((failures + 1))
    fi
}

# --- 1. templates -------------------------------------------------------------
mkdir -p "$work/rendered"
while IFS= read -r template; do
    render "$template" "$work/rendered/$(basename "$template")"
done < <(find "$deploy/files" -type f)
check "every template renders" "0" "$(grep -l '__[A-Z][A-Z_]*__' "$work"/rendered/* | wc -l | tr -d ' ')"

# --- 2. nginx -----------------------------------------------------------------
mkdir -p "$ACME_ROOT/.well-known/acme-challenge" "$CERT_DIR" "$NGINX_LOG_DIR" "$NGINX_SNIPPETS" \
    "$work/sites" "$work/conf.d" "$work/tmp" "$repo/public/build"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=$APP_HOST" \
    -addext "subjectAltName=DNS:$APP_HOST,DNS:$PORTAL_HOST" \
    -keyout "$CERT_DIR/privkey.pem" -out "$CERT_DIR/fullchain.pem" 2>/dev/null

render "$deploy/files/nginx/tls.conf" "$NGINX_SNIPPETS/astrolabe-tls.conf"
render "$deploy/files/nginx/tuning.conf" "$work/conf.d/astrolabe.conf"
for site in default http https; do
    render "$deploy/files/nginx/$site.conf" "$work/sites/$site.conf"
done
# The stand-in for PHP-FPM: tells which request reached the app.
# shellcheck disable=SC2016
printf '%s\n' 'default_type text/plain;' 'return 200 "php $request_uri";' >"$NGINX_SNIPPETS/astrolabe-php.conf"

cat >"$work/nginx.conf" <<EOF
worker_processes 1;
pid $work/nginx.pid;
error_log $work/log/error.log;
events {}
http {
    include /etc/nginx/mime.types;
    default_type application/octet-stream;
    access_log $work/log/access.log;
    client_body_temp_path $work/tmp/body;
    fastcgi_temp_path $work/tmp/fastcgi;
    proxy_temp_path $work/tmp/proxy;
    uwsgi_temp_path $work/tmp/uwsgi;
    scgi_temp_path $work/tmp/scgi;
    include $work/conf.d/*.conf;
    include $work/sites/*.conf;
}
EOF

echo 'console.log("ci")' >"$repo/public/build/ci-check.js"
echo '<?php echo "should never run";' >"$repo/public/ci-check.php"
echo 'acme-token' >"$ACME_ROOT/.well-known/acme-challenge/ci-token"

nginx -t -q -e "$work/log/startup.log" -p "$work" -c "$work/nginx.conf"
nginx -e "$work/log/startup.log" -p "$work" -c "$work/nginx.conf"

# Prints "<status> <body>" for a request; -k because the certificate is made up.
fetch() {
    local host=$1 path=$2 scheme=${3:-https} port=$HTTPS_PORT
    [ "$scheme" = https ] || port=$HTTP_PORT
    rm -f "$work/body"
    curl -sk --max-time 5 --resolve "$host:$port:127.0.0.1" -o "$work/body" -w '%{http_code}' \
        "$scheme://$host:$port$path" || true
    printf ' %s' "$(cat "$work/body" 2>/dev/null || true)"
}

for host in "$PORTAL_HOST" "$APP_HOST"; do
    for path in /sw.js /manifest.webmanifest /offline; do
        check "$host$path reaches the app" "200 php $path" "$(fetch "$host" "$path")"
    done
done
check "an SPA route reaches the app with its query" "200 php /clients/12?tab=chart" "$(fetch "$APP_HOST" '/clients/12?tab=chart')"
check "the API reaches the app" "200 php /api/v1/health" "$(fetch "$APP_HOST" /api/v1/health)"
check "the start page reaches the app" "200 php /" "$(fetch "$PORTAL_HOST" /)"
check "index.php itself runs" "200 php /index.php" "$(fetch "$APP_HOST" /index.php)"
check "no other PHP file runs" "404" "$(fetch "$APP_HOST" /ci-check.php | cut -d' ' -f1)"
check "dot files stay hidden" "404" "$(fetch "$APP_HOST" /.htaccess | cut -d' ' -f1)"
check "a built file is served as a file" '200 console.log("ci")' "$(fetch "$APP_HOST" /build/ci-check.js)"
check "a missing built file is a 404, not the app" "404" "$(fetch "$APP_HOST" /build/missing.js | cut -d' ' -f1)"
check "built files are cached for a year" "public, max-age=31536000, immutable" \
    "$(curl -sk --resolve "$APP_HOST:$HTTPS_PORT:127.0.0.1" -D - -o /dev/null "https://$APP_HOST:$HTTPS_PORT/build/ci-check.js" |
        tr -d '\r' | sed -n 's/^[Cc]ache-[Cc]ontrol: //p')"
fetch "$PORTAL_HOST" /icons/astrolabe-192.png >/dev/null
icon=different
if cmp -s "$work/body" "$repo/public/icons/astrolabe-192.png"; then icon=same; fi
check "an icon is served as a file" "same" "$icon"
check "plain HTTP goes to HTTPS" "301 https://$APP_HOST/clients" \
    "$(curl -s --resolve "$APP_HOST:$HTTP_PORT:127.0.0.1" -o /dev/null -w '%{http_code} %{redirect_url}' "http://$APP_HOST:$HTTP_PORT/clients")"
check "Let's Encrypt's check is answered over HTTP" "200 acme-token" "$(fetch "$PORTAL_HOST" /.well-known/acme-challenge/ci-token http)"
check "another name over HTTPS gets no certificate" "35" \
    "$(curl -sk --max-time 5 --resolve "other.example:$HTTPS_PORT:127.0.0.1" -o /dev/null "https://other.example:$HTTPS_PORT/" >/dev/null 2>&1; echo $?)"
check "another name over HTTP gets no answer" "52" \
    "$(curl -s --max-time 5 --resolve "other.example:$HTTP_PORT:127.0.0.1" -o /dev/null "http://other.example:$HTTP_PORT/" >/dev/null 2>&1; echo $?)"

# --- 3. systemd ---------------------------------------------------------------
mkdir -p "$work/systemd"
for unit in astrolabe-worker.service astrolabe-offsite.service astrolabe-offsite.timer; do
    render "$deploy/files/systemd/$unit" "$work/systemd/$unit"
done
if systemd-analyze verify "$work"/systemd/* >"$work/systemd.log" 2>&1; then
    check "systemd accepts the units" "yes" "yes"
else
    cat "$work/systemd.log"
    check "systemd accepts the units" "yes" "no"
fi

# --- 4. .env helpers ----------------------------------------------------------
env_file="$work/test.env"
printf 'APP_NAME=AstroLabe\nMAIL_PASSWORD=\nMAIL_PASSWORD_OLD=keep\n' >"$env_file"
# A literal dollar sign and backslash, on purpose.
# shellcheck disable=SC2016
tricky='p@ss word#$1\x'
set_env "$env_file" MAIL_PASSWORD "$tricky"
set_env "$env_file" BACKUP_KEY 'base64:AbC+/9=='
check "a value with spaces and symbols is quoted" "MAIL_PASSWORD='$tricky'" "$(grep '^MAIL_PASSWORD=' "$env_file")"
check "it reads back unchanged" "$tricky" "$(get_env "$env_file" MAIL_PASSWORD)"
check "a similar name is left alone" "keep" "$(get_env "$env_file" MAIL_PASSWORD_OLD)"
check "a new key is added once" "1 base64:AbC+/9==" "$(grep -c '^BACKUP_KEY=' "$env_file") $(get_env "$env_file" BACKUP_KEY)"

echo
if [ "$failures" -gt 0 ]; then
    echo "$failures check(s) failed."
    exit 1
fi
echo "All deploy checks passed."

# Shared settings and helpers for the scripts in deploy/ (sourced, not run).
#
# Only public names live here: the two app hosts, paths, user names. Server
# addresses, passwords and keys never go into this repository — they are given
# on the command line at run time or written on the server itself.

# shellcheck shell=bash

: "${APP_HOST:=app.astrolabe.online}"
: "${PORTAL_HOST:=portal.astrolabe.online}"
: "${APP_DIR:=/var/www/astrolabe}"
: "${REPO_URL:=https://github.com/dakakiki/astrolabe.git}"
: "${REPO_BRANCH:=main}"

# Users: RUN_USER runs PHP-FPM, the queue worker, cron and the off-site copy and can
# write only storage/ and bootstrap/cache; DEPLOY_USER owns the code and puts new
# versions live (no sudo); OPS_USER is the owner's own login with sudo.
: "${RUN_USER:=astrolabe}"
: "${DEPLOY_USER:=deploy}"
: "${OPS_USER:=ops}"

: "${DB_NAME:=astrolabe}"
: "${DB_USER:=astrolabe}"
: "${PHP_VERSION:=8.3}"
: "${MARIADB_VERSION:=11.8}"
: "${NODE_MAJOR:=24}"
: "${COMPOSER_VERSION:=2.10.3}"
: "${SWISSEPH_DIR:=/opt/swisseph}"
: "${ACME_ROOT:=/var/www/letsencrypt}"
: "${CERT_NAME:=astrolabe}"
: "${CERT_DIR:=/etc/letsencrypt/live/${CERT_NAME}}"
: "${PHP_SOCKET:=/run/php/php${PHP_VERSION}-fpm-astrolabe.sock}"
: "${HTTP_PORT:=80}"
: "${HTTPS_PORT:=443}"
: "${NGINX_LOG_DIR:=/var/log/nginx}"
: "${NGINX_SNIPPETS:=/etc/nginx/snippets}"
: "${MARIADB_MIRROR:=https://deb.mariadb.org}"
# MariaDB's release signing key (mariadb.org, "MariaDB Signing Key"); check it there before the mariadb step.
: "${MARIADB_KEY_FINGERPRINT:=177F4010FE56CA3336300305F1656F24C74CD1D8}"
: "${RUN_HOME:=/var/lib/${RUN_USER}}"
: "${OFFSITE_ENV:=/etc/astrolabe/offsite.env}"
: "${OFFSITE_MARKER:=${APP_DIR}/storage/app/private/offsite-backup.ok}"

# Values that are secrets: never on a command line, never printed.
SECRET_KEYS="APP_KEY DB_PASSWORD MAIL_PASSWORD BACKUP_KEY VAPID_PRIVATE_KEY"

info() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m!!\033[0m %s\n' "$*" >&2; }
die() {
    printf '\033[1;31mxx\033[0m %s\n' "$*" >&2
    exit 1
}

require_root() {
    [ "$(id -u)" -eq 0 ] || die "Run this as root."
}

home_of() {
    getent passwd "$1" | cut -d: -f6
}

# Runs a command as another user, in the current directory, with that user's HOME.
as_user() {
    local user=$1
    shift
    runuser -u "$user" -- env HOME="$(home_of "$user")" PATH="/usr/local/bin:/usr/bin:/bin" "$@"
}

is_secret() {
    case " $SECRET_KEYS " in
    *" $1 "*) return 0 ;;
    *) return 1 ;;
    esac
}

# Fills the __PLACEHOLDERS__ of a template from the settings above.
render() {
    local template=$1 target=$2 tmp
    tmp="$(mktemp)"
    sed \
        -e "s|__APP_HOST__|${APP_HOST}|g" \
        -e "s|__PORTAL_HOST__|${PORTAL_HOST}|g" \
        -e "s|__APP_DIR__|${APP_DIR}|g" \
        -e "s|__RUN_USER__|${RUN_USER}|g" \
        -e "s|__DEPLOY_USER__|${DEPLOY_USER}|g" \
        -e "s|__DB_NAME__|${DB_NAME}|g" \
        -e "s|__DB_USER__|${DB_USER}|g" \
        -e "s|__PHP_VERSION__|${PHP_VERSION}|g" \
        -e "s|__PHP_SOCKET__|${PHP_SOCKET}|g" \
        -e "s|__NGINX_SNIPPETS__|${NGINX_SNIPPETS}|g" \
        -e "s|__SWISSEPH_DIR__|${SWISSEPH_DIR}|g" \
        -e "s|__ACME_ROOT__|${ACME_ROOT}|g" \
        -e "s|__CERT_DIR__|${CERT_DIR}|g" \
        -e "s|__HTTP_PORT__|${HTTP_PORT}|g" \
        -e "s|__HTTPS_PORT__|${HTTPS_PORT}|g" \
        -e "s|__NGINX_LOG_DIR__|${NGINX_LOG_DIR}|g" \
        -e "s|__OFFSITE_ENV__|${OFFSITE_ENV}|g" \
        -e "s|__ROOT_LOGIN__|${ROOT_LOGIN:-prohibit-password}|g" \
        "$template" >"$tmp"
    if grep -q '__[A-Z][A-Z_]*__' "$tmp"; then
        rm -f "$tmp"
        die "Unfilled placeholder in $template"
    fi
    mv -f "$tmp" "$target"
    chmod 0644 "$target"
}

# Reads one value from a .env file (without surrounding quotes).
get_env() {
    local file=$1 key=$2 line
    line="$(grep -m1 "^${key}=" "$file" || true)"
    line="${line#*=}"
    line="${line#\'}"
    line="${line%\'}"
    line="${line#\"}"
    line="${line%\"}"
    printf '%s' "$line"
}

# Writes KEY=value into a .env file, replacing the line if it is there. The value
# never reaches a command line: awk reads it from the environment.
set_env() {
    local file=$1 key=$2 value=$3 line tmp
    [[ $key =~ ^[A-Z][A-Z0-9_]*$ ]] || die "Not a setting name: $key"
    case $value in
    *"'"* | *$'\n'*) die "$key: a value with a single quote or a line break has to be set by hand." ;;
    esac
    if [[ $value =~ ^[A-Za-z0-9_./:@+=,-]*$ ]]; then
        line="${key}=${value}"
    else
        line="${key}='${value}'"
    fi
    tmp="$(mktemp "${file}.XXXXXX")"
    K="$key" L="$line" awk '
        BEGIN { k = ENVIRON["K"] "="; l = ENVIRON["L"]; n = length(k) }
        substr($0, 1, n) == k { if (!done) print l; done = 1; next }
        { print }
        END { if (!done) print l }
    ' "$file" >"$tmp"
    chown --reference="$file" "$tmp"
    chmod --reference="$file" "$tmp"
    mv -f "$tmp" "$file"
}

#!/usr/bin/env bash
#
# Changes settings in the app's .env and makes the app and the worker use them:
#
#   bash deploy/bin/set-env.sh OPERATOR_EMAIL=… MAIL_HOST=…   ordinary values
#   bash deploy/bin/set-env.sh --secret MAIL_PASSWORD         asks for the value, shows nothing
#
# Secrets are only ever typed in by the owner (--secret reads from the terminal),
# so they never end up on a command line, in a shell history or in a chat.

# shellcheck source-path=SCRIPTDIR
set -euo pipefail

# shellcheck source=SCRIPTDIR/../lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"

main() {
    local env_file="$APP_DIR/.env" key value pair
    if [ "$(id -u)" -eq 0 ]; then
        exec runuser -u "$DEPLOY_USER" -- bash "$(realpath "${BASH_SOURCE[0]}")" "$@"
    fi
    [ "$(id -un)" = "$DEPLOY_USER" ] || die "Run this as $DEPLOY_USER (or with sudo)."
    [ -f "$env_file" ] || die "No $env_file yet."
    [ $# -gt 0 ] || die "Usage: set-env.sh KEY=value … | set-env.sh --secret KEY"

    if [ "$1" = --secret ]; then
        key="${2:?Usage: set-env.sh --secret KEY}"
        [ -t 0 ] || [ -r /dev/tty ] || die "--secret needs a terminal: the owner types the value."
        read -r -s -p "$key: " value </dev/tty
        echo
        [ -n "$value" ] || die "Nothing typed — $key unchanged."
        set_env "$env_file" "$key" "$value"
    else
        for pair in "$@"; do
            key="${pair%%=*}"
            value="${pair#*=}"
            [ "$key" != "$pair" ] || die "Expected KEY=value, got: $pair"
            ! is_secret "$key" || die "$key is a secret: bash deploy/bin/set-env.sh --secret $key"
            set_env "$env_file" "$key" "$value"
        done
    fi

    cd "$APP_DIR"
    php artisan optimize >/dev/null
    php artisan queue:restart >/dev/null
    info "Saved; the app and the worker use the new values."
}

main "$@"

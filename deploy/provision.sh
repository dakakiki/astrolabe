#!/usr/bin/env bash
#
# Sets up a fresh Ubuntu 24.04 server (Hetzner Cloud CX33) for AstroLabe, one step
# at a time, as root. The deploy/ folder is copied to the server first (scp); the
# steps clone the rest.
#
#   bash provision.sh <step>          settings come from the environment, e.g.
#   OWNER_KEY=/root/owner.pub DEPLOY_KEY=/root/deploy.pub bash provision.sh users
#
# Steps, in this order (docs/deployment.md, "Prvo podizanje"):
#
#   system    Ubuntu packages, UTC, swap, daily security updates, firewall, SSH keys only
#   users     OPS_USER (the owner, sudo), DEPLOY_USER (deploys, no sudo), RUN_USER (the app)
#   mariadb   MariaDB from MariaDB's repository, the database and its user
#   tools     Composer and Node.js, checked against their published checksums
#   app       the code, .env with generated secrets, PHP and npm packages, the build
#   swetest   Swiss Ephemeris built from source (SWISSEPH_SOURCE) into SWISSEPH_DIR
#   database  migrations
#   places    the GeoNames gazetteer (large download, takes a while)
#   web       PHP-FPM, Nginx (HTTP for now), queue worker, cron, config cache
#   tls       one Let's Encrypt certificate for both hosts, then HTTPS (LE_AGREE_TOS=yes)
#   offsite   key and settings for the nightly copy to the Storage Box
#   finish    root can no longer sign in (OWNER_VERIFIED=yes)
#   check     what runs, versions, health
#
# Each step that downloads says what before it starts and runs only when asked
# for, so downloads are approved step by step. Every step can run again.
# Nothing secret is printed or written outside the server.

# shellcheck source-path=SCRIPTDIR
set -euo pipefail

FILES="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/files"
# shellcheck source=SCRIPTDIR/lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

DB_SECRET=/root/.astrolabe/db.env
ENV_FILE="$APP_DIR/.env"
FPM_DIR="/etc/php/${PHP_VERSION}/fpm"
export DEBIAN_FRONTEND=noninteractive

step_system() {
    local VERSION_ID PRETTY_NAME
    # shellcheck disable=SC1091
    . /etc/os-release
    [ "$VERSION_ID" = "24.04" ] || die "Ubuntu 24.04 expected, found $PRETTY_NAME."
    [ -s /root/.ssh/authorized_keys ] || die "root has no SSH key, and password sign-in is about to be switched off."

    info "Downloads: updates and packages from the Ubuntu archive — Nginx, PHP ${PHP_VERSION}, certbot, build tools, ufw, fail2ban (~150 MB)."
    apt-get update
    apt-get -y -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold upgrade
    apt-get -y install --no-install-recommends \
        nginx certbot \
        "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-opcache" "php${PHP_VERSION}-readline" \
        "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-intl" "php${PHP_VERSION}-mysql" "php${PHP_VERSION}-bcmath" \
        "php${PHP_VERSION}-zip" "php${PHP_VERSION}-xml" "php${PHP_VERSION}-curl" \
        git unzip xz-utils build-essential rsync acl curl ca-certificates gnupg \
        ufw fail2ban unattended-upgrades cron

    timedatectl set-timezone UTC

    if ! swapon --show=NAME --noheadings | grep -qx /swapfile; then
        info "2 GB swap file (keeps MariaDB alive if a build needs more memory)"
        [ -f /swapfile ] || fallocate -l 2G /swapfile
        chmod 0600 /swapfile
        mkswap /swapfile >/dev/null
        swapon /swapfile
        grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >>/etc/fstab
    fi
    echo 'vm.swappiness = 10' >/etc/sysctl.d/90-astrolabe.conf
    sysctl --quiet --system

    install -m 0644 "$FILES/apt/20auto-upgrades" /etc/apt/apt.conf.d/20auto-upgrades
    install -m 0644 "$FILES/apt/52astrolabe-unattended-upgrades" /etc/apt/apt.conf.d/52astrolabe-unattended-upgrades

    install -d -m 0755 /etc/systemd/journald.conf.d
    install -m 0644 "$FILES/systemd/journald.conf" /etc/systemd/journald.conf.d/astrolabe.conf
    systemctl restart systemd-journald

    ROOT_LOGIN=prohibit-password render "$FILES/ssh/01-astrolabe.conf" /etc/ssh/sshd_config.d/01-astrolabe.conf
    sshd -t
    systemctl reload-or-restart ssh

    ufw default deny incoming >/dev/null
    ufw default allow outgoing >/dev/null
    ufw allow OpenSSH >/dev/null
    ufw allow 80/tcp >/dev/null
    ufw allow 443/tcp >/dev/null
    ufw --force enable >/dev/null
    systemctl enable --now fail2ban >/dev/null

    info "System ready: UTC, swap, daily security updates (restart at 04:30 when needed), ufw 22/80/443, SSH keys only."
}

install_key() {
    local user=$1 file=$2 home
    ssh-keygen -l -f "$file" >/dev/null 2>&1 || die "$file is not a public SSH key."
    home="$(home_of "$user")"
    install -d -m 0700 -o "$user" -g "$user" "$home/.ssh"
    install -m 0600 -o "$user" -g "$user" "$file" "$home/.ssh/authorized_keys"
}

step_users() {
    [ -f "${OWNER_KEY:-}" ] || die "OWNER_KEY=<file with the owner's public SSH key> is needed."
    [ -f "${DEPLOY_KEY:-}" ] || die "DEPLOY_KEY=<file with the public key used for deploys> is needed."

    getent group "$RUN_USER" >/dev/null || groupadd --system "$RUN_USER"
    id "$RUN_USER" >/dev/null 2>&1 ||
        useradd --system --gid "$RUN_USER" --home-dir "$RUN_HOME" --create-home --shell /usr/sbin/nologin "$RUN_USER"
    chmod 0750 "$RUN_HOME"

    id "$DEPLOY_USER" >/dev/null 2>&1 || useradd --create-home --shell /bin/bash "$DEPLOY_USER"
    usermod -aG "$RUN_USER,systemd-journal" "$DEPLOY_USER"

    id "$OPS_USER" >/dev/null 2>&1 || useradd --create-home --shell /bin/bash "$OPS_USER"
    usermod -aG "sudo,adm,systemd-journal" "$OPS_USER"

    install_key "$OPS_USER" "$OWNER_KEY"
    install_key "$DEPLOY_USER" "$DEPLOY_KEY"

    info "Users: $OPS_USER (owner, sudo), $DEPLOY_USER (deploys, no sudo), $RUN_USER (the app, no login)."
    warn "Owner: give $OPS_USER a password for sudo in your own root session (passwd $OPS_USER), then check 'ssh $OPS_USER@…' and 'sudo -v' before the finish step."
}

step_mariadb() {
    local key=/etc/apt/keyrings/mariadb-keyring.pgp fingerprint password version
    info "Downloads: MariaDB's signing key (mariadb.org) and MariaDB ${MARIADB_VERSION} packages from ${MARIADB_MIRROR} (~40 MB)."
    install -d -m 0755 /etc/apt/keyrings
    curl -fsSL --proto '=https' -o "$key.new" https://mariadb.org/mariadb_release_signing_key.pgp
    fingerprint="$(gpg --show-keys --with-colons "$key.new" 2>/dev/null | awk -F: '$1 == "fpr" { print $10; exit }')"
    if [ "$fingerprint" != "$MARIADB_KEY_FINGERPRINT" ]; then
        rm -f "$key.new"
        die "MariaDB's key has fingerprint '$fingerprint', expected $MARIADB_KEY_FINGERPRINT — nothing installed."
    fi
    mv -f "$key.new" "$key"
    chmod 0644 "$key"
    cat >/etc/apt/sources.list.d/mariadb.sources <<EOF
# MariaDB ${MARIADB_VERSION} LTS (AstroLabe, deploy/provision.sh mariadb)
Types: deb
URIs: ${MARIADB_MIRROR}/${MARIADB_VERSION}/ubuntu
Suites: noble
Components: main
Signed-By: ${key}
EOF
    apt-get update
    apt-get -y install mariadb-server mariadb-client

    install -m 0644 "$FILES/mariadb/90-astrolabe.cnf" /etc/mysql/mariadb.conf.d/90-astrolabe.cnf
    systemctl enable mariadb >/dev/null
    systemctl restart mariadb
    version="$(mariadb -N -e 'SELECT VERSION()')"
    case $version in
    "${MARIADB_VERSION}".*) ;;
    *) die "MariaDB ${MARIADB_VERSION} expected, the server says $version." ;;
    esac

    install -d -m 0700 "$(dirname "$DB_SECRET")"
    if [ ! -f "$DB_SECRET" ]; then
        (umask 077 && printf 'DB_PASSWORD=%s\n' "$(openssl rand -hex 24)" >"$DB_SECRET")
    fi
    password="$(get_env "$DB_SECRET" DB_PASSWORD)"
    # Through standard input, so the password is never on a command line.
    mariadb <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${password}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${password}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${password}';
ALTER USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${password}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost', '${DB_USER}'@'127.0.0.1';
-- backup:restore --verify loads the newest backup into this scratch database and drops it.
GRANT ALL PRIVILEGES ON \`${DB_NAME}\_restore\_check\`.* TO '${DB_USER}'@'localhost', '${DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
    info "MariaDB $version: database '$DB_NAME', user '$DB_USER' (password only in $DB_SECRET and the app's .env)."
}

step_tools() {
    local work arch file base
    work="$(mktemp -d)"
    case "$(uname -m)" in
    x86_64) arch=x64 ;;
    aarch64) arch=arm64 ;;
    *) die "Unknown processor: $(uname -m)" ;;
    esac
    base="https://nodejs.org/dist/latest-v${NODE_MAJOR}.x"

    info "Downloads: Composer ${COMPOSER_VERSION} (getcomposer.org, ~3 MB) and the newest Node.js ${NODE_MAJOR} for Linux ${arch} (nodejs.org, ~30 MB)."
    curl -fsSL --proto '=https' -o "$work/composer.phar" "https://getcomposer.org/download/${COMPOSER_VERSION}/composer.phar"
    curl -fsSL --proto '=https' -o "$work/composer.phar.sha256sum" "https://getcomposer.org/download/${COMPOSER_VERSION}/composer.phar.sha256sum"
    [ "$(sha256sum "$work/composer.phar" | awk '{ print $1 }')" = "$(awk '{ print $1; exit }' "$work/composer.phar.sha256sum")" ] ||
        die "Composer's checksum does not match."
    install -m 0755 "$work/composer.phar" /usr/local/bin/composer

    curl -fsSL --proto '=https' -o "$work/SHASUMS256.txt" "$base/SHASUMS256.txt"
    file="$(grep -oE "node-v${NODE_MAJOR}\.[0-9]+\.[0-9]+-linux-${arch}\.tar\.xz" "$work/SHASUMS256.txt" | head -n1)"
    [ -n "$file" ] || die "No Node.js ${NODE_MAJOR} build for linux-${arch} in $base."
    curl -fsSL --proto '=https' -o "$work/$file" "$base/$file"
    (cd "$work" && grep " ${file}\$" SHASUMS256.txt | sha256sum --check --status) || die "Node.js checksum does not match."
    install -d -m 0755 /usr/local/lib/nodejs
    tar -xJf "$work/$file" -C /usr/local/lib/nodejs
    ln -sfn "/usr/local/lib/nodejs/${file%.tar.xz}" /usr/local/lib/nodejs/current
    for tool in node npm npx; do
        ln -sf "/usr/local/lib/nodejs/current/bin/$tool" "/usr/local/bin/$tool"
    done
    rm -rf "$work"
    info "Composer $(composer --version 2>/dev/null | awk '{print $3}'), Node.js $(node --version), npm $(npm --version)."
}

# storage/ and bootstrap/cache belong to RUN_USER's group with default ACLs, so files
# made by PHP-FPM, the worker or a deploy can be written by both; other users see nothing.
fix_permissions() {
    local dir
    for dir in "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"; do
        chgrp -R "$RUN_USER" "$dir"
        chmod -R u+rwX,g+rwX,o-rwx "$dir"
        find "$dir" -type d -exec chmod g+s {} +
        setfacl -R -m "g:${RUN_USER}:rwX,d:u::rwX,d:g::rwX,d:g:${RUN_USER}:rwX,d:o::---" "$dir"
    done
    chown "$DEPLOY_USER:$RUN_USER" "$ENV_FILE"
    chmod 0640 "$ENV_FILE"
}

step_app() {
    local out
    id "$DEPLOY_USER" >/dev/null 2>&1 || die "Run the users step first."
    [ -f "$DB_SECRET" ] || die "Run the mariadb step first."
    if ! command -v composer >/dev/null || ! command -v npm >/dev/null; then
        die "Run the tools step first."
    fi

    info "Downloads: the AstroLabe code ($REPO_URL), PHP packages (Packagist) and npm packages (npm registry) for the build."
    if [ ! -d "$APP_DIR/.git" ]; then
        install -d -o "$DEPLOY_USER" -g "$DEPLOY_USER" -m 0755 "$APP_DIR"
        as_user "$DEPLOY_USER" git clone --quiet --branch "$REPO_BRANCH" "$REPO_URL" "$APP_DIR"
    fi
    if [ ! -f "$ENV_FILE" ]; then
        render "$FILES/env.template" "$ENV_FILE"
    fi
    fix_permissions
    set_env "$ENV_FILE" DB_PASSWORD "$(get_env "$DB_SECRET" DB_PASSWORD)"

    cd "$APP_DIR"
    as_user "$DEPLOY_USER" composer install --no-dev --optimize-autoloader --no-interaction --no-progress

    if [ -z "$(get_env "$ENV_FILE" APP_KEY)" ]; then
        as_user "$DEPLOY_USER" php artisan key:generate --force --no-interaction >/dev/null
    fi
    if [ -z "$(get_env "$ENV_FILE" BACKUP_KEY)" ]; then
        out="$(as_user "$DEPLOY_USER" php artisan backup:key)"
        set_env "$ENV_FILE" BACKUP_KEY "${out%%$'\n'*}"
    fi
    if [ -z "$(get_env "$ENV_FILE" VAPID_PRIVATE_KEY)" ]; then
        out="$(as_user "$DEPLOY_USER" php artisan portal:push-keys)"
        set_env "$ENV_FILE" VAPID_PUBLIC_KEY "$(printf '%s\n' "$out" | sed -n 's/^VAPID_PUBLIC_KEY=//p')"
        set_env "$ENV_FILE" VAPID_PRIVATE_KEY "$(printf '%s\n' "$out" | sed -n 's/^VAPID_PRIVATE_KEY=//p')"
    fi
    for key in APP_KEY BACKUP_KEY VAPID_PUBLIC_KEY VAPID_PRIVATE_KEY DB_PASSWORD; do
        [ -n "$(get_env "$ENV_FILE" "$key")" ] || die "$key is still empty in $ENV_FILE."
    done

    as_user "$DEPLOY_USER" npm ci --no-audit --no-fund
    as_user "$DEPLOY_USER" npm run build
    fix_permissions

    info "Code in $APP_DIR, .env written (APP_KEY, BACKUP_KEY, VAPID keys and the database password made here)."
    warn "Owner: copy APP_KEY and BACKUP_KEY into your password manager now — without them no backup can be read on another server:"
    warn "  sudo grep -E '^(APP_KEY|BACKUP_KEY)=' $ENV_FILE"
}

step_swetest() {
    local build
    [ -n "${SWISSEPH_SOURCE:-}" ] || die "SWISSEPH_SOURCE=<the repository URL from the Swiss Ephemeris licence contract> is needed."
    [ -f "$APP_DIR/scripts/build-swetest.sh" ] || die "Run the app step first."
    info "Downloads: the Swiss Ephemeris sources at the pinned commit (~1.8 MB) and three data files (~2 MB)."
    build="$(mktemp -d)"
    chown "$DEPLOY_USER" "$build"
    (cd "$APP_DIR" && as_user "$DEPLOY_USER" env SWISSEPH_SOURCE="$SWISSEPH_SOURCE" bash scripts/build-swetest.sh "$build/swisseph")
    install -d -m 0755 "$SWISSEPH_DIR" "$SWISSEPH_DIR/ephe"
    install -m 0755 "$build/swisseph/swetest" "$SWISSEPH_DIR/swetest"
    install -m 0644 "$build/swisseph/ephe/"*.se1 "$SWISSEPH_DIR/ephe/"
    rm -rf "$build"
    set_env "$ENV_FILE" SWETEST_PATH "$SWISSEPH_DIR/swetest"
    set_env "$ENV_FILE" EPHEMERIS_PATH "$SWISSEPH_DIR/ephe"
    info "swetest and $(find "$SWISSEPH_DIR/ephe" -name '*.se1' | wc -l) data files in $SWISSEPH_DIR."
}

step_database() {
    cd "$APP_DIR"
    as_user "$DEPLOY_USER" php artisan migrate --force
}

step_places() {
    cd "$APP_DIR"
    info "Downloads: GeoNames allCountries.zip (~422 MB, download.geonames.org); the import of ~5.1 M places and ~9.6 M names takes a while."
    as_user "$DEPLOY_USER" php artisan places:import --source=all
}

enable_site() {
    local name=$1
    render "$FILES/nginx/$name.conf" "/etc/nginx/sites-available/astrolabe-$name"
    ln -sfn "/etc/nginx/sites-available/astrolabe-$name" "/etc/nginx/sites-enabled/astrolabe-$name"
}

step_web() {
    [ -f "$ENV_FILE" ] || die "Run the app step first."

    render "$FILES/php/fpm-pool.conf" "$FPM_DIR/pool.d/astrolabe.conf"
    [ ! -f "$FPM_DIR/pool.d/www.conf" ] || mv -f "$FPM_DIR/pool.d/www.conf" "$FPM_DIR/pool.d/www.conf.disabled"
    render "$FILES/php/fpm.ini" "$FPM_DIR/conf.d/90-astrolabe.ini"
    render "$FILES/php/cli.ini" "/etc/php/${PHP_VERSION}/cli/conf.d/90-astrolabe.ini"
    render "$FILES/php/logrotate" "/etc/logrotate.d/php${PHP_VERSION}-fpm"
    "php-fpm${PHP_VERSION}" -t
    systemctl enable "php${PHP_VERSION}-fpm" >/dev/null
    systemctl restart "php${PHP_VERSION}-fpm"

    install -d -m 0755 "$ACME_ROOT"
    render "$FILES/nginx/tuning.conf" /etc/nginx/conf.d/astrolabe.conf
    render "$FILES/nginx/tls.conf" "$NGINX_SNIPPETS/astrolabe-tls.conf"
    render "$FILES/nginx/php.conf" "$NGINX_SNIPPETS/astrolabe-php.conf"
    rm -f /etc/nginx/sites-enabled/default
    enable_site default
    enable_site http
    if [ -f "$CERT_DIR/fullchain.pem" ]; then
        enable_site https
    fi
    nginx -t
    systemctl reload nginx

    for unit in astrolabe-worker.service astrolabe-offsite.service astrolabe-offsite.timer; do
        render "$FILES/systemd/$unit" "/etc/systemd/system/$unit"
    done
    render "$FILES/cron/astrolabe" /etc/cron.d/astrolabe
    systemctl daemon-reload

    (cd "$APP_DIR" && as_user "$DEPLOY_USER" php artisan optimize >/dev/null)
    systemctl enable astrolabe-worker >/dev/null
    systemctl restart astrolabe-worker

    if [ -f "$CERT_DIR/fullchain.pem" ]; then
        info "PHP-FPM, Nginx (HTTP and HTTPS), worker and cron are running."
    else
        info "PHP-FPM, Nginx (HTTP only until the tls step), worker and cron are running."
    fi
}

step_tls() {
    local email_args
    if [ "${LE_AGREE_TOS:-}" != yes ]; then
        die "Let's Encrypt asks to accept its Subscriber Agreement (https://letsencrypt.org/repository/). Once the owner has said yes: LE_AGREE_TOS=yes."
    fi
    if [ -n "${LE_EMAIL:-}" ]; then
        email_args=(--email "$LE_EMAIL" --no-eff-email)
    else
        email_args=(--register-unsafely-without-email)
    fi
    [ -f /etc/nginx/sites-enabled/astrolabe-http ] || die "Run the web step first."

    info "Asks Let's Encrypt for one certificate for $APP_HOST and $PORTAL_HOST (both names must already point at this server)."
    certbot certonly --webroot -w "$ACME_ROOT" --cert-name "$CERT_NAME" \
        -d "$APP_HOST" -d "$PORTAL_HOST" \
        --agree-tos --non-interactive --keep-until-expiring "${email_args[@]}" \
        --deploy-hook "systemctl reload nginx"

    enable_site https
    nginx -t
    systemctl reload nginx
    systemctl enable --now certbot.timer >/dev/null 2>&1 || true
    info "HTTPS on for both hosts; certbot renews by itself (certbot.timer) and reloads Nginx."
}

step_offsite() {
    local port dir key known scan shown
    : "${STORAGE_BOX_HOST:?STORAGE_BOX_HOST=<the Storage Box host from the Hetzner console> is needed}"
    : "${STORAGE_BOX_USER:?STORAGE_BOX_USER=<the Storage Box (sub-)account> is needed}"
    port="${STORAGE_BOX_PORT:-23}"
    dir="${STORAGE_BOX_DIR:-astrolabe-backups}"
    key="$RUN_HOME/.ssh/storagebox_ed25519"
    known="$RUN_HOME/.ssh/known_hosts"

    install -d -m 0700 -o "$RUN_USER" -g "$RUN_USER" "$RUN_HOME/.ssh"
    [ -f "$key" ] || as_user "$RUN_USER" ssh-keygen -q -t ed25519 -N '' -C "astrolabe-offsite" -f "$key"

    if ! ssh-keygen -F "[$STORAGE_BOX_HOST]:$port" -f "$known" >/dev/null 2>&1; then
        scan="$(mktemp)"
        ssh-keyscan -p "$port" -t ed25519 "$STORAGE_BOX_HOST" >"$scan" 2>/dev/null
        [ -s "$scan" ] || die "The Storage Box does not answer on port $port (is SSH support switched on?)."
        shown="$(ssh-keygen -l -f "$scan" | awk '{ print $2 }')"
        if [ -n "${STORAGE_BOX_FINGERPRINT:-}" ] && [ "$shown" != "$STORAGE_BOX_FINGERPRINT" ]; then
            rm -f "$scan"
            die "The Storage Box shows host key $shown, expected $STORAGE_BOX_FINGERPRINT — nothing saved."
        fi
        [ -n "${STORAGE_BOX_FINGERPRINT:-}" ] || warn "Storage Box host key $shown — compare it with the fingerprint Hetzner publishes."
        cat "$scan" >>"$known"
        rm -f "$scan"
        chown "$RUN_USER:$RUN_USER" "$known"
        chmod 0644 "$known"
    fi

    install -d -m 0750 -o root -g "$RUN_USER" "$(dirname "$OFFSITE_ENV")"
    (
        umask 027
        printf 'STORAGE_BOX_HOST=%s\nSTORAGE_BOX_USER=%s\nSTORAGE_BOX_PORT=%s\nSTORAGE_BOX_DIR=%s\n' \
            "$STORAGE_BOX_HOST" "$STORAGE_BOX_USER" "$port" "$dir" >"$OFFSITE_ENV"
    )
    chgrp "$RUN_USER" "$OFFSITE_ENV"

    set_env "$ENV_FILE" BACKUP_OFFSITE_MARKER "$OFFSITE_MARKER"
    (cd "$APP_DIR" && as_user "$DEPLOY_USER" php artisan optimize >/dev/null)
    systemctl daemon-reload
    systemctl enable --now astrolabe-offsite.timer >/dev/null

    info "Nightly copy at 03:15 UTC to $dir/ on the Storage Box. Its public key:"
    cat "$key.pub"
    warn "Owner: put that key on the Storage Box once (asks for the Storage Box password):"
    warn "  sudo cat $key.pub | ssh -p $port <account>@<host> install-ssh-key"
    warn "Then a first copy: sudo systemctl start astrolabe-offsite.service && journalctl -u astrolabe-offsite -n 20"
}

step_finish() {
    [ "${OWNER_VERIFIED:-}" = yes ] ||
        die "First the owner signs in as $OPS_USER with their own key and 'sudo -v' works; then OWNER_VERIFIED=yes."
    [ -s "$(home_of "$OPS_USER")/.ssh/authorized_keys" ] || die "$OPS_USER has no SSH key."
    [ "$(passwd -S "$OPS_USER" | awk '{ print $2 }')" = P ] || die "$OPS_USER has no password yet, so sudo would not work: passwd $OPS_USER"

    ROOT_LOGIN=no render "$FILES/ssh/01-astrolabe.conf" /etc/ssh/sshd_config.d/01-astrolabe.conf
    sshd -t
    systemctl reload-or-restart ssh
    : >/root/.ssh/authorized_keys
    info "Root can no longer sign in. $OPS_USER (sudo, password) is the way to root; $DEPLOY_USER keeps its key without sudo."
}

step_check() {
    local unit
    for unit in nginx "php${PHP_VERSION}-fpm" mariadb astrolabe-worker cron fail2ban ufw; do
        printf '%-24s %s\n' "$unit" "$(systemctl is-active "$unit" 2>/dev/null || true)"
    done
    systemctl list-timers astrolabe-offsite.timer certbot.timer --no-pager 2>/dev/null || true
    php --version | head -n1
    php -r 'echo "PHP time zone data: ", timezone_version_get(), PHP_EOL;'
    mariadb --version
    nginx -v 2>&1
    printf 'Node.js %s, npm %s\n' "$(node --version 2>/dev/null)" "$(npm --version 2>/dev/null)"
    ufw status | head -n 8
    for key in MAIL_HOST MAIL_USERNAME MAIL_PASSWORD OPERATOR_EMAIL BACKUP_OFFSITE_MARKER; do
        [ -n "$(get_env "$ENV_FILE" "$key")" ] || warn "$key is empty in .env."
    done
    (cd "$APP_DIR" && as_user "$RUN_USER" php artisan health:check) || true
}

main() {
    require_root
    local step="${1:-}"
    case "$step" in
    system | users | mariadb | tools | app | swetest | database | places | web | tls | offsite | finish | check)
        "step_$step"
        ;;
    *)
        sed -n '3,30p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
        exit 2
        ;;
    esac
}

main "$@"

#!/usr/bin/env bash
#
# Copies the encrypted backups to the Hetzner Storage Box — every night at 03:15 UTC
# through astrolabe-offsite.timer, as the app's own user. The copy mirrors
# storage/app/private/backups: the same 14 nights as on the server and nothing older
# (the promise in the DPA and the Privacy Policy). The files are encrypted with
# BACKUP_KEY before they leave, so the Storage Box never sees their content.
#
# Settings from /etc/astrolabe/offsite.env (written by `provision.sh offsite`):
# STORAGE_BOX_HOST, STORAGE_BOX_USER, STORAGE_BOX_PORT (23), STORAGE_BOX_DIR.
# After a good copy it touches the marker file that the health check reads
# (BACKUP_OFFSITE_MARKER): no copy for 26 hours → /api/v1/health fails and the
# operator gets an email.

# shellcheck source-path=SCRIPTDIR
set -euo pipefail

# shellcheck source=SCRIPTDIR/../lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"

main() {
    local port remote_dir key source_dir max_age_hours fresh count
    : "${STORAGE_BOX_HOST:?STORAGE_BOX_HOST is not set}"
    : "${STORAGE_BOX_USER:?STORAGE_BOX_USER is not set}"
    port="${STORAGE_BOX_PORT:-23}"
    remote_dir="${STORAGE_BOX_DIR:-astrolabe-backups}"
    key="${STORAGE_BOX_KEY:-$RUN_HOME/.ssh/storagebox_ed25519}"
    source_dir="${BACKUP_DIR:-$APP_DIR/storage/app/private/backups}"
    max_age_hours="${BACKUP_MAX_AGE_HOURS:-26}"

    # A mirror copies deletions too: never mirror without a fresh, complete backup,
    # or an emptied folder here would empty the copy as well.
    fresh="$(find "$source_dir" -mindepth 2 -maxdepth 2 -name manifest.json -mmin "-$((max_age_hours * 60))" -print -quit 2>/dev/null || true)"
    [ -n "$fresh" ] || die "No complete backup younger than ${max_age_hours} h in $source_dir — nothing was copied."
    count="$(find "$source_dir" -mindepth 2 -maxdepth 2 -name manifest.json | wc -l)"

    rsync --archive --delete --delay-updates \
        -e "ssh -p $port -i $key -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=$RUN_HOME/.ssh/known_hosts" \
        "$source_dir/" "${STORAGE_BOX_USER}@${STORAGE_BOX_HOST}:${remote_dir}/"

    touch "$OFFSITE_MARKER"
    info "Copied: $count backups in $remote_dir/ on the Storage Box."
}

main "$@"

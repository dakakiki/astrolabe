#!/usr/bin/env bash
#
# Puts a new version of AstroLabe live (docs/deployment.md, "Svaka sledeća verzija").
# Run it as the deploy user in the app's directory, after CI is green on that commit:
#
#   bash deploy/deploy.sh            the newest commit on main
#   bash deploy/deploy.sh <commit>   a given commit (must be ahead of the running one)
#
# The site is in maintenance mode while it runs (a minute or two). If a step fails
# it stays there: fix the cause, then run this again (or `php artisan up`).

# shellcheck source-path=SCRIPTDIR
set -euo pipefail

# shellcheck source=SCRIPTDIR/lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

main() {
    local ref current target
    [ "$(id -un)" = "$DEPLOY_USER" ] || die "Run this as $DEPLOY_USER."
    cd "$APP_DIR"
    ref="${1:-origin/$REPO_BRANCH}"

    git fetch --quiet origin
    [ -z "$(git status --porcelain --untracked-files=no)" ] || die "The checkout has local changes — nothing was changed."
    current="$(git rev-parse HEAD)"
    target="$(git rev-parse --verify --quiet "${ref}^{commit}")" || die "Unknown commit: $ref"
    if [ "$current" = "$target" ]; then
        info "Already live: $(git log -1 --format='%h %s')"
        exit 0
    fi
    git merge-base --is-ancestor "$current" "$target" ||
        die "$ref is not ahead of the running version; going back is done by hand (docs/deployment.md)."

    info "Going live:"
    git log --oneline "$current..$target"

    php artisan down --retry=60 --refresh=15
    trap 'warn "Stopped halfway: the site stays in maintenance mode. Fix the cause, then run this again (or: php artisan up)."' ERR

    git merge --ff-only --quiet "$target"
    composer install --no-dev --optimize-autoloader --no-interaction --no-progress
    npm ci --no-audit --no-fund
    npm run build
    php artisan migrate --force
    php artisan optimize
    php artisan queue:restart

    trap - ERR
    php artisan up
    info "Live: $(git log -1 --format='%h %s')"
}

# Inside a function: bash has read the whole script before git replaces this file.
main "$@"

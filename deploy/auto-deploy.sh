#!/usr/bin/env bash
#
# Pull-based auto-deploy for the b2bhaat.com cPanel checkout.
#
# Run from cron every 5 minutes. It asks GitHub whether ONE branch has moved
# and, only if it has, deploys that commit into ONE directory:
#
#   */5 * * * * ALERT_EMAIL=you@example.com /bin/bash /home/techreal/b2bhaat.com/deploy/auto-deploy.sh \
#       /home/techreal/b2bhaat.com production-deploy >/dev/null 2>&1
#
# The branch is `production-deploy`, not `production`: .github/workflows/deploy.yml
# runs the tests, builds public/build (the server has no Node) and publishes the
# result there. So only tested, fully built commits ever reach this script.
#
# Why pull and not push: the host firewalls inbound SSH, so GitHub Actions
# cannot reach it, but the server can reach GitHub.
#
# Guarantees — each is checked, and the script refuses (changes nothing) when
# it does not hold:
#   * Only the branch named on the command line is fetched or deployed, and the
#     checkout must already be on it.
#   * Only fast-forwards. Server-only commits or a force-push stop it.
#   * No uncommitted edits to tracked files are ever overwritten.
#   * Never two runs at once (lock per directory).
#   * A PostgreSQL backup (pg_dump) is taken before anything changes; no
#     backup, no deploy. The last 10 are kept in storage/app/backups/.
#   * If any step fails, the code is put back to the previous commit, the site
#     is brought back up, and that commit is not retried until a newer one is
#     pushed. Database changes from a half-run migration are NOT undone — the
#     log names the backup to restore from.
#
# Logged to ~/deploy-<directory name>.log. A run with nothing to deploy writes
# nothing. Alerts (alert.sh) go to ALERT_EMAIL: one per deploy, per failure,
# per new refusal, and when GitHub has been unreachable for 30 minutes.
#
# A commit that changes THIS script is deployed by the previous version of it.
#
# Optional environment overrides: PHP_BIN (default /usr/local/bin/php — on
# these hosts /usr/bin/php is php-cgi), COMPOSER_BIN, PG_DUMP_BIN, DEPLOY_LOG,
# ALERT_EMAIL, SKIP_BACKUP=1 (deploy without a database backup — not advised).

set -uo pipefail

# The whole body is one function: bash parses all of it before running any of
# it, so the git fast-forward below can safely replace this very file.
main() {

APP_DIR="${1:-}"
BRANCH="${2:-}"

if [[ -z "$APP_DIR" || -z "$BRANCH" ]]; then
    echo "usage: $0 <app directory> <branch>" >&2
    exit 64
fi

APP_DIR="${APP_DIR%/}"
NAME="$(basename "$APP_DIR")"
LOG="${DEPLOY_LOG:-$HOME/deploy-$NAME.log}"
STATE_DIR="$HOME/.deploy-state/$NAME"
PHP="${PHP_BIN:-/usr/local/bin/php}"
BACKUP_DIR="$APP_DIR/storage/app/backups"
BACKUPS_KEPT=10

mkdir -p "$STATE_DIR" || exit 1

log() { printf '%s  %s\n' "$(date '+%Y-%m-%d %H:%M:%S %z')" "$*" >> "$LOG"; }

# shellcheck source=alert.sh
if ! source "$(dirname "${BASH_SOURCE[0]}")/alert.sh" 2>/dev/null; then
    send_alert() { printf '%s\n\n%s\n' "$1" "$2"; }
fi

# A refusal repeats on every cron run until fixed; log and alert once.
die() {
    if [[ "$*" != "$(cat "$STATE_DIR/last-refusal" 2>/dev/null)" ]]; then
        printf '%s' "$*" > "$STATE_DIR/last-refusal"
        log "REFUSED: $*"
        send_alert "[$NAME] Auto-deploy REFUSED - needs a person" \
"The auto-deploy of branch '${BRANCH:-?}' into $APP_DIR stopped without changing anything:

  $*

The site is still running the version it had. Nothing more will be tried
until this is fixed. Log: $LOG"
    fi
    exit 1
}

# --- one run at a time -------------------------------------------------------
LOCK="$STATE_DIR/lock"
if ! mkdir "$LOCK" 2>/dev/null; then
    if [[ -n "$(find "$LOCK" -maxdepth 0 -mmin +60 2>/dev/null)" ]]; then
        log "removing stale lock (older than 60 min)"
        rmdir "$LOCK" 2>/dev/null || rm -rf "$LOCK"
        mkdir "$LOCK" 2>/dev/null || exit 0
    else
        exit 0
    fi
fi
trap 'rmdir "$LOCK" 2>/dev/null' EXIT

# --- preconditions (read-only) -----------------------------------------------
[[ -x "$PHP" ]] || die "PHP CLI not found at $PHP (set PHP_BIN)"
"$PHP" -r 'exit(PHP_SAPI === "cli" ? 0 : 1);' 2>/dev/null \
    || die "$PHP is not the PHP command-line binary (it is probably php-cgi); set PHP_BIN"

cd "$APP_DIR" 2>/dev/null || die "directory $APP_DIR does not exist"
[[ -f artisan ]] || die "$APP_DIR has no artisan file — not this app"
git rev-parse --git-dir >/dev/null 2>&1 || die "$APP_DIR is not a git checkout"

CURRENT_BRANCH="$(git symbolic-ref --quiet --short HEAD 2>/dev/null)"
[[ "$CURRENT_BRANCH" == "$BRANCH" ]] \
    || die "checkout is on '${CURRENT_BRANCH:-detached HEAD}', not '$BRANCH' — not touching it"

if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
    die "tracked files have uncommitted changes on the server: $(git status --porcelain --untracked-files=no | tr '\n' ' ')"
fi

if ! FETCH_ERR="$(git fetch --quiet --no-tags origin "+refs/heads/$BRANCH:refs/remotes/origin/$BRANCH" 2>&1)"; then
    if [[ "$FETCH_ERR" != "$(cat "$STATE_DIR/last-fetch-error" 2>/dev/null)" ]]; then
        printf '%s' "$FETCH_ERR" > "$STATE_DIR/last-fetch-error"
        log "fetch of '$BRANCH' failed: $FETCH_ERR"
    fi
    [[ -f "$STATE_DIR/fetch-failing-since" ]] || touch "$STATE_DIR/fetch-failing-since"
    if [[ ! -f "$STATE_DIR/fetch-alerted" && -n "$(find "$STATE_DIR/fetch-failing-since" -mmin +30 2>/dev/null)" ]]; then
        touch "$STATE_DIR/fetch-alerted"
        send_alert "[$NAME] Auto-deploy cannot reach GitHub" \
"For over 30 minutes the server has failed to fetch '$BRANCH' from GitHub, so
new pushes are NOT being deployed. The site itself is unaffected.

  $FETCH_ERR

Usual cause: the server's GitHub deploy key was removed or the repository
was renamed. Log: $LOG"
    fi
    exit 1
fi
rm -f "$STATE_DIR/last-fetch-error" "$STATE_DIR/fetch-failing-since" "$STATE_DIR/fetch-alerted"

rm -f "$STATE_DIR/last-refusal"

OLD="$(git rev-parse HEAD)"
NEW="$(git rev-parse "refs/remotes/origin/$BRANCH")"

if [[ "$OLD" == "$NEW" ]]; then
    rm -f "$STATE_DIR/failed-commit"
    exit 0
fi
[[ "$NEW" == "$(cat "$STATE_DIR/failed-commit" 2>/dev/null)" ]] && exit 0

if ! git merge-base --is-ancestor "$OLD" "$NEW"; then
    printf '%s' "$NEW" > "$STATE_DIR/failed-commit"
    die "server is at ${OLD:0:7}, which is not an ancestor of origin/$BRANCH ${NEW:0:7} (server-only commits or a force-push). Fix by hand."
fi

# --- tools -------------------------------------------------------------------
# Find a composer that actually RUNS: on this host /usr/local/bin/composer can
# be a cPanel wrapper whose target is missing.
ORIGINAL_PATH="$PATH"
export PATH="$(dirname "$PHP"):$PATH"

composer_exec() {
    local bin="$1"; shift
    if [[ "$bin" == *.phar ]] || head -c 200 "$bin" 2>/dev/null | head -1 | grep -q php; then
        "$PHP" "$bin" "$@"
    else
        "$bin" "$@"
    fi
}

COMPOSER=""
TRIED=""
for c in "${COMPOSER_BIN:-}" \
         /opt/cpanel/composer/bin/composer \
         "$(PATH="$ORIGINAL_PATH" command -v composer 2>/dev/null)" \
         /usr/local/bin/composer \
         "$HOME/bin/composer" \
         "$HOME/composer.phar"; do
    [[ -n "$c" && -f "$c" ]] || continue
    if composer_exec "$c" --version --no-interaction >/dev/null 2>&1; then
        COMPOSER="$c"
        break
    fi
    TRIED+=" $c"
done
[[ -n "$COMPOSER" ]] || die "no working composer found (tried:${TRIED:- none}). Set COMPOSER_BIN in the cron line."

composer_run() { composer_exec "$COMPOSER" "$@"; }
artisan() { "$PHP" artisan "$@"; }

# Reads one key from .env, without the surrounding quotes.
env_value() {
    grep -E "^$1=" .env 2>/dev/null | tail -1 | cut -d= -f2- | sed -e 's/^["'"'"']//' -e 's/["'"'"']$//'
}

PG_DUMP="${PG_DUMP_BIN:-$(command -v pg_dump 2>/dev/null)}"
if [[ "${SKIP_BACKUP:-0}" != 1 ]]; then
    [[ "$(env_value DB_CONNECTION)" == pgsql ]] \
        || die "DB_CONNECTION in .env is '$(env_value DB_CONNECTION)', not pgsql — the pg_dump backup cannot run. Fix .env or set SKIP_BACKUP=1."
    [[ -n "$PG_DUMP" && -x "$PG_DUMP" ]] \
        || die "pg_dump not found, so no backup can be taken. Set PG_DUMP_BIN in the cron line, or SKIP_BACKUP=1 to deploy without backups."
fi

# Custom-format dump of the app's database, named after the commit it was
# taken on, so a failed migration can be undone with pg_restore.
backup_database() {
    mkdir -p "$BACKUP_DIR" || return 1
    local file="$BACKUP_DIR/predeploy-$(date '+%Y%m%d-%H%M%S')-${OLD:0:7}.dump"
    PGPASSWORD="$(env_value DB_PASSWORD)" "$PG_DUMP" \
        --host="$(env_value DB_HOST)" --port="$(env_value DB_PORT)" \
        --username="$(env_value DB_USERNAME)" --format=custom --no-owner \
        --file="$file" "$(env_value DB_DATABASE)" || { rm -f "$file"; return 1; }
    [[ -s "$file" ]] || { rm -f "$file"; echo "pg_dump wrote an empty file"; return 1; }
    ls -1t "$BACKUP_DIR"/predeploy-*.dump 2>/dev/null | tail -n +$((BACKUPS_KEPT + 1)) | xargs -r rm -f
    echo "Backup written: $file"
}

STEP_OUT="$STATE_DIR/step-output"
run_step() {
    local label="$1"; shift
    if "$@" > "$STEP_OUT" 2>&1; then
        log "  ok  $label"
        return 0
    fi
    log "  FAILED  $label — output:"
    sed 's/^/        /' "$STEP_OUT" | tail -40 >> "$LOG"
    return 1
}

# --- deploy ------------------------------------------------------------------
SUBJECT="$(git log -1 --format=%s "$NEW")"
DEPLOY_LOG_START=$(( $( { wc -l < "$LOG"; } 2>/dev/null || echo 0) + 1 ))
this_deploy_log() { tail -n +"$DEPLOY_LOG_START" "$LOG"; }
log "deploying $BRANCH ${OLD:0:7} -> ${NEW:0:7} ($SUBJECT) in $APP_DIR"
log "  using composer $COMPOSER, php $PHP"

BACKUP_LINE="No database backup was taken (SKIP_BACKUP=1)."
if [[ "${SKIP_BACKUP:-0}" != 1 ]]; then
    if ! run_step "database backup (pg_dump)" backup_database; then
        printf '%s' "$NEW" > "$STATE_DIR/failed-commit"
        die "no backup, so no deploy. Site untouched, still at ${OLD:0:7}. See $LOG for the pg_dump error."
    fi
    BACKUP_LINE="$(grep -o 'Backup written: .*' "$STEP_OUT" | head -1)"
fi

WENT_DOWN=0
bring_up() { [[ $WENT_DOWN == 1 ]] && artisan up > /dev/null 2>&1; WENT_DOWN=0; }

rollback() {
    local failed_step="$1"
    log "  rolling code back to ${OLD:0:7}"
    git reset --hard --quiet "$OLD" >> "$LOG" 2>&1 || log "  !! git reset failed — check $APP_DIR by hand"
    run_step "composer install (rollback)" composer_run install --no-dev --optimize-autoloader --no-interaction --no-progress
    run_step "optimize:clear (rollback)" artisan optimize:clear
    run_step "optimize (rollback)" artisan optimize
    run_step "queue:restart (rollback)" artisan queue:restart
    bring_up
    printf '%s' "$NEW" > "$STATE_DIR/failed-commit"
    log "DEPLOY FAILED at '$failed_step'. Site is back on ${OLD:0:7} and live. ${NEW:0:7} will not be retried until a newer commit is pushed."
    if [[ "$failed_step" == migrate* ]]; then
        log "  NOTE: some migrations may have run before the failure. Check 'php artisan migrate:status'. ${BACKUP_LINE}"
    fi
    send_alert "[$NAME] Deploy FAILED at $failed_step - site kept on old version" \
"Deploying ${NEW:0:7} \"$SUBJECT\" from '$BRANCH' failed at: $failed_step

The code was put back to ${OLD:0:7} and the site is live on that version.
${NEW:0:7} will not be retried; push a fixed commit to try again.

What happened:
$(this_deploy_log)"
    exit 1
}

if artisan down --retry=30 > /dev/null 2>&1; then
    WENT_DOWN=1
fi
trap 'bring_up; rmdir "$LOCK" 2>/dev/null' EXIT

run_step "git fast-forward to ${NEW:0:7}" git merge --ff-only --quiet "$NEW"        || rollback "git"
run_step "composer install" composer_run install --no-dev --optimize-autoloader --no-interaction --no-progress \
                                                                                 || rollback "composer"
run_step "migrate --force" artisan migrate --force                               || rollback "migrate"
run_step "filament:upgrade" artisan filament:upgrade                             || rollback "filament:upgrade"
run_step "optimize:clear" artisan optimize:clear                                 || rollback "optimize:clear"
run_step "optimize" artisan optimize                                             || rollback "optimize"
run_step "queue:restart" artisan queue:restart                                   || rollback "queue:restart"

bring_up
rm -f "$STATE_DIR/failed-commit"
printf '%s' "$NEW" > "$STATE_DIR/last-deployed"
log "DEPLOYED ${NEW:0:7} ($SUBJECT). ${BACKUP_LINE}"
send_alert "[$NAME] Deployed ${NEW:0:7}: $SUBJECT" \
"'$BRANCH' is live on $APP_DIR.

Commits in this release:
$(git log --format='  %h %s' "$OLD..$NEW" | head -30)

$(this_deploy_log)
${BACKUP_LINE}"
exit 0
}

main "$@"

#!/usr/bin/env bash
# Nightly restic backup of the host: to a repository on the host itself, or to
# the rest-server on a NAS (RESTIC_REPOSITORY). Runs as root, from
# herdbook-backup.service. Settings come from /etc/herdbook/backup.env
# (example: docker/backup/backup.env.example).
#
# Every step runs even when an earlier one failed (a broken MySQL dump must
# not also cost the Vaultwarden backup); the script fails at the end if any
# step did, so systemd records the run as failed. The exit code tells
# docker/backup/notify-failure.sh what to report:
#   1  the backup failed (or the settings are wrong)
#   3  the backup is fine, pruning old snapshots failed
#   4  the backup is fine, the disk of the repository is nearly full (or so
#      full that pruning failed)
#   5  the backup failed, and the disk of the repository is nearly full
set -uo pipefail

: "${RESTIC_REPOSITORY:?RESTIC_REPOSITORY is not set}"
: "${RESTIC_PASSWORD_FILE:?RESTIC_PASSWORD_FILE is not set}"

# Only the exact words: a typo must not switch pruning off without a sound.
case "${PRUNE_ON_BOX:-}" in
    true | false) ;;
    *)
        echo "PRUNE_ON_BOX in /etc/herdbook/backup.env must be true or false (now: '${PRUNE_ON_BOX:-}')." >&2
        exit 1
        ;;
esac

case "$RESTIC_REPOSITORY" in
    *[[:space:]]*)
        # systemd keeps a comment after a value as part of the value.
        echo "RESTIC_REPOSITORY contains a space: no comment after the value in /etc/herdbook/backup.env (now: '$RESTIC_REPOSITORY')." >&2
        exit 1
        ;;
    /*) on_box=true ;;
    *:*) on_box=false ;;
    *)
        echo "RESTIC_REPOSITORY must be an absolute path or a restic URL such as rest:http://… (now: '$RESTIC_REPOSITORY')." >&2
        exit 1
        ;;
esac

if [[ "$on_box" == true && "$PRUNE_ON_BOX" != true ]]; then
    echo "A repository on the host needs PRUNE_ON_BOX=true: nothing else removes its old snapshots, and the disk fills up." >&2
    exit 1
fi

HERDBOOK_DIR="${HERDBOOK_DIR:-/srv/herdbook}"
# shellcheck source=docker/lib/box-env.sh
. "$HERDBOOK_DIR/docker/lib/box-env.sh"
# The same data directory the containers use (docker/box.env).
DATA_DIR="$(box_setting "$HERDBOOK_DIR/docker/box.env" DATA_DIR)"
: "${DATA_DIR:?DATA_DIR is not set in $HERDBOOK_DIR/docker/box.env}"
if [[ "$on_box" == true && "${RESTIC_REPOSITORY%/}/" == "${DATA_DIR%/}/"* ]]; then
    echo "RESTIC_REPOSITORY lies inside DATA_DIR: every night would back the repository up into itself." >&2
    exit 1
fi
TAG=herdbook
failed=()

# A fixed host name: snapshots keep their history (and their parent) when the
# app moves to another host. --retry-lock: a restore test that still holds the
# repository does not fail the night.
# shellcheck disable=SC2329 # called through step()
restic_backup() {
    restic backup --host herdbook --tag "$TAG" --retry-lock 30m "$@"
}

# How full the disk of a repository on the host is, in percent (empty otherwise).
# A repository on the host shares its disk with MySQL, and pruning needs free
# space itself: a full disk cannot be pruned back, so it is reported in time.
disk_used() {
    [[ "$on_box" == true ]] || return 0
    df -P "$RESTIC_REPOSITORY" 2>/dev/null | awk 'NR == 2 { sub("%", "", $5); print $5 }'
}

disk_full() {
    local used
    used="$(disk_used)"
    if [[ "$used" =~ ^[0-9]+$ ]] && ((used >= 90)); then
        echo "!! the disk of $RESTIC_REPOSITORY is ${used}% full" >&2
        return 0
    fi
    return 1
}

step() {
    local name="$1"
    shift
    echo "== $name"
    if ! "$@"; then
        echo "!! $name failed" >&2
        failed+=("$name")
    fi
}

# 1. MySQL. restic runs the dump itself (restic >= 0.17): a failing mysqldump
#    fails the backup, instead of storing an empty file from a broken pipe.
#    Uncompressed on purpose, so restic can deduplicate between nights.
#    The single quotes are deliberate: the variables expand inside the container.
# shellcheck disable=SC2016
step "mysql" restic_backup --stdin-filename herdbook.sql --stdin-from-command -- \
    "$HERDBOOK_DIR/docker/compose.sh" exec -T mysql \
    sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump -uroot --single-transaction --routines --triggers "$MYSQL_DATABASE"'

# 2. Vaultwarden, when its profile is on (docker/box.env). A consistent copy of
#    its SQLite database through the online backup API; the live file (and its
#    -wal/-shm) is excluded below. When the services cannot be listed, the
#    step fails rather than be skipped: a silent hole in the backup is worse.
if services="$("$HERDBOOK_DIR/docker/compose.sh" config --services)"; then
    if grep -qx vaultwarden <<<"$services"; then
        step "vaultwarden-db" sqlite3 "$DATA_DIR/vaultwarden/db.sqlite3" ".backup '$DATA_DIR/vaultwarden/db-backup.sqlite3'"
    fi
else
    echo "!! vaultwarden-db failed: could not list the services" >&2
    failed+=("vaultwarden-db")
fi

# 3. Files: Vaultwarden (attachments, keys, the copy above), Caddy's CA (lan),
#    storage/app, and both env files. .env holds APP_KEY: without it the
#    encrypted two-factor secrets in the dump cannot be read, and nobody can
#    sign in after a restore.
step "files" restic_backup \
    --exclude "$DATA_DIR/vaultwarden/db.sqlite3" \
    --exclude "$DATA_DIR/vaultwarden/db.sqlite3-wal" \
    --exclude "$DATA_DIR/vaultwarden/db.sqlite3-shm" \
    "$DATA_DIR" "$HERDBOOK_DIR/.env" "$HERDBOOK_DIR/docker/box.env"

if ((${#failed[@]})); then
    echo "Backup incomplete, failed: ${failed[*]}" >&2
    disk_full && exit 5
    exit 1
fi

# The app warns when this gets old (`backups:check`); storage/app is bind-mounted.
if ! touch "$DATA_DIR/app/backup-last-success"; then
    echo "Backup done, but the marker for the app could not be written." >&2
    exit 1
fi
chown 33:33 "$DATA_DIR/app/backup-last-success"

echo "Backup complete."

# Old snapshots go here only when the repository is on the host itself, and
# only after a good backup: while backups fail, nothing old is thrown away. A
# NAS's append-only rest-server prunes itself (docs/backups.md).
if [[ "$PRUNE_ON_BOX" == true ]]; then
    echo "== prune"
    if ! "$HERDBOOK_DIR/docker/backup/prune.sh" "$RESTIC_REPOSITORY" "$RESTIC_PASSWORD_FILE"; then
        echo "!! prune failed (the backup itself is fine)" >&2
        disk_full && exit 4
        exit 3
    fi
fi

disk_full && exit 4
exit 0

#!/usr/bin/env bash
# Called by herdbook-backup-failed.service when the nightly backup failed.
#
# Reports through the app (`alerts:send`): the same channels as the checks,
# so ntfy, email or Pushover, as set up in .env. When the app cannot (the
# stack is down too), straight to NTFY_URL (and NTFY_TOKEN when the topic is
# protected) from /etc/herdbook/backup.env, if set. It also helps when only
# the database is down: the app then still tries its own ntfy, but cannot look
# up who gets Pushover or email.
#
# The timeout stops this script, not the command in the container: an app
# that is merely slow may still deliver, and the alert then arrives twice.
# Twice is better than never.
set -uo pipefail

# What failed, by the exit code of backup.sh (its header lists them). In
# English: this script runs outside the app and does not know its language.
case "$(systemctl show -p ExecMainStatus --value herdbook-backup.service 2>/dev/null)" in
    3)
        title="Backup pruning failed"
        body="The backup itself succeeded, pruning old snapshots did not. See: journalctl -u herdbook-backup"
        ;;
    4)
        title="Backup disk nearly full"
        body="The backup succeeded, but the disk with the backups is 90% full or more. See: df -h"
        ;;
    5)
        title="Backup failed: disk full"
        body="The nightly backup failed, and the disk with the backups is 90% full or more. See: df -h and journalctl -u herdbook-backup"
        ;;
    *)
        title="Backup failed"
        body="The nightly backup failed. See: journalctl -u herdbook-backup"
        ;;
esac
compose="${HERDBOOK_DIR:-/srv/herdbook}/docker/compose.sh"

if timeout 120 "$compose" exec -T app php artisan alerts:send "$title" "$body" --problem; then
    exit 0
fi

if [[ -z "${NTFY_URL:-}" ]]; then
    echo "The app could not send the alert and NTFY_URL is not set: the failed backup is not reported." >&2
    exit 1
fi

auth=()
if [[ -n "${NTFY_TOKEN:-}" ]]; then
    auth=(-H "Authorization: Bearer ${NTFY_TOKEN}")
fi

curl -fsS --max-time 15 "${auth[@]}" \
    -H "Title: ${title}" \
    -H "Priority: 4" \
    -H "Tags: floppy_disk" \
    -d "$body" \
    "$NTFY_URL" >/dev/null

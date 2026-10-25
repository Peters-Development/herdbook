#!/usr/bin/env bash
# Forget old snapshots and prune: the one retention policy for every place
# the backup lives.
#   - a repository on the host itself: docker/backup/backup.sh runs this after
#     every good backup (PRUNE_ON_BOX=true in /etc/herdbook/backup.env);
#   - an append-only rest-server on a NAS: the NAS's task scheduler runs it,
#     weekly, outside the backup window (the host cannot delete there).
# Refuses when a snapshot claims to be from the future: on a NAS a hijacked
# host can only append, but a snapshot with a forged date would otherwise push
# the real history out of every retention bucket. (Snapshots forged into the
# past are not caught: there the NAS's own filesystem snapshots are the net.)
# On the host itself a future date usually means a clock that jumped.
#   prune.sh /path/to/repository /path/to/restic-password
# Needs restic and jq.
set -euo pipefail

repository="${1:?usage: prune.sh <repository> <password-file>}"
password_file="${2:?usage: prune.sh <repository> <password-file>}"

now="$(date -u +%s)"
# --retry-lock: the nightly backup or a restore test may hold the repository.
future="$(restic -r "$repository" --password-file "$password_file" --retry-lock 30m snapshots --json \
    | jq --argjson now "$now" '[.[] | select((.time | sub("\\.[0-9]+"; "") | sub("(?<z>[+-][0-9]{2}:[0-9]{2}|Z)$"; "Z") | fromdateiso8601) > ($now + 86400))] | length')"

if [[ "$future" != "0" ]]; then
    echo "Refusing to prune: ${future} snapshot(s) dated in the future. Check the clock (timedatectl), then remove them with restic forget <id>." >&2
    exit 1
fi

restic -r "$repository" --password-file "$password_file" --retry-lock 30m \
    forget --keep-daily 14 --keep-weekly 8 --keep-monthly 12 --prune

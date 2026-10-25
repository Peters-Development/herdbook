#!/usr/bin/env bash
# Deploy on the host: pull, build, migrate, (re)start.
#   docker/deploy.sh
#
# The script pulls first and then re-executes itself, so a change to this
# file takes effect in the same deploy instead of the next one.
#
# Rolling back to an earlier commit (migrations are not rolled back: docs/deployment.md#rolling-back):
#   git checkout <sha> && docker/deploy.sh --pulled
# and back to normal afterwards:
#   git checkout main && docker/deploy.sh
set -euo pipefail

self="$(realpath "$0")"
docker_dir="$(dirname "$self")"
cd "$docker_dir/.."

if [[ "${1:-}" != "--pulled" ]]; then
    if ! git symbolic-ref -q HEAD >/dev/null; then
        echo "HEAD is a detached commit (a rollback?). Go back first: git checkout main" >&2
        exit 1
    fi

    before="$(git rev-parse HEAD)"
    git pull --ff-only
    exec "$self" --pulled "$before"
fi

compose="$docker_dir/compose.sh"

# Images we do not build (MySQL, Tailscale, Caddy, Vaultwarden) only change when pulled.
"$compose" pull --ignore-buildable

# Kept, so a failed migration can put them back: otherwise the next `up`
# (a reboot, docker/up.sh) would start the new code on the old schema.
previous_app="$(docker image inspect -f '{{.Id}}' herdbook-app 2>/dev/null || true)"
previous_web="$(docker image inspect -f '{{.Id}}' herdbook-web 2>/dev/null || true)"

"$compose" build --pull

# The schema first, with the new image in a one-off container.
"$compose" up -d --wait mysql

if ! "$compose" run --rm app php artisan migrate --force; then
    [[ -n "$previous_app" ]] && docker tag "$previous_app" herdbook-app
    [[ -n "$previous_web" ]] && docker tag "$previous_web" herdbook-web
    echo "Migration failed: the previous images are back in place and nothing was restarted." >&2
    exit 1
fi

"$docker_dir/up.sh"
"$compose" exec -T app php artisan queue:restart

# Caddy reads its config only at start, and a mounted file is not part of what
# `up` compares: recreated on every deploy (and rollback), so a changed
# Caddyfile always takes effect. It costs the LAN a second.
if grep -qx caddy <<<"$("$compose" config --services)"; then
    "$compose" up -d --no-deps --force-recreate caddy
fi

# The previous images are dangling now; without this every deploy leaves one behind.
docker image prune -f >/dev/null

# Units are linked from the checkout: systemd needs a reload to see changes.
if [[ -n "${2:-}" ]] && ! git diff --quiet "$2" HEAD -- docker/backup docker/boot; then
    echo "systemd units changed: run 'sudo systemctl daemon-reload' (and 'systemctl link' for new units, see docs/deployment.md#changing-settings)."
fi

"$compose" ps

#!/usr/bin/env bash
# `docker compose` for the host: the production compose file with both env
# files (docker/docker-compose.prod.yml explains which holds what).
#   docker/compose.sh ps
#   docker/compose.sh exec app php artisan users:create
#
# The profiles (how the app is reached) come from COMPOSE_PROFILES in
# docker/box.env, and the settings each one needs are checked here: compose
# itself cannot require a variable for a profile that is on only.
set -euo pipefail

root="$(cd "$(dirname "$(realpath "$0")")/.." && pwd)"
box_env="$root/docker/box.env"

if [[ ! -f "$box_env" ]]; then
    echo "docker/box.env is missing: copy docker/box.env.example (docs/deployment.md#configuration)." >&2
    exit 1
fi

# shellcheck source=docker/lib/box-env.sh
. "$root/docker/lib/box-env.sh"

setting() {
    box_setting "$box_env" "$1"
}

profiles="$(setting COMPOSE_PROFILES)"
profiles="${profiles// /}"

reachable=false
names=()
IFS=, read -ra names <<<"$profiles"
for name in ${names[@]+"${names[@]}"}; do
    case "$name" in
        tailscale | lan) reachable=true ;;
        vaultwarden) ;;
        *)
            echo "Unknown profile in COMPOSE_PROFILES (docker/box.env): '$name'. Use tailscale, lan and/or vaultwarden." >&2
            exit 1
            ;;
    esac
done

if [[ "$reachable" != true ]]; then
    echo "COMPOSE_PROFILES in docker/box.env needs tailscale, lan or both: otherwise nobody can reach the app." >&2
    exit 1
fi

if [[ ",$profiles," == *,lan,* ]]; then
    lan_ip="$(setting LAN_IP)"
    # One address: 0.0.0.0 would publish HTTPS on every network of the host,
    # the tailnet included.
    if ! [[ "$lan_ip" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] || [[ "$lan_ip" == 0.0.0.0 ]]; then
        echo "Profile lan: set LAN_IP in docker/box.env to the host's IPv4 address on the local network (now: '$lan_ip')." >&2
        exit 1
    fi
    if [[ "$(setting LAN_HOSTNAMES)" == *,* ]]; then
        echo "Profile lan: separate the names in LAN_HOSTNAMES (docker/box.env) with spaces, not commas." >&2
        exit 1
    fi
fi

if [[ ",$profiles," == *,vaultwarden,* && -z "$(setting TAILNET_DOMAIN)" ]]; then
    echo "Profile vaultwarden: set TAILNET_DOMAIN in docker/box.env." >&2
    exit 1
fi

# Exported, so a COMPOSE_PROFILES left in the shell cannot pick other profiles.
export COMPOSE_PROFILES="$profiles"

exec docker compose \
    --project-directory "$root/docker" \
    --env-file "$root/.env" \
    --env-file "$box_env" \
    -f "$root/docker/docker-compose.prod.yml" \
    "$@"

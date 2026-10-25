#!/usr/bin/env bash
# Bring the stack on the host up in a known-good state. Used by
# docker/deploy.sh and at boot (docker/boot/herdbook-stack.service).
#
# Services of a profile that was switched off in docker/box.env are removed
# first: `up` leaves them running otherwise (they are no orphans to compose).
#
# Caddy (profile lan) gets its own `up`, after the rest: when it cannot bind
# LAN_IP (the host got another address, or something holds the port), compose
# aborts the whole start, and a LAN problem must not stop the checks.
#
# web and vaultwarden live in the network namespace of their Tailscale
# sidecar. When a sidecar restarts or is recreated (a changed env, a reboot
# in the wrong order), they keep the old, dead namespace and stop answering;
# recreating them joins the current one. It costs a few seconds. Only the
# ones whose profile is on: naming a service would start it regardless.
# With --no-deps, so they come back while MySQL is still recovering. Compose
# 2.20 cannot find the sidecar that way ("network service:ts-herdbook not
# found"); then once more with the dependencies (the sidecar is only started
# if it is not running, not recreated).
#
# Every step runs even when an earlier one fails (e.g. MySQL still recovering
# after a power cut): Vaultwarden does not need MySQL, and it is the tool you
# need when the rest is broken.
set -uo pipefail

compose="$(dirname "$(realpath "$0")")/compose.sh"
status=0

active="$("$compose" config --services)" || exit 1
all="$("$compose" --profile '*' config --services)" || exit 1

inactive=()
while IFS= read -r service; do
    inactive+=("$service")
done < <(comm -23 <(sort <<<"$all") <(sort <<<"$active"))

main=()
while IFS= read -r service; do
    main+=("$service")
done < <(grep -vx caddy <<<"$active")

if ((${#inactive[@]})); then
    "$compose" rm --stop --force "${inactive[@]}" || status=$?
fi

"$compose" up -d --remove-orphans "${main[@]}" || status=$?

if grep -qx caddy <<<"$active"; then
    "$compose" up -d caddy || status=$?
fi

for service in vaultwarden web; do
    if grep -qx "$service" <<<"$active"; then
        "$compose" up -d --no-deps --force-recreate "$service" \
            || "$compose" up -d --force-recreate "$service" \
            || status=$?
    fi
done

exit "$status"

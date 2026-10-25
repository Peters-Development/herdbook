#!/usr/bin/env bash
# Sourced by docker/compose.sh and docker/backup/backup.sh: one way to read
# docker/box.env (the host's settings), the same way compose reads it.

# box_setting FILE NAME: the last value of NAME in FILE, with an optional
# `export `, quotes around the value or an inline comment after an unquoted
# one, and Windows line endings taken off.
box_setting() {
    local file="$1" name="$2" value quote
    value="$(sed -n -E "s/^(export[[:space:]]+)?${name}=//p" "$file" | tail -n 1 | tr -d '\r' | sed -E 's/^[[:space:]]+//')"
    quote="${value:0:1}"
    if [[ "$quote" == '"' || "$quote" == "'" ]]; then
        value="${value:1}"
        value="${value%%"$quote"*}"
    else
        value="$(printf '%s' "$value" | sed -E 's/[[:space:]]+#.*$//; s/[[:space:]]+$//')"
    fi
    printf '%s' "$value"
}

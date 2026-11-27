#!/bin/sh
# Demo, development and tests only. Creates each secret once and never overwrites it.
# Isolation comes from mounting each volume only where it is needed.
set -eu
base=/run/app-secrets

create() {
    file="$base/$1"
    [ -s "$file" ] && return 0
    php -r "$2" >"$file.tmp"
    chmod 444 "$file.tmp"
    mv "$file.tmp" "$file"
}

password='echo bin2hex(random_bytes(24));'

create runtime/app_key 'echo "base64:".base64_encode(random_bytes(32));'
create runtime/db_password "$password"
create owner/db_password "$password"
create maintenance/db_password "$password"
create superuser/password "$password"

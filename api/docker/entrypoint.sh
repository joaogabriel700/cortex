#!/bin/sh
# Turns VAR_FILE into VAR (values are never printed), then runs the command.
set -eu

for var in APP_KEY DB_PASSWORD DB_OWNER_PASSWORD DB_MAINTENANCE_PASSWORD MAIL_PASSWORD \
    VAPID_PRIVATE_KEY TICK_SECRET AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY; do
    eval "file=\${${var}_FILE:-}"
    if [ -n "$file" ]; then
        [ -r "$file" ] || { echo "entrypoint: cannot read ${var}_FILE" >&2; exit 1; }
        value="$(cat "$file")"
        export "$var=$value"
    fi
done
unset value

# Some platforms choose the HTTP port through PORT.
if [ -n "${PORT:-}" ]; then
    export SERVER_NAME=":${PORT}"
fi

# Configuration is cached at start, never at build time, so images carry no environment.
if [ "${APP_OPTIMIZE:-true}" = "true" ]; then
    php artisan optimize >/dev/null
fi

# Refuse to start with a database role that could bypass row level security.
if [ "${DB_ASSERT_ROLE:-false}" = "true" ]; then
    php artisan db:assert-role
fi

exec "$@"

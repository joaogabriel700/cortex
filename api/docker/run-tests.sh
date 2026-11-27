#!/bin/sh
# Migrates the test database as the owner, then runs the tests as the runtime role.
set -eu

if [ "${DB_DATABASE:-}" != "cortex_test" ]; then
    echo "run-tests: refusing to run outside the cortex_test database" >&2
    exit 1
fi

DB_USERNAME=app_owner DB_PASSWORD="${DB_OWNER_PASSWORD:?}" \
    php artisan migrate:fresh --force --drop-views --drop-types

exec vendor/bin/pest "$@"

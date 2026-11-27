#!/bin/sh
# Release step: runs once per version, before the other processes start.
set -eu
exec php artisan migrate --force

#!/bin/sh
# One-off trigger for hosts without always-on processes: runs the due recurring tasks
# (catch-up, so late triggers skip nothing), then drains the queue once.
set -u
status=0
php artisan app:tick || status=$?
php artisan queue:work --stop-when-empty --tries=3 --timeout=60 --max-time="${TICK_MAX_TIME:-50}" || status=$?
exit "$status"

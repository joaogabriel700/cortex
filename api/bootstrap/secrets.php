<?php

/*
| Reads VAR from the file named by VAR_FILE when VAR is not set. The container entrypoint
| already does this for its own process; this covers processes that do not go through it,
| such as `docker compose exec api php artisan ...`. Values are never printed.
*/
foreach (['APP_KEY', 'DB_PASSWORD', 'DB_OWNER_PASSWORD', 'DB_MAINTENANCE_PASSWORD', 'MAIL_PASSWORD',
    'VAPID_PRIVATE_KEY', 'TICK_SECRET', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY'] as $name) {
    $file = getenv($name.'_FILE');

    if (getenv($name) !== false || $file === false || $file === '' || ! is_readable($file)) {
        continue;
    }

    $value = rtrim((string) file_get_contents($file), "\r\n");
    putenv("{$name}={$value}");
    $_ENV[$name] = $_SERVER[$name] = $value;
}

unset($name, $file, $value);

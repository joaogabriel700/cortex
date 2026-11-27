<?php

$postgres = static fn (?string $username, ?string $password): array => [
    'driver' => 'pgsql',
    'url' => env('DB_URL'),
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '5432'),
    'database' => env('DB_DATABASE', 'cortex'),
    'username' => $username,
    'password' => $password,
    'charset' => env('DB_CHARSET', 'utf8'),
    'prefix' => '',
    'prefix_indexes' => true,
    'search_path' => 'public',
    'sslmode' => env('DB_SSLMODE', 'prefer'),
];

return [

    'default' => env('DB_CONNECTION', 'pgsql'),

    /*
    | Three connections to the same PostgreSQL database:
    | - pgsql: application data, as app_runtime (as app_owner only in the migrate step).
    | - pgsql_infra: same role, separate session, for cache, locks, sessions and rate limiting,
    |   so that a rolled back request does not undo them.
    | - pgsql_maintenance: app_maintenance. Credentials exist only in the scheduler. Use it
    |   explicitly: never as the default, never in HTTP requests or queued jobs.
    */
    'connections' => [

        'pgsql' => $postgres(env('DB_USERNAME', 'app_runtime'), env('DB_PASSWORD', '')),

        'pgsql_infra' => $postgres(env('DB_USERNAME', 'app_runtime'), env('DB_PASSWORD', '')),

        'pgsql_maintenance' => $postgres(env('DB_MAINTENANCE_USERNAME'), env('DB_MAINTENANCE_PASSWORD')),

    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

];

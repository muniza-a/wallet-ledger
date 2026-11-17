<?php

$mysql = [
    'driver' => 'mysql',
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '3306'),
    'database' => env('DB_DATABASE', 'ledger'),
    'username' => env('DB_USERNAME', 'ledger'),
    'password' => env('DB_PASSWORD', 'ledger'),
    'unix_socket' => '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'prefix_indexes' => true,
    'strict' => true,
    'engine' => 'InnoDB',
    'options' => [
        // Reuse the MySQL connection across requests in the same FPM worker:
        // saves the TCP + auth handshake on every request. PDO rolls back any
        // open transaction when a persistent handle is released.
        PDO::ATTR_PERSISTENT => (bool) env('DB_PERSISTENT', false),
        // Laravel defaults to server-side prepares: prepare + execute + close = 3 round
        // trips per query. Emulated prepares send one round trip; values are still
        // escaped by the driver and ints are bound as PARAM_INT.
        PDO::ATTR_EMULATE_PREPARES => true,
    ],
];

return [
    'default' => env('DB_CONNECTION', 'mysql'),

    'connections' => [
        'mysql' => $mysql,

        // Used only by `php artisan ledger:seed` for LOAD DATA LOCAL INFILE.
        // The API connection never gets local-infile rights.
        'seed' => array_merge($mysql, [
            'options' => [
                PDO::ATTR_PERSISTENT => false,
                PDO::MYSQL_ATTR_LOCAL_INFILE => true,
            ],
        ]),
    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],
];

<?php

return [

    'default' => env('DB_CONNECTION', 'mysql'),

    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DATABASE_URL'),

            // Read/Write Separation — production scale
            // Writes go to primary, reads distribute across replicas
            'read' => [
                'host' => explode(',', env('DB_READ_HOST', env('DB_HOST', '127.0.0.1'))),
            ],
            'write' => [
                'host' => [env('DB_HOST', '127.0.0.1')],
            ],
            'sticky' => true, // Use write connection for subsequent reads in same request

            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'boss888'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => 'InnoDB',
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                \PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

];

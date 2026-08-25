<?php
return [
    'default' => env('queue.connection', 'database'),

    'connections' => [
        'database' => [
            'group' => env('queue.database.group', 'default'),
            'shared' => true,
            'skip_locked' => true,
            'table' => env('queue.database.table', 'jobs'),
        ],
        'redis' => [
            'driver' => 'redis',
            'host' => env('redis.host', '127.0.0.1'),
            'password' => env('redis.password', null),
            'port' => env('redis.port', 6379),
            'database' => env('redis.database', 0),
        ],
        'predis' => [
            'driver' => 'predis',
            'scheme' => 'tcp',
            'host' => env('redis.host', '127.0.0.1'),
            'password' => env('redis.password', null),
            'port' => env('redis.port', 6379),
            'database' => env('redis.database', 0),
        ],
        'rabbitmq' => [
            'driver' => 'rabbitmq',
            'host' => env('rabbitmq.host', '127.0.0.1'),
            'port' => env('rabbitmq.port', 5672),
            'user' => env('rabbitmq.user', 'guest'),
            'password' => env('rabbitmq.password', 'guest'),
            'vhost' => env('rabbitmq.vhost', '/'),
        ],
    ],

    'drivers' => [
        'database' => \BlitzPHP\Queue\Drivers\Database::class,
        'redis' => \BlitzPHP\Queue\Drivers\Redis::class,
        'predis' => \BlitzPHP\Queue\Drivers\Predis::class,
        'rabbitmq' => \BlitzPHP\Queue\Drivers\RabbitMQ::class,
    ],

    'keep_failed_jobs' => true,

    'failed' => [
        'driver' => env('queue.failed_driver', 'database-uuids'),
        'database' => env('db.connection', 'default'),
        'table' => 'failed_jobs',
    ],

    'batching' => [
        'database' => env('db.connection', 'default'),
        'table' => 'job_batches',
    ],
];

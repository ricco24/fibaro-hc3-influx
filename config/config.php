<?php

return [
    'influx' => [
        'host' => '',
        'database' => '',
        'port' => 8086,
        'username' => '',
        'password' => '',
        'ssl' => false,
        'verifySSL' => false,
        'timeout' => 0,
        'connectTimeout' => 0
    ],
    'hc' => [
        'url' => '',
        'username' => '',
        'password' => '',
        'verifySSL' => false
    ],
    'lastFile' => [
        'devices' => '/last-devices',
        'events' => '/last-events'
    ]
];
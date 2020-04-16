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
    'storage' => [
        'type' => 'file',
        'directory' => '../shared/storage'
    ],
    'commands' => [
        'consumption' => [
            'devices' => [
                40, // bojler
                47, // radiator
                49  // obehovka
            ]
        ]
    ]
];
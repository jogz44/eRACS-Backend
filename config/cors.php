<?php

return [
    // 'paths' => ['api/*', 'sanctum/csrf-cookie', 'upload-photo'],
    // 'allowed_methods' => ['*'],
    // 'allowed_origins' => ['*'], // Allow all origins for development
    // 'allowed_origins_patterns' => [],
    // 'allowed_headers' => ['*'],
    // 'exposed_headers' => [],
    // 'max_age' => 0,
    // 'supports_credentials' => true, // Set to true




        'paths' => ['api/*', 'sanctum/csrf-cookie'],

        'allowed_methods' => ['*'],

        'allowed_origins' => [
            'http://10.0.1.23:3002',
            'http://192.168.8.33:3001',
            'http://localhost:9000',
            'http://192.168.8.67:9000',  // sheena IP

        ],

        'allowed_origins_patterns' => [],

        'allowed_headers' => ['*'],

        'exposed_headers' => [],

        'max_age' => 0,

        'supports_credentials' => false,

];

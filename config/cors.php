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
        ],

        'allowed_origins_patterns' => [],

        'allowed_headers' => ['*'],

        'exposed_headers' => [],

        'max_age' => 0,

        'supports_credentials' => false,

];

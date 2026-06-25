<?php
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', '*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'https://kambeng-market-frontend.onrender.com',
        'http://localhost:5173',
        'http://localhost:3000',
        env('FRONTEND_URL', '*'),
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true, // Set to true if using cookies/sanctum
];

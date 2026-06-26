<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', '*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'https://kambeng-market-frontend.vercel.app',
        'https://kambeng-market-frontend-kwt8u842f-singhateh3s-projects.vercel.app',
        '*.vercel.app',
        'http://localhost:5173',
        'http://localhost:3000',
        'http://localhost:8000',
    ],
    'allowed_origins_patterns' => [
        '/^https:\/\/.*\.vercel\.app$/',
        '/^https:\/\/.*-singhateh3s-projects\.vercel\.app$/',
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];

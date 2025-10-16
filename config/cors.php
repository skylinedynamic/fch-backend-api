<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    // Configure allowed origins via env; default to Vite dev server
    'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173')),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['Authorization', 'Content-Type'],
    'max_age' => 0,
    'supports_credentials' => false,
];
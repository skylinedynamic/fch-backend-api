<?php

return [
    'paths' => ['api/*', 'resolve-custom-domain', 'caddy/domain-check'],
    'allowed_methods' => ['*'],
    'allowed_origins' => ['*'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [
        'Authorization',
        'Content-Type',
        'X-Tenant-Type',
        'X-Partner-Id',
    ],
    'max_age' => 0,
    'supports_credentials' => true, 
];

<?php
return [
    'paths' => ['api/*', 'resolve-custom-domain', 'caddy/domain-check'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'https://fourcornerfunding.com',
        'https://partner.fourcornerfunding.com',
        'https://app.fourcornerfunding.com',
        'https://admin.fourcornerfunding.com',
    ],
    'allowed_origins_patterns' => [
        // Wildcard for any subdomain of fourcornerfunding.com
        '#^https://[a-zA-Z0-9\-]+\.fourcornerfunding\.com$#',
        // Custom tenant domains
        '#^https://[a-zA-Z0-9\-]+\.[a-zA-Z0-9\-]+\.[a-zA-Z]{2,}$#',
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => [
        'Authorization',
        'Content-Type',
        'X-Tenant-Type',
        'X-Partner-Id',
    ],
    'max_age' => 86400,
    'supports_credentials' => true,
];

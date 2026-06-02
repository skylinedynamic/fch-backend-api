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
        // Match all origins — supports any domain, subdomain, custom domain, HTTP or HTTPS
        '#^https?://.*$#',
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

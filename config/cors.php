<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'https://partner.fourcornerfunding.com',
        'https://app.fourcornerfunding.com',
        'https://fourcornerfunding.com',
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['Authorization', 'Content-Type'],
    'max_age' => 0,
    'supports_credentials' => true, // Important if using token/cookies
];

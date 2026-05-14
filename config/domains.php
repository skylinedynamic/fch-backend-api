<?php

return [
    'reserved' => [
        'fourcornerfunding.com',
        'www.fourcornerfunding.com',
        'api.fourcornerfunding.com',
        'partner.fourcornerfunding.com',
        'app.fourcornerfunding.com',
    ],

    'base_domain' => env('APP_BASE_DOMAIN'),
    'internal_key' => env('INTERNAL_RESOLVER_KEY', 'change-this-secret'),
];
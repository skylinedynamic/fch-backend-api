<?php

return [
    'reserved' => [
        'base_reserved_website' => env('BASE_WEBSITE', 'fourcornerfunding.com'),
        'base_reserved_api_portal' => env('BASE_API_URL', 'api.fourcornerfunding.com'),
        'base_reserved_partner_portal' => env('BASE_PARTNER_PORTAL', 'partner.fourcornerfunding.com'),
        'base_reserved_borrower_portal' => env('BASE_BORROWER_PORTAL', 'app.fourcornerfunding.com'),
    ],

    'domain_check' => env('DOMAIN_CHECK', 'fourcornerfunding.com'),
    'internal_key' => env('INTERNAL_RESOLVER_KEY', 'fourcornerfunding2026'),
];
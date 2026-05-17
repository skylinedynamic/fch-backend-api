<?php

return [
    'reserved' => [
        env('BASE_RESERVED_WEBSITE', 'fourcornerfunding.com'),
        env('BASE_RESERVED_API_PORTAL', 'api.fourcornerfunding.com'),
        env('BASE_RESERVED_PARTNER_PORTAL', 'partner.fourcornerfunding.com'),
        env('BASE_RESERVED_BORROWER_PORTAL', 'app.fourcornerfunding.com'),
    ],

    'domain_check' => env('DOMAIN_CHECK', 'fourcornerfunding.com'),
    'internal_key' => env('INTERNAL_RESOLVER_KEY', 'fourcornerfunding2026'),
];
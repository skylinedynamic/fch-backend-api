<?php

return [
    'reserved' => [
        'base_reserved_website' => env('BASE_WEBSITE', 'fourcornerfunding.com'),
        'base_reserved_api_portal' => env('BASE_API_URL', 'api.fourcornerfunding.com'),
        'base_reserved_partner_portal' => env('BASE_PARTNER_PORTAL', 'partner.fourcornerfunding.com'),
        'base_reserved_borrower_portal' => env('BASE_BORROWER_PORTAL', 'app.fourcornerfunding.com'),
    ],

    'domain_check' => env('DOMAIN_CHECK', 'fourcornerfunding.com'),

    // Brand names a partner's custom domain may not use or imitate, in every
    // environment (R3-BUG-021): on any TLD, in any label, or a letter or two
    // off. Comma-separated; letters and digits only are compared.
    'protected_brands' => array_values(array_filter(array_map('trim', explode(',', (string) env('PROTECTED_BRANDS', 'fourcornerfunding'))))),
    // Shared secret the edge proxy sends as X-Internal-Key. No default: an
    // environment that never sets it refuses every lookup rather than accepting
    // a value anyone can read in this repository.
    'internal_key' => env('INTERNAL_RESOLVER_KEY'),
];
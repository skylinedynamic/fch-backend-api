<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Permission List
    |--------------------------------------------------------------------------
    |
    | Define all system-wide permissions here.
    | These will be seeded for every business automatically.
    | is_host: 1 = Only for host partners, 0 = For regular partners
    |
    */

    'default' => [
        'is_host' => 0,
        'permissions' => [
            'view dashboard',
        ],
    ],

    'partners' => [
        'is_host' => 1,
        'permissions' => [
            'view partners',
            'create partners',
            'update partners',
            'delete partners',
        ],
    ],

    'demo request' => [
        'is_host' => 1,
        'permissions' => [
            'view demo request',
            'create demo request',
            'update demo request',
            'delete demo request',
        ],
    ],

    'leads' => [
        'is_host' => 0,
        'permissions' => [
            'view leads',
            'create leads',
            'update leads',
            'delete leads',
        ],
    ],

    'contacts' => [
        'is_host' => 0,
        'permissions' => [
            'view contacts',
            'create contacts',
            'update contacts',
            'delete contacts',
        ],
    ],

    'clients' => [
        'is_host' => 0,
        'permissions' => [
            'view clients',
            'create clients',
            'update clients',
            'delete clients',
        ],
    ],

    'commission' => [
        'is_host' => 0,
        'permissions' => [
            'view commission',
            'create commission',
            'update commission',
            'delete commission',
        ],
    ],

    'payments' => [
        'is_host' => 0,
        'permissions' => [
            'view payments',
            'create payments',
            'update payments',
            'delete payments',
        ],
    ],

    'invoices' => [
        'is_host' => 0,
        'permissions' => [
            'view invoices',
            'create invoices',
            'update invoices',
            'delete invoices',
        ],
    ],

    'subscriptions' => [
        'is_host' => 0,
        'permissions' => [
            'view subscriptions',
            'create subscriptions',
            'update subscriptions',
            'delete subscriptions',
        ],
    ],

    'appointments' => [
        'is_host' => 0,
        'permissions' => [
            'view appointments',
            'create appointments',
            'update appointments',
            'delete appointments',
        ],
    ],

    'training & courses' => [
        'is_host' => 0,
        'permissions' => [
            'view training & courses',
            'create training & courses',
            'update training & courses',
            'delete training & courses',
        ],
    ],

    'support tickets' => [
        'is_host' => 0,
        'permissions' => [
            'view support tickets',
            'create support tickets',
            'update support tickets',
            'delete support tickets',
        ],
    ],

    'resources' => [
        'is_host' => 0,
        'permissions' => [
            'view resources',
            'create resources',
            'update resources',
            'delete resources',
        ],
    ],

    'employees' => [
        'is_host' => 0,
        'permissions' => [
            'view employees',
            'create employees',
            'update employees',
            'delete employees',
        ],
    ],

    'users' => [
        'is_host' => 0,
        'permissions' => [
            'view users',
            'create users',
            'update users',
            'delete users',
        ],
    ],

    'roles & permissions' => [
        'is_host' => 0,
        'permissions' => [
            'view roles & permissions',
            'create roles & permissions',
            'update roles & permissions',
            'delete roles & permissions',
        ],
    ],

    'settings' => [
        'is_host' => 0,
        'permissions' => [
            'view settings',
            'create settings',
            'update settings',
            'delete settings',
        ],
    ],

    'tiers' => [
        'is_host' => 1,
        'permissions' => [
            'view tiers',
            'add tiers',
            'edit tiers',
            'delete tiers',
        ],
    ],

    'verification center' => [
        'is_host' => 0,
        'permissions' => [
            'view verification center',
            'add verification center',
            'edit verification center',
            'delete verification center',
        ],
    ],

    'borrower documents' => [
        'is_host' => 0,
        'permissions' => [
            'view borrower documents',
            'upload borrower documents',
            'update borrower documents status',
            'delete borrower documents',
        ],
    ],
];

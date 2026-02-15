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

    'dashboard' => [
        'permissions' => [
            ['name' => 'view dashboard', 'is_wl' => 1, 'is_host' => 0],
        ],
    ],

    'partners' => [
        'permissions' => [
            ['name' => 'view partners', 'is_wl' => 0, 'is_host' => 1],
            ['name' => 'create partners', 'is_wl' => 0, 'is_host' => 1],
            ['name' => 'update partners', 'is_wl' => 0, 'is_host' => 1],
            ['name' => 'delete partners', 'is_wl' => 0, 'is_host' => 1],
        ],
    ],

    'demo request' => [
        'permissions' => [
            ['name' => 'view demo request', 'is_wl' => 0, 'is_host' => 1],
            ['name' => 'create demo request', 'is_wl' => 0, 'is_host' => 1],
            ['name' => 'update demo request', 'is_wl' => 0, 'is_host' => 1],
            ['name' => 'delete demo request', 'is_wl' => 0, 'is_host' => 1]
        ],
    ],

    'leads' => [
        'permissions' => [
            ['name' => 'view leads', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'create leads', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'update leads', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'delete leads', 'is_wl' => 1, 'is_host' => 0],
        ],
    ],

    'contacts' => [
        'permissions' => [
            ['name' => 'view contacts', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'create contacts', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'update contacts', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'delete contacts', 'is_wl' => 1, 'is_host' => 0],
        ],
    ],

    'clients' => [
        'permissions' => [
            ['name' => 'view clients', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'create clients', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'update clients', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'delete clients', 'is_wl' => 1, 'is_host' => 0],
        ],
    ],

    'intake and review' => [
        'permissions' => [
            ['name' => 'view pre qualification', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'update pre qualification', 'is_wl' => 0, 'is_host' => 1],
            ['name' => 'view funding application', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'update funding application', 'is_wl' => 0, 'is_host' => 1],
        ],
    ],

    'commission' => [
        'permissions' => [
            ['name' => 'view commission', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'update commission', 'is_wl' => 0, 'is_host' => 1],
        ],
    ],

    'payments' => [
        'permissions' => [
            ['name' => 'view payments', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'create payments', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'update payments', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'delete payments', 'is_wl' => 1, 'is_host' => 0],
        ],
    ],

    'invoices' => [
        'permissions' => [
            ['name' => 'view invoices', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'create invoices', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'update invoices', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'delete invoices', 'is_wl' => 1, 'is_host' => 0],
        ],
    ],

    'subscriptions' => [
        'permissions' => [
            ['name' => 'view subscriptions', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'create subscriptions', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'update subscriptions', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'delete subscriptions', 'is_wl' => 1, 'is_host' => 0],
        ],
    ],

    'appointments' => [
        'permissions' => [
            ['name' => 'view appointments', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'create appointments', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'update appointments', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'delete appointments', 'is_wl' => 1, 'is_host' => 0],
        ],
    ],

    'training & courses' => [
        'permissions' => [
            ['name' => 'view training & courses', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'create training & courses', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'update training & courses', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'delete training & courses', 'is_wl' => 1, 'is_host' => 0],
        ],
    ],

    'support tickets' => [
        'permissions' => [
            ['name' => 'view support tickets', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'create support tickets', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'update support tickets', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'delete support tickets', 'is_wl' => 1, 'is_host' => 0],
        ],
    ],

    'resources' => [
        'permissions' => [
            ['name' => 'view resources', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'create resources', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'update resources', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'delete resources', 'is_wl' => 1, 'is_host' => 0],
        ],
    ],

    'employees' => [
        'permissions' => [
            ['name' => 'view employees', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'create employees', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'update employees', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'delete employees', 'is_wl' => 1, 'is_host' => 0],
        ],
    ],

    'users' => [
        'permissions' => [
            ['name' => 'view users', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'create users', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'update users', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'delete users', 'is_wl' => 1, 'is_host' => 0],
        ],
    ],

    'borrower documents' => [
        'permissions' => [
            ['name' => 'view borrower documents', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'upload borrower documents', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'update borrower documents status', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'delete borrower documents', 'is_wl' => 1, 'is_host' => 0],
        ],
    ],

    'contacts' => [
        'permissions' => [
            ['name' => 'view contacts', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'create contacts', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'update contacts', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'delete contacts', 'is_wl' => 1, 'is_host' => 0],
        ],
    ],

    'credit builder' => [
        'permissions' => [
            ['name' => 'view tier', 'is_wl' => 0, 'is_host' => 1],
            ['name' => 'update tier', 'is_wl' => 0, 'is_host' => 1],
            ['name' => 'view verification request', 'is_wl' => 0, 'is_host' => 1],
            ['name' => 'update verification request', 'is_wl' => 0, 'is_host' => 1],
        ],
    ],

    'roles & permissions' => [
        'permissions' => [
            ['name' => 'view roles & permissions', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'create roles & permissions', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'update roles & permissions', 'is_wl' => 1, 'is_host' => 0],
            ['name' => 'delete roles & permissions', 'is_wl' => 1, 'is_host' => 0],
        ],
    ],

    'settings' => [
        'permissions' => [
            ['name' => 'view settings', 'is_wl' => 0, 'is_host' => 1],
            ['name' => 'create settings', 'is_wl' => 0, 'is_host' => 1],
            ['name' => 'update settings', 'is_wl' => 0, 'is_host' => 1],
            ['name' => 'delete settings', 'is_wl' => 0, 'is_host' => 1],
        ],
    ],
];

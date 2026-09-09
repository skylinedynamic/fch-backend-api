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

    // 'dashboard' => [
    //     'permissions' => [
    //         ['name' => 'view dashboard', 'is_certified' => 1, 'is_host' => 0],
    //     ],
    // ],

    'partners' => [
        'permissions' => [
            ['name' => 'view partners', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'create partners', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'update partners', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'delete partners', 'is_certified' => 0, 'is_host' => 1],
        ],
    ],

    'demo request' => [
        'permissions' => [
            ['name' => 'view demo request', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'create demo request', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'update demo request', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'delete demo request', 'is_certified' => 0, 'is_host' => 1]
        ],
    ],

    'leads' => [
        'permissions' => [
            ['name' => 'view leads', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'create leads', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'update leads', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'delete leads', 'is_certified' => 1, 'is_host' => 0],
        ],
    ],

    'contacts' => [
        'permissions' => [
            ['name' => 'view contacts', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'create contacts', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'update contacts', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'delete contacts', 'is_certified' => 1, 'is_host' => 0],
        ],
    ],

    'clients' => [
        'permissions' => [
            ['name' => 'view clients', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'create clients', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'update clients', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'delete clients', 'is_certified' => 1, 'is_host' => 0],
        ],
    ],

    'intake and review' => [
        'permissions' => [
            ['name' => 'view pre qualification', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'update pre qualification', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'view funding application', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'update funding application', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'view funding request', 'is_certified' => 0, 'is_host' => 0],
            ['name' => 'update funding request', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'view change requests', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'update change requests', 'is_certified' => 0, 'is_host' => 1],
        ],
    ],

    'commission' => [
        'permissions' => [
            ['name' => 'view commission', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'update commission', 'is_certified' => 0, 'is_host' => 1],
        ],
    ],

    'payments' => [
        'permissions' => [
            ['name' => 'view payments', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'can approve payment', 'is_certified' => 1, 'is_host' => 0],
        ],
    ],

    'invoices' => [
        'permissions' => [
            ['name' => 'view invoices', 'is_certified' => 1, 'is_host' => 0],
        ],
    ],

    'upcoming billings' => [
        'permissions' => [
            ['name' => 'view billings', 'is_certified' => 1, 'is_host' => 0],
        ],
    ],

    'subscriptions' => [
        'permissions' => [
            ['name' => 'view subscriptions', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'create subscriptions', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'update subscriptions', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'delete subscriptions', 'is_certified' => 1, 'is_host' => 0],
        ],
    ],

    'appointments' => [
        'permissions' => [
            ['name' => 'view appointments', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'create appointments', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'update appointments', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'delete appointments', 'is_certified' => 1, 'is_host' => 0],
        ],
    ],

    'training & courses' => [
        'permissions' => [
            ['name' => 'view training & courses', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'create training & courses', 'is_certified' => 0, 'is_host' => 0],
            ['name' => 'update training & courses', 'is_certified' => 0, 'is_host' => 0],
            ['name' => 'delete training & courses', 'is_certified' => 0, 'is_host' => 0],
        ],
    ],

    'support tickets' => [
        'permissions' => [
            ['name' => 'view support tickets', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'create support tickets', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'update support tickets', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'delete support tickets', 'is_certified' => 1, 'is_host' => 0],
        ],
    ],

    'resources' => [
        'permissions' => [
            ['name' => 'view resources', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'create resources', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'update resources', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'delete resources', 'is_certified' => 0, 'is_host' => 1],
        ],
    ],

    'employees' => [
        'permissions' => [
            ['name' => 'view employees', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'create employees', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'update employees', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'delete employees', 'is_certified' => 1, 'is_host' => 0],
        ],
    ],

    'users' => [
        'permissions' => [
            ['name' => 'view users', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'create users', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'update users', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'delete users', 'is_certified' => 1, 'is_host' => 0],
        ],
    ],

    'borrower documents' => [
        'permissions' => [
            ['name' => 'view borrower documents', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'upload borrower documents', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'update borrower documents status', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'delete borrower documents', 'is_certified' => 1, 'is_host' => 0],
        ],
    ],

    'credit builder' => [
        'permissions' => [
            ['name' => 'view tier', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'update tier', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'view verification request', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'update verification request', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'view credit accounts', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'create credit accounts', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'update credit accounts', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'delete credit accounts', 'is_certified' => 0, 'is_host' => 1],
        ],
    ],

    'roles & permissions' => [
        'permissions' => [
            ['name' => 'view roles & permissions', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'create roles & permissions', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'update roles & permissions', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'delete roles & permissions', 'is_certified' => 1, 'is_host' => 0],
        ],
    ],

    'settings' => [
        'permissions' => [
            ['name' => 'update payment settings', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'update partner agreement settings', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'update borrower agreement settings', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'update email configuration settings', 'is_certified' => 0, 'is_host' => 0],
            ['name' => 'update branding settings', 'is_certified' => 0, 'is_host' => 0],
            ['name' => 'update credit profile settings', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'update score calculation settings', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'update microbilt settings', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'update openai settings', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'update domain configuration settings', 'is_certified' => 0, 'is_host' => 0],
        ],
    ],

    'funding' => [
        'permissions' => [
            ['name' => 'view funding', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'create funding', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'update funding', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'delete funding', 'is_certified' => 0, 'is_host' => 1],
        ],
    ],

    'lenders' => [
        'permissions' => [
            ['name' => 'view lenders', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'create lenders', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'update lenders', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'delete lenders', 'is_certified' => 0, 'is_host' => 1],
        ],
    ],

    'cms' => [
        'permissions' => [
            ['name' => 'view site settings', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'add soical media', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'update site settings', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'view blogs', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'create blogs', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'update blogs', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'view seo settings', 'is_certified' => 0, 'is_host' => 1],
            ['name' => 'update seo settings', 'is_certified' => 0, 'is_host' => 1],
        ],
    ],

    'enbedded loan application form' => [
        'permissions' => [
            ['name' => 'view configuration', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'update configuration', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'view application', 'is_certified' => 1, 'is_host' => 0],
            ['name' => 'convert application', 'is_certified' => 1, 'is_host' => 0],
        ],
    ],
];

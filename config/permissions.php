<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Permission List
    |--------------------------------------------------------------------------
    |
    | Define all system-wide permissions here.
    | These will be seeded for every business automatically.
    |
    */

    'default' => [
        'View.Dashboard',
    ],

    'Users' => [
        'List.Users',
        'Create.Users',
        'Edit.Users',
        'Delete.Users',
    ],

    'Sales' => [
        'List.Sales',
        'Create.Sales',
        'Edit.Sales',
        'Delete.Sales',
    ],

    'Reports' => [
        'View.Reports',
        'Export.Reports',
    ],

    'Settings' => [
        'Manage.Settings',
    ],
];

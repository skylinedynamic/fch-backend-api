<?php

return [
    'validation' => [
        'partner_name' => [
            'required' => 'Partner name is required.',
        ],
        'company_name' => [
            'required' => 'Company name is required.',
        ],
        'email' => [
            'required' => 'Email address is required.',
            'email' => 'Please provide a valid email address.',
        ],
        'phone_number' => [
            'required' => 'Phone number is required.',
        ],
        'website' => [
            'url' => 'Please provide a valid website URL.',
        ],
    ],

     /*
    |--------------------------------------------------------------------------
    | Success Messages
    |--------------------------------------------------------------------------
    */
    'success' => [
        'retrieved' => 'Partners retrieved successfully.',
        'created' => 'Partner created successfully.',
        'updated' => 'Partner updated successfully.',
        'deleted' => 'Partner deleted successfully.',
    ],
];
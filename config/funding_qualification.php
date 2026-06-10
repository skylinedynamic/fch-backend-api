<?php

return [
    'factor_keys' => [
        'Business Credit'  => 'business_credit',
        'Revenue'          => 'revenue',
        'Time in Business' => 'time_in_business',
        'Banking Activity' => 'banking_activity',
        'Existing Debt'      => 'existing_debt',
        'Documents'          => 'documents',
        // 'Allowed States'     => 'allowed_states',
        // 'Allowed Industries' => 'allowed_industries',
    ],

    'rules' => [
        'business_credit' => [
            'type'           => 'compare',
            'borrower_field' => 'borrowers.paydex_score',
            'funding_field'  => 'funding_sequencings.min_fico',
            'strong_offset'  => 20,
        ],
        'revenue' => [
            'type'           => 'compare',
            'borrower_field' => 'borrowers.monthly_revenue',
            'funding_field'  => 'funding_sequencings.min_monthly_revenue',
            'strong_offset'  => 0.01,
        ],
        'time_in_business' => [
            'type'           => 'date_compare',
            'borrower_field' => 'borrowers.business_start_date',
            'funding_field'  => 'funding_sequencings.min_tib_months',
            'strong_offset'  => 12,
        ],
        'banking_activity' => [
            'type' => '',
        ],
        'existing_debt' => [
            'type' => '',
        ],
        'documents' => [
            'type' => 'documents',
        ],
        'equipment' => [
            'type' => '',
        ],
        // 'allowed_states' => [
        //     'type'           => 'in_array',
        //     'borrower_field' => 'borrowers.state',
        //     'funding_field'  => 'funding_sequencings.allowed_states',
        // ],
        // 'allowed_industries' => [
        //     'type'           => 'in_array',
        //     'borrower_field' => 'borrowers.business_industry_type',
        //     'funding_field'  => 'funding_sequencings.allowed_industries',
        // ]
    ],

    'statuses' => [
        'strong'   => 'Strong',
        'good'     => 'Good',
        'not_good' => 'Not Good',
        'missing'  => 'Missing',
    ],

    'qualify_keys' => [
        'Revenue meets minimum threshold'             => 'revenue',
        'Business has been operating at least 1 year' => 'time_in_business',
        'Credit score meets minimum threshold'        => 'business_credit',
        'Equipment qualifies for financing'           => 'equipment',
    ]
];

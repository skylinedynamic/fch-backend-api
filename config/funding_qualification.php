<?php

use App\Models\Borrower\BorrowerDocType;

return [
    'factor_keys' => [
        'Business Credit'  => 'business_credit',
        'Revenue'          => 'revenue',
        'Time in Business' => 'time_in_business',
        'Banking Activity' => 'banking_activity',
        'Minimum Monthly Deposits' => 'min_monthly_deposits',
        'Minimum Average Daily Balance' => 'min_average_balance',
        'Max NSF Count' => 'max_nsf',
        'Max Negative Days' => 'max_negative_days',
        // 'Allowed States'     => 'allowed_states',
        // 'Allowed Industries' => 'allowed_industries',
    ],

    'rules' => [
        'business_credit' => [
            'type'           => 'compare',
            'borrower_field' => 'borrowers.fico_score',
            'funding_field'  => 'lender_visibilities.min_fico',
            'strong_offset'  => 20,
        ],
        'revenue' => [
            'type'           => 'compare',
            'borrower_field' => 'borrowers.monthly_revenue',
            'funding_field'  => 'lender_visibilities.min_monthly_revenue',
            'strong_offset'  => 0.01,
        ],
        'time_in_business' => [
            'type'           => 'date_compare',
            'borrower_field' => 'borrowers.business_start_date',
            'funding_field'  => 'lender_visibilities.min_tib_months',
            'strong_offset'  => 12,
        ],
        'banking_activity' => [
            'type'          => 'specific_document',
            'document_name' => BorrowerDocType::BANK_STATEMENTS,
        ],
        'min_monthly_deposits' => [
            'type'           => 'compare',
            'borrower_field' => 'borrowers.average_monthly_deposits',
            'funding_field'  => 'lender_visibilities.min_avg_deposits',
            'strong_offset'  => 0.01,
        ],
        'min_deposit_count' => [
            'type'           => 'compare',
            'borrower_field' => 'borrowers.monthly_deposit_count',
            'funding_field'  => 'lender_visibilities.min_deposit_count',
            'strong_offset'  => 1,
        ],
        'min_average_balance' => [
            'type'           => 'compare',
            'borrower_field' => 'borrowers.avg_bank_balance',
            'funding_field'  => 'lender_visibilities.min_average_daily_balance',
            'strong_offset'  => 0.01,
        ],
        'max_nsf' => [
            'type'           => 'compare',
            'borrower_field' => 'borrowers.nsf_count',
            'funding_field'  => 'lender_visibilities.max_nsf_count',
            'strong_offset'  => -1,
        ],
        'max_negative_days' => [
            'type'           => 'compare',
            'borrower_field' => 'borrowers.negative_days',
            'funding_field'  => 'lender_visibilities.max_negative_days',
            'strong_offset'  => -1,
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

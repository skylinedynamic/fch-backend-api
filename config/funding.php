<?php

return [
    'readiness_factors' => [
        'business_credit' => [
            'max_score' => 850,
            'strong_percentage' => 80,
            'good_percentage' => 60,
        ],
        'personal_credit' => [
            'strong_buffer' => 50,
        ],
        'revenue' => [
            'strong_multiplier' => 2.0,
        ],
        'time_in_business' => [
            'strong_buffer_months' => 12,
        ],
        'banking_activity' => [
            'strong_multiplier' => 2.0,
        ],
        'existing_debt' => [
            'strong_max_balance' => 1000,
            'good_max_balance' => 50000,
        ],
        'statuses' => [
            'strong' => 'Strong',
            'good' => 'Good',
            'missing' => 'Missing',
        ],
    ],
];

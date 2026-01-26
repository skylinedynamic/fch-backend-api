<?php

return [
    'types' => [
        'on_time_payment' => [
            'label' => 'OnTime Payment',
            'amount' => 2900,
        ],
        'six_month_payment' => [
            'label' => '6 Month Payment',
            'amount' => 500,
        ],
    ],
    'partner_plans' => [
        'Certified Partner' => [
            'one_time_cost' => 4997,
            'installment_cost' => 499.70,
            'installments_count' => 11,
            'platform_fee' => 299,
        ],
        'Executive Partner' => [
            'one_time_cost' => 6997,
            'installment_cost' => 699.70,
            'installments_count' => 11,
            'platform_fee' => 399,
        ],
        'White-Label Partner (WL)' => [
            'one_time_cost' => 6997,
            'installment_cost' => 699.70,
            'installments_count' => 11,
            'platform_fee' => 399,
        ],
    ],
];

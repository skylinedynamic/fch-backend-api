<?php

return [
    'entity_type_other' => 'Other',
    'industry_type_other' => 'Other',

    // A borrower's age on any client or profile form (App\Rules\AdultDateOfBirth).
    // A borrower signs funding agreements, so 18 is a hard floor; past 120 the
    // date is a typo, not a person.
    'date_of_birth' => [
        'min_age' => 18,
        'max_age' => 120,
    ],

    // Bounds on a client's Business Financials. The amount ceiling is the one
    // the borrower's own Financial Details already used; a margin goes negative
    // when the business runs at a loss.
    'financials' => [
        'amount_max' => 999999999.99,
        'profit_margin' => [
            'min' => -100,
            'max' => 100,
        ],
    ],
];

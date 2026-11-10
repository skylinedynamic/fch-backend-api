<?php

return [
    'entity_type_other' => 'Other',
    'industry_type_other' => 'Other',

    // A date of birth's age range (18 to 120) is config('app.minimum_age') /
    // config('app.maximum_age'), used by App\Rules\DateOfBirth everywhere.

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

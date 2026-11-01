<?php

// Plan & Fee > a plan's price - shown under each field (BUG-096).
return [
    'total_amount_required' => 'Enter the total amount.',
    'total_amount_numeric' => 'The total amount must be a number.',
    'total_amount_gt' => 'The total amount must be greater than 0.',
    'total_amount_max' => 'The total amount cannot be more than :max.',

    'platform_fee_numeric' => 'The platform fee must be a number.',
    'platform_fee_required' => 'Enter the platform fee.',
    'platform_fee_gt' => 'The platform fee must be greater than 0.',
    'platform_fee_max' => 'The platform fee cannot be more than :max.',

    'installments_integer' => 'The number of installments must be a whole number.',
    'installments_min' => 'Allow at least 1 installment.',

    'seats_integer' => 'The number of free users must be a whole number.',
    'seats_min' => 'The number of free users cannot be negative.',

    // The values passed the check before the confirmation (nothing saved yet).
    'values_valid' => 'The values are valid.',
];

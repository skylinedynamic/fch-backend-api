<?php

return [
    // ✅ Success Messages
    'retrieved' => 'Compensation retrieved successfully.',
    'saved'     => 'Compensation saved successfully.',

    // ✅ Failure Messages
    'retrieve_failed' => 'Failed to retrieve compensation.',
    'save_failed'     => 'Failed to save compensation.',
    'entity_not_found' => 'The record this compensation belongs to could not be found.',

    // ✅ Validation Messages
    'entity_type_required' => 'The compensation owner type is required.',
    'entity_type_invalid'  => 'The selected compensation owner type is invalid.',
    'entity_id_required'   => 'The compensation owner is required.',
    'paid_by_required'          => 'Please select how this employee is paid.',
    'paid_by_invalid'           => 'The selected payment method is invalid.',
    'compensations_required'    => 'Please provide the compensation details.',
    'service_required'          => 'Please select the applicable service.',
    'payout_type_prohibited'    => 'A payout type only applies to per deal compensation.',
    'amount_required'           => 'Please enter an amount.',
    'commission_percentage_max' => 'A commission percentage cannot be greater than 100.',
];

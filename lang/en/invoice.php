<?php

return [
    'list_retrieved' => 'Invoices retrieved successfully.',
    'details_retrieved' => 'Invoice details retrieved successfully.',
    'stats_retrieved' => 'Invoice statistics retrieved successfully.',
    'not_found' => 'Invoice not found.',
    'created' => 'Invoice created successfully.',
    'updated' => 'Invoice updated successfully.',
    'deleted' => 'Invoice deleted successfully.',
    'sent_success' => 'Invoice sent successfully.',
    'commission_payout' => 'Commission Payout',
    'downloaded_successfully' => 'Invoice downloaded successfully.',

    // BUG-049: the last installment takes up the rounding of the monthly amount.
    'final_installment' => [
        'description' => 'Installment :number of :count (final)',
        // :difference is "$0.02 less" or "$0.01 more".
        'less' => ':amount less',
        'more' => ':amount more',
        'detail' => 'This last payment is :difference than your usual :monthly, so your :count payments add up to exactly :total.',
        'mail_title' => 'This is your final installment',
        'mail_note' => 'This last payment is :difference than your usual :monthly, so your :count payments add up to exactly :total.',
    ],
];

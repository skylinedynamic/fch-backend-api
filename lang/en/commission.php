<?php

return [
    // Success Messages
    'retrieved' => 'Commissions retrieved successfully.',
    'details_retrieved' => 'Commission details retrieved successfully.',
    
    // Error Messages
    'not_found' => 'Commission not found.',
    'retrieval_failed' => 'Failed to retrieve commissions.',

    // Payout to the client's assigned user (host-partner clients)
    'payout_paid' => 'Payment made successfully.',
    'payout_not_host_client' => 'Only a commission on a client of the host partner has a payout to make.',
    'payout_already_paid' => 'This payout has already been paid.',
    'payout_not_allowed' => 'Only the host, or staff who manage commissions, can pay out a commission.',
    'payout_nothing_to_pay' => 'There is nothing to pay for this commission.',
    'payout_funding_not_closed' => 'The payout can be made once the funding is closed.',

    // Funding-application count tiers (Partner profile › Commissions)
    'tier' => [
        'min_count_required' => 'Enter where this tier starts.',
        'min_count_integer' => 'The start must be a whole number.',
        'min_count_min' => 'The start cannot be negative.',
        'max_count_integer' => 'The end must be a whole number.',
        'max_count_min' => 'The end cannot be negative.',
        'first_starts_at_zero' => 'The first tier must start at 0.',
        'starts_after_previous' => 'This tier must start at :count, right after the previous tier.',
        'only_last_open_ended' => 'Only the last tier can be left without an end.',
        'ends_before_start' => 'The end must be the same as or greater than the start.',
    ],

    // How a commission's dates are written (PHP date format)
    'date_format' => 'M d, Y',
    'list_date_format' => 'Y/m/d',
];

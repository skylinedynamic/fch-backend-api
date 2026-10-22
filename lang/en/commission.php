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
];

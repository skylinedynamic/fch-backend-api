<?php

return [
    // Update Status Request Validation Messages
    'id_required' => 'The application ID is required.',
    'id_integer' => 'The application ID must be an integer.',
    'id_exists' => 'The selected application does not exist.',

    'status_required' => 'The status is required.',
    'status_in' => 'The selected status is invalid.',

    'status_note_string' => 'The status note must be a string.',
    'status_note_max' => 'The status note may not be greater than 1000 characters.',

    // Service Messages
    'pre_qualification_retrieved' => 'Applications retrieved successfully.',
    'funding_application_retrieved' => 'Funding applications retrieved successfully.',
    'pre_qualification_not_found' => 'Pre-qualification application not found.',
    'funding_application_not_found' => 'Funding application not found.',
    'status_updated' => 'Status updated successfully.',
    
    // Controller Messages
    'error_retrieving_pre_qualification' => 'Failed to retrieve pre-qualification application.',
    'error_retrieving_funding_application' => 'Failed to retrieve funding application.',
    'error_updating_status' => 'Failed to update application status.',
];

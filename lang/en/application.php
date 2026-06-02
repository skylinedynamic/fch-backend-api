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
    
    // Commission Validation Messages
    'funding_application_id_required' => 'The funding application ID is required.',
    'funding_application_id_integer' => 'The funding application ID must be an integer.',
    'funding_application_id_exists' => 'The selected funding application does not exist.',
    'total_commission_required' => 'The success fee commission is required.',
    'total_commission_numeric' => 'The success fee commission must be a number.',
    'total_commission_min' => 'The success fee commission must be at least 1.',
    'total_commission_max' => 'The success fee commission may not be greater than 100.',
    'admin_commission_required' => 'The admin commission is required.',
    'admin_commission_numeric' => 'The admin commission must be a number.',
    'admin_commission_min' => 'The admin commission must be at least 1.',
    'admin_commission_max' => 'The admin commission may not be greater than 100.',
    'partner_commission_numeric' => 'The partner commission must be a number.',
    'partner_commission_min' => 'The partner commission must be at least 0.',
    'partner_commission_max' => 'The partner commission may not be greater than 100.',
    'requested_amount_numeric' => 'The requested amount must be a number.',
    'requested_amount_min' => 'The requested amount must be at least 1.',
    
    // Commission Service Messages
    'commission_saved_successfully' => 'Commission saved successfully.',
    'error_saving_commission' => 'Failed to save commission.',
    'commission_already_paid' => 'Commission already paid.',
    'commission_payout_processed_successfully' => 'Commission payout processed successfully.',
    'error_processing_payout' => 'Failed to process commission payout.',
];

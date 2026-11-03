<?php

return [
    // Update Status Request Validation Messages
    'id_required' => 'The application ID is required.',
    'id_integer' => 'The application ID must be an integer.',
    'id_exists' => 'The selected application does not exist.',

    'status_required' => 'The status is required.',
    'status_in' => 'The selected status is invalid.',

    'status_note_string' => 'The status note must be a string.',
    'status_note_max' => 'The status note may not be greater than :max characters.',

    // Service Messages
    'pre_qualification_retrieved' => 'Applications retrieved successfully.',
    'funding_application_retrieved' => 'Funding applications retrieved successfully.',
    'pre_qualification_not_found' => 'Pre-qualification application not found.',
    'pre_qualification_ai_started' => 'Pre-qualification AI evaluation started. The result will appear when it completes.',
    'pre_qualification_ai_already_running' => 'An AI evaluation is already running for this application. Please wait for it to finish.',
    'funding_application_not_found' => 'Funding application not found.',
    'download_password_in_body' => 'Send the download password in the request body, not in the URL.',
    'status_updated' => 'Status updated successfully.',

    // Activity log of a funding application's status change
    'activity_note_updated_title' => 'Funding Application Note Updated',
    'activity_note_updated_description' => 'Status note was updated.',
    'activity_status_changed_title' => 'Funding Application Status Changed: :from → :to',
    'activity_status_changed_description' => 'Status updated to: :to',
    'activity_status_note' => 'Note: :note',
    'audit_history_retrieved' => 'Audit history retrieved successfully.',
    
    // Controller Messages
    'error_retrieving_pre_qualification' => 'Failed to retrieve pre-qualification application.',
    'error_retrieving_funding_application' => 'Failed to retrieve funding application.',
    'error_updating_status' => 'Failed to update application status.',
    'error_retrieving_audit_history' => 'Failed to retrieve audit history.',
    
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

    // Funding Application edit lock & re-signature
    'funding_application_locked' => 'This application can no longer be edited because it is ":status". Contact your funding advisor if something needs to change.',
    // Host, white-label and certified partner users: they are the advisors.
    'funding_application_locked_staff' => 'This funding application is ":status", so it can no longer be edited. Funding applications can only be edited while their status is one of the editable statuses.',
    'funding_application_requires_resign' => 'Your changes were saved, but they replaced information you had already signed for. Please review and sign the application again to put it back into review.',
    'funding_application_partner_edit_requires_resign' => 'Your changes were saved. Because the borrower had already signed this application, their signature has been cleared and they must sign again before it returns to review.',
];

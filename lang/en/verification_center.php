<?php

return [
    'success_retrieved' => 'Verification requests retrieved successfully.',
    'error_fetch_failed' => 'Failed to fetch verification requests.',
    'success_status_updated' => 'Verification status updated successfully.',
    'error_status_update_failed' => 'Failed to update verification status.',

    'request_ids_required' => 'At least one request ID is required.',
    'request_ids_array' => 'The request ID must be an array.',
    'request_ids_invalid' => 'One or more of the selected request IDs are invalid.',
    'status_required' => 'The status field is required.',
    'status_invalid' => 'The selected status is invalid.',
    'note_required' => 'A note is required when status is rejected.',

    'already_verified' => 'Already Verified',
    'already_rejected' => 'Already Rejected',
    'not_verified' => 'Not Verified',
    'no_api_assigned' => 'No Verification Api Assigned',
    'verified' => 'Verified',
    'verification_failed' => 'Verification failed.',
    'no_matching_business_record' => 'No matching business record found.',
    'verification_status_update' => 'Verification Status Update',

    // Notifications. A submission is announced to the host; a decision goes back
    // to the borrower only.
    'new_request_subject' => 'New Verification Request',
    'unknown_task' => 'Unknown Task',

    // Stand-ins for an incomplete record, so a missing name never renders blank.
    'default_borrower_name' => 'Borrower',
    'unknown_tier' => '—',

    // ":count task" / ":count tasks" - via trans_choice, so the plural rule
    // belongs to the translation rather than to a ternary in PHP.
    'task_count' => '{1}:count task|[2,*]:count tasks',

    // Logs. Notification failures are logged and swallowed: a mail problem must
    // never surface to the borrower submitting, or to the reviewer deciding.
    'log_host_email_missing' => 'Verification request not announced: the host partner has no email address.',
    'log_request_notify_failed' => 'Failed to announce a credit builder verification request.',
    'log_borrower_notify_failed' => 'Failed to send the verification status email to the borrower.',
    'rejected' => 'Rejected',
    'processing' => 'Processing',
    'pending' => 'Pending',
    'tasks_updated_successfully' => 'Tasks updated successfully.',
    'tier_task_updated' => 'Tier Task Has been Updated',
    'tasks_processed_desc' => 'The following tasks for :tier have been processed:',
    'note_label' => 'Note',
];

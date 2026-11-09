<?php

return [
    'validation_failed' => 'Validation failed',
    'current_password_required' => 'Current password is required.',
    'current_password_incorrect' => 'Current password does not match our records.',
    'new_password_required' => 'New password is required.',
    'new_password_min' => 'New password must be at least 8 characters long.',
    'new_password_mixed' => 'New password must include both uppercase and lowercase letters.',
    'new_password_letters' => 'New password must contain both uppercase and lowercase letters.',
    'new_password_numbers' => 'New password must include at least one number.',
    'new_password_symbols' => 'New password must include at least one special character.',
    'new_password_confirmed' => 'New password confirmation does not match.',
    'new_password_different' => 'New password must be different from the old password.',
    'password_update_success' => 'Password updated successfully.',
    'change_request_submitted' => 'Your change request has been submitted for review.',
    'change_request_failed' => 'Failed to submit change request. Please try again.',
    'change_request_list_success' => 'Change requests retrieved successfully.',
    'change_request_status_updated' => 'Change request status updated successfully.',
    'change_request_cancelled' => 'Your change request has been cancelled.',
    'change_request_not_found' => 'Change request not found.',
    'change_request_pending_list' => 'Fields waiting for review.',
    'change_request_same_value' => 'This is already the value on your profile.',
    'change_request_already_pending' => 'You already have a change request for this field waiting for review.',
    'change_request_already_processed' => 'This change request has already been processed and cannot be modified.',
    'change_request_nothing_to_reveal' => 'Only an SSN or EIN change is masked; this one has nothing to reveal.',

    // Notifications. A submission is announced to the host; a decision goes back
    // to the borrower only. See App\Services\Profile\ChangeRequestNotifier.
    'change_request_status_subject' => 'Profile Change Request Update',
    'default_borrower_name' => 'Borrower',

    // Logs. Notification failures are logged and swallowed: the change request is
    // already saved, so a mail problem must not surface to the borrower or the
    // reviewer.
    'log_change_request_host_email_missing' => 'Change request not announced: the host partner has no email address.',
    'log_change_request_notify_failed' => 'Failed to announce a profile change request to the host partner.',
    'log_change_request_borrower_email_missing' => 'Change request decision not sent: the borrower has no email address.',
    'log_change_request_status_notify_failed' => 'Failed to send the change request status email to the borrower.',
    'borrower_not_found' => 'Borrower record not found.',
    'profile_update_success' => 'Profile updated successfully.',
    'profile_picture_update_success' => 'Profile picture updated successfully.',
    'password_changed_subject' => 'Security Alert: Your Password Was Changed',
    'greeting' => 'Hello :name!',
    'default_user' => 'User',
    'password_changed_line1' => 'This is a confirmation that your platform password was recently changed.',
    'password_changed_line2' => 'If you made this change, no further action is needed.',
    'password_changed_line3' => 'If you did not make this change, please reset your password immediately or contact support.'
];

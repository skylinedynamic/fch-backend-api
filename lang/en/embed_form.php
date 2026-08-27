<?php

return [
    // Configuration & Keys
    'config_updated' => 'Embed form configuration updated.',
    'key_regenerated' => 'Embed key regenerated successfully.',
    'form_not_found_or_inactive' => 'Form not found or is no longer active.',

    // Submissions
    'submission_not_found' => 'Submission not found.',
    'status_updated' => 'Status updated successfully.',
    'submission_received' => 'Submission received.',
    'submission_success' => 'Your application has been submitted successfully. We will be in touch soon!',

    // Conversion
    'converted_to_borrower' => 'Submission successfully converted to Borrower.',
    'conversion_failed' => 'Conversion failed.',
    'email_required_conversion' => 'Email is required for conversion.',
    'borrower_exists_email' => 'A borrower with this email already exists.',

    // Attachments & Downloads
    'file_not_found' => 'File not found.',
    'file_not_exists' => 'File no longer exists on server.',
    'unauthorized_attachment' => 'You do not have permission to access this attachment.',

    // Email & Notifications
    'new_submission_mail_subject' => 'New Loan Application — :name',

    // Validation
    'first_name_required' => 'First name is required.',
    'last_name_required' => 'Last name is required.',
    'email_required' => 'Email address is required.',
    'email_invalid' => 'Please enter a valid email address.',
    'ssn_format' => 'SSN must be in the format XXX-XX-XXXX.',
    'credit_score_min' => 'Credit score must be at least 300.',
    'credit_score_max' => 'Credit score cannot exceed 850.',
    'honeypot_triggered' => 'Submission rejected.',
    'paystub_mimes' => 'Paystub must be a PDF, Word, Excel, or image file.',
    'paystub_max' => 'Each paystub file may not exceed 10 MB.',
    'credit_report_mimes' => 'Credit report must be a PDF, Word, Excel, or image file.',
    'credit_report_max' => 'Each credit report file may not exceed 10 MB.',

    // Logs
    'log_store_failed' => 'EmbedFormService::storeSubmission failed',
    'log_notification_failed' => 'Failed to send embed form notification email',
    'log_conversion_failed' => 'Embed form submission to borrower conversion failed',
];

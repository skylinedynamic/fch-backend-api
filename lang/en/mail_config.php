<?php

return [
    'retrieved' => 'Mail configurations retrieved successfully.',
    'saved' => 'Mail configuration saved. Verify it to start sending with it.',
    'deleted' => 'Mail configuration removed.',
    'default_set' => 'Default mail configuration updated.',
    'not_verified_for_default' => 'Only a verified mail configuration can be the default.',
    'no_partner' => 'Your account is not linked to a partner.',
    'fallback_forbidden' => 'Only the host partner can manage the fallback mail configuration.',

    // Verification
    'code_sent' => 'A verification code has been sent to :email.',
    'code_not_sent' => 'The verification code could not be sent with these settings. Please check them and try again.',
    'code_wait' => 'Please wait a minute before asking for another code.',
    'code_invalid' => 'The code is incorrect or has expired. Please request a new one.',
    'not_complete' => 'Fill in every field for this provider before verifying it.',
    'verified' => 'Mail configuration verified.',
    'logs' => [
        'code_not_sent' => 'Mail configuration verification code could not be sent.',
    ],

    'verification_mail' => [
        'subject' => 'Your mail configuration verification code',
        'greeting' => 'Hello :name,',
        'line' => 'Use this code to verify the :provider mail settings that send from :address.',
        'expiry' => 'The code expires in :minutes minutes. If you did not ask for it, you can ignore this email.',
    ],

    // Validation
    'provider_invalid' => 'Choose a supported mail provider.',
    'from_address_required' => 'The from email address is required.',
    'from_address_email' => 'The from address must be a valid email address.',
    'bcc_emails_invalid' => 'One or more BCC email addresses are invalid.',
    'field_required' => 'The :field is required.',
    'code_required' => 'Enter the verification code.',
];

<?php

return [
    'retrieved' => 'Email configurations retrieved successfully.',
    'saved' => 'Email configuration saved successfully.',
    'status_updated' => 'Email configuration status updated successfully.',
    'not_found' => 'Email configuration not found.',

    // Validation Messages
    'provider_required'         => 'Email provider is required.',
    'from_address_required'     => 'The from email address is required.',
    'from_address_email'        => 'The from address must be a valid email address.',
    'from_name_required'        => 'The from name is required.',
    'from_name_max'             => 'The from name must not exceed 255 characters.',

    // SMTP
    'smtp_host_required_if'      => 'The SMTP host is required.',
    'smtp_port_required_if'      => 'The SMTP port is required.',
    'smtp_encryption_required_if' => 'The SMTP encryption is required.',
    'smtp_encryption_in'         => 'The encryption must be one of: tls, ssl, none.',
    'smtp_username_required_if'  => 'The SMTP username is required.',
    'smtp_password_required_if'  => 'The SMTP password is required.',

    // AWS SES
    'aws_access_key_required_if' => 'The AWS access key is required.',
    'aws_secret_key_required_if' => 'The AWS secret key is required.',
    'aws_region_required_if'     => 'The AWS region is required.',

    // BCC
    'bcc_emails_label'          => 'BCC Emails',
    'bcc_emails_placeholder'    => 'e.g. bcc1@example.com, bcc2@example.com',
    'bcc_emails_help'           => 'Enter multiple email addresses separated by commas.',
    'bcc_emails_invalid'        => 'One or more BCC email addresses are invalid.',

    // Relations
    'partner_id_exists'         => 'The selected partner does not exist.',
    'id_exists'                 => 'The selected email configuration does not exist.',
];

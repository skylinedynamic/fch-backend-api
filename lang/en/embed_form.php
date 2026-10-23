<?php

return [
    // Configuration & Keys
    'config_updated' => 'Embed form configuration updated.',
    'key_regenerated' => 'Embed key regenerated successfully.',
    'form_not_found_or_inactive' => 'Form not found or is no longer active.',
    'origin_not_allowed' => 'This form is not authorised to be submitted from this website.',

    // Publishing address. A White-Label partner's form is served on a domain of
    // its own and nowhere else, so without one there is no address to publish
    // and no form to create.
    'partner_domain_required' => 'Set a domain for your account before creating an embedded form. Your application form is published on that domain, so there is nowhere to host it until one is configured.',

    // The host and Certified partners publish on the platform's public site, so
    // a blank BASE_WEBSITE leaves them with no address either. Reported the same
    // way as a missing partner domain rather than silently producing no link.
    'base_website_required' => 'The platform website address is not configured, so there is nowhere to publish this form. Set BASE_WEBSITE on the server before creating an embedded form.',

    // Submissions
    'submission_not_found' => 'Submission not found.',
    'status_updated' => 'Status updated successfully.',
    'status_invalid' => 'That is not a valid submission status.',
    'status_locked_converted' => 'A converted application cannot change status.',
    'date_range_invalid' => 'The end date must not be before the start date.',
    'submission_received' => 'Submission received.',
    'submission_success' => 'Your application has been submitted successfully. We will be in touch soon!',

    // Conversion
    'converted_to_borrower' => 'Submission successfully converted to a Free Plan Borrower.',
    'conversion_failed' => 'Conversion failed.',
    'already_converted' => 'This application has already been converted.',
    'email_required_conversion' => 'Email is required for conversion.',
    'borrower_exists_email' => 'A borrower with this email already exists.',
    'user_exists_email' => 'A user account with this email already exists.',
    'email_belongs_to_staff' => 'This email already belongs to a :type account and cannot be converted to a borrower.',
    'existing_borrower_confirmation_required' => 'A borrower with this email already exists. Confirm to create the funding application under that borrower.',
    'borrower_other_partner' => 'A borrower with this email already exists under a different partner, so this submission cannot be converted.',
    'converted_to_existing_borrower' => 'Funding application created for the existing borrower.',
    'step_valid' => 'Step is valid.',
    'log_funding_application_failed' => 'Failed to create a funding application from embed form submission',

    // Attachments & Downloads
    'file_not_found' => 'File not found.',
    'file_not_exists' => 'File no longer exists on server.',
    'unauthorized_attachment' => 'You do not have permission to access this attachment.',

    // Email & Notifications
    'new_submission_mail_subject' => 'New Embedded Form Application — :name',
    'converted_mail_subject' => 'Your funding application is in review — :site',
    'converted_staff_mail_subject' => 'Applicant converted to a client — :name',

    // Validation
    'first_name_required' => 'First name is required.',
    'last_name_required' => 'Last name is required.',
    'email_required' => 'Email address is required.',
    'email_invalid' => 'Please enter a valid email address.',
    'email_already_registered' => 'This email address is already registered in our system.',
    'email_registered_with_other_partner' => 'This email already exists.',
    'ssn_format' => 'SSN must be 9 digits, in the format XXX-XX-XXXX.',
    'currency_invalid' => 'Select the currency of the requested amount: USD, EUR or GBP.',
    'additional_funding_type_invalid' => 'Select additional funding types from the list, each once.',
    'additional_funding_type_preferred' => 'The preferred funding type cannot also be an additional funding type.',
    'honeypot_triggered' => 'Submission rejected.',
    'phone_required' => 'A phone number is required.',
    'phone_invalid' => 'Enter a valid 10-digit US phone number, e.g. (555) 234-5678.',
    'minimum_age' => 'Applicants must be at least :age years old.',
    'credit_score_required' => 'An estimated credit score is required.',
    'sales_rep_invalid' => 'Select a sales representative from the list.',
    'referral_source_invalid' => 'Select a referral source from the list.',
    'referral_source_other_required' => 'Please tell us where you heard about us.',

    // Business information
    'full_name_required' => 'Business contact name is required.',
    'business_name_required' => 'Legal business name is required.',

    // Ownership
    'ownership_max' => 'Ownership percentage cannot exceed 100%.',

    // Financial
    'monthly_revenue_required' => 'Average monthly revenue is required.',
    'debt_balance_required' => 'Please enter the outstanding balance of your existing business debt.',

    // Funding request
    'requested_amount_required' => 'Requested funding amount is required.',

    // Disclosures, compliance and attestation
    'credit_pull_required' => 'You must authorize a credit pull to continue.',
    'terms_required' => 'You must accept the terms and conditions to continue.',
    'explanation_required' => 'Please explain the disclosure(s) you answered Yes to.',
    'signature_required' => 'Your full legal name is required as an electronic signature.',
    'signature_min' => 'Please type your full legal name.',
    'esign_required' => 'You must consent to using an electronic signature.',
    'legal_binding_required' => 'You must acknowledge that this submission is legally binding.',

    // Documents
    'document_type_invalid' => 'One or more files were uploaded under an unknown document type.',
    'document_mimes' => 'Documents must be PDF, Word, Excel, CSV, or image files.',
    'document_max' => 'Each file may not exceed 10 MB.',

    'credit_score_min' => 'Credit score must be at least 300.',
    'credit_score_max' => 'Credit score cannot exceed 850.',

    // Deprecated: kept so historic validation messages still resolve.
    'paystub_mimes' => 'Paystub must be a PDF, Word, Excel, or image file.',
    'paystub_max' => 'Each paystub file may not exceed 10 MB.',
    'credit_report_mimes' => 'Credit report must be a PDF, Word, Excel, or image file.',
    'credit_report_max' => 'Each credit report file may not exceed 10 MB.',

    // Logs
    'log_store_failed' => 'EmbedFormService::storeSubmission failed',
    'log_notification_failed' => 'Failed to send embed form notification email',
    'log_conversion_failed' => 'Embed form submission to free trial borrower conversion failed',
];

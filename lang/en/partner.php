<?php

return [
    'validation' => [
        'partner_name' => [
            'required' => 'Partner name is required.',
        ],
        'full_name' => [
            'required' => 'Full name is required.',
        ],
        'company_name' => [
            'required' => 'Company name is required.',
        ],
        'email' => [
            'required' => 'Email address is required.',
            'email' => 'Please provide a valid email address.',
            'unique' => 'The email has already been taken by another user.',
        ],
        'phone_number' => [
            'required' => 'Phone number is required.',
        ],
        'state' => [
            'required' => 'State is required.',
        ],
        'city' => [
            'required' => 'City is required.',
        ],
        'country' => [
            'required' => 'Country is required.',
        ],
        'address_line_1' => [
            'required' => 'Address is required.',
        ],
        'website' => [
            'url' => 'Please provide a valid website URL.',
        ],
        'partner_id' => [
            'required' => 'Partner ID is required.',
            'exists' => 'Selected partner does not exist.',
        ],
        'partner_type' => [
            'required' => 'Partner type is required.',
            'string' => 'Partner type must be a string.',
            'max' => 'Partner type must not exceed 255 characters.',
        ],
        'enrollment_type' => [
            'string' => 'Enrollment type must be a string.',
            'max' => 'Enrollment type must not exceed 255 characters.',
        ],
        'monthly_platform_fee' => [
            'required' => 'Monthly platform fee is required.',
            'numeric' => 'Monthly platform fee must be a number.',
            'min' => 'Monthly platform fee must be greater than or equal to 0.',
            'gt' => 'Monthly platform fee must be greater than 0.',
        ],
        'document_file' => [
            'required' => 'Document file is required.',
            'max' => 'Document file size must not exceed 50MB.',
        ],
        'partner_url' => [
            'string' => 'Partner URL must be a string.',
            'max' => 'Partner URL must not exceed 255 characters.',
        ],
        'partner_domain' => [
            'required' => 'Sub Domain is required.',
            'string' => 'Sub Domain must be a string.',
            'min' => 'Sub Domain must be at least 4 characters.',
            'max' => 'Sub Domain must not exceed 50 characters.',
            'regex' => 'Sub Domain must start with a letter and only contain letters, numbers, and hyphens, and cannot end with a hyphen.',
            'unique' => 'This subdomain is already taken. Please choose a different one.',
        ],
        'borrower_domain' => [
            'unique' => 'This borrower domain is already taken. Please choose a different one.',
        ],
        'first_reg_date' => [
            'date' => 'First registration date must be a valid date.',
        ],
        'first_reg_amount' => [
            'numeric' => 'First registration amount must be a number.',
            'min' => 'First registration amount must be greater than or equal to 0.',
        ],
        'installment_type' => [
            'numeric' => 'Installment type must be a number.',
            'min' => 'Installment type must be greater than or equal to 0.',
        ],
        'first_reg_payment' => [
            'numeric' => 'First registration payment must be a number.',
            'min' => 'First registration payment must be greater than or equal to 0.',
        ],
        'first_reg_payment_2' => [
            'numeric' => 'Second registration payment must be a number.',
            'min' => 'Second registration payment must be greater than or equal to 0.',
        ],
        'total_paid_amount' => [
            'numeric' => 'Total paid amount must be a number.',
            'min' => 'Total paid amount must be greater than or equal to 0.',
        ],
        'subscription_start_date' => [
            'date' => 'Subscription start date must be a valid date.',
        ],
        'subscription_end_date' => [
            'date' => 'Subscription end date must be a valid date.',
            'after_or_equal' => 'Subscription end date must be on or after the subscription start date.',
        ],
        'expiry_date' => [
            'date' => 'Expiry date must be a valid date.',
        ],
        'termination_date' => [
            'date' => 'Termination date must be a valid date.',
        ],
        'status' => [
            'string' => 'Status must be a string.',
            'in' => 'Status must be one of: active, inactive, suspended, or expired.',
        ],
        'enrollment_type' => [
            'required' => 'Enrollment type is required.',
            'string' => 'Enrollment type must be a string.',
            'max' => 'Enrollment type must not exceed 255 characters.',
        ],
        'enrollment_amount' => [
            'required' => 'Enrollment amount is required.',
            'numeric' => 'Enrollment amount must be a number.',
            'min' => 'Enrollment amount must be greater than or equal to 0.',
            'gt' => 'Enrollment amount must be greater than 0.',
        ],
        'enrollment_type_required' => 'Enrollment type is required.',
        'enrollment_amount_required' => 'Enrollment amount is required.',
        'enrollment_amount_numeric' => 'Enrollment amount must be a number.',
        'enrollment_amount_gt' => 'Enrollment amount must be greater than 0.',
        'enrollment_amount_regex' => 'Enrollment amount must be a number greater than 0 and cannot be -0.',
        'monthly_platform_fee_required' => 'Monthly platform fee is required.',
        'monthly_platform_fee_numeric' => 'Monthly platform fee must be a number.',
        'monthly_platform_fee_gt' => 'Monthly platform fee must be greater than 0.',
        'monthly_platform_fee_regex' => 'Monthly platform fee must be a number greater than 0 and cannot be -0.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Success Messages
    |--------------------------------------------------------------------------
    */
    'success' => [
        'retrieved' => 'Partners retrieved successfully.',
        'created' => 'Partner created successfully.',
        'updated' => 'Partner updated successfully.',
        'deleted' => 'Partner deleted successfully.',
        'archived' => 'Partner(s) archived successfully.',
        'unarchived' => 'Partner(s) restored to the active list.',
        'document_uploaded' => 'Document uploaded successfully.',
        'document_updated' => 'Document updated successfully.',
        'documents_retrieved' => 'Documents retrieved successfully.',
        'document_deleted' => 'Document deleted successfully.',
        'tenant_config_retrieved' => 'Tenant configuration retrieved successfully.',
        'tenant_config_updated' => 'Tenant configuration updated successfully.',
    ],

    'error' => [
        'not_found' => 'Partner not found.',
        'list_failed' => 'Failed to retrieve partners.',
        'archive_failed' => 'Could not archive the selected partner(s). Please try again.',
        'unarchive_failed' => 'Could not restore the selected partner(s). Please try again.',
        'delete_failed' => 'Could not delete the selected partner(s). Please try again.',
        'tenant_config_update_failed' => 'Could not update the tenant configuration. Please try again.',
        'deletable_check_failed' => 'Could not check whether these partners can be deleted.',
    ],

    'log' => [
        'partner_create_failed' => 'Partner creation failed',
        'partner_update_failed' => 'Partner update failed',
        'trial_welcome_mail_failed' => 'Partner trial welcome email failed',
        'account_update_mail_failed' => 'Partner account update email failed',
        'trial_expiry_mail_failed' => 'Partner trial expiry date update email failed',
        'account_create_notification_failed' => 'Account creation notification email failed',
    ],

    'partner_stats_retrieved' => 'Partner statistics retrieved successfully.',
    'partner_stats_retrieved_failed' => 'Failed to retrieve partner statistics.',
    'borrowers_retrieved_data' => 'Borrowers retrieved successfully.',
    'borrowers_fetch_failed' => 'Failed to fetch borrowers.',
    'next_id_retrieved' => 'Next partner ID retrieved successfully.',
    'next_id_failed' => 'Failed to retrieve next partner ID.',

    'subscription_saved' => 'Subscription saved successfully.',
    'subscription_save_failed' => 'Failed to save subscription.',
    'subscription_list' => 'Subscription list retrieved successfully.',
    'subscription_list_failed' => 'Failed to retrieve subscription list.',
    'partner_delete_failed' => 'Failed to delete partner.',
    'subscription_not_found' => 'Subscription not found.',
    'subscription_fetch_failed' => 'Failed to fetch subscription details.',
    'subscription_cancelled' => 'Subscription cancelled successfully.',
    'subscription_already_cancelled' => 'Subscription is already cancelled.',
    'borrower_fee_retrieved' => 'Borrower fee retrieved successfully.',
    'subscription_list_retrieved' => 'Subscription list retrieved successfully.',
    'subscription_details' => 'Subscription details retrieved successfully.',
    'partner_not_found' => 'Partner not found.',
    'my_subscription_retrieved' => 'Subscription details retrieved successfully.',
    'my_subscription_failed' => 'Failed to retrieve subscription details',
    'my_subscription_non_host_only' => 'Subscription details are available to partner accounts only.',
    'provider_name_required' => 'The payment provider is required.',
    'default_payment_method_updated' => 'Default payment method updated successfully.',
    'payment_method_update_failed' => 'The card could not be saved: :error',

    'free_trial_created_log' => 'Partner account created on a <span class="text-danger fw-semibold">Free Trial</span> plan. Trial end date: <strong>:date</strong>.',
    'free_trial_expiry_updated_log' => 'Partner free trial expiration date updated to <strong>:date</strong>.',
    'free_trial_converted_log' => 'Converted from Free Trial to :enrollment_type',
    'default_enrollment_type' => 'Paid Enrollment',
    'trial_end_date' => 'Trial End Date',
    'assigned_user' => 'Assigned User',
    'field_changed' => ':field was changed',
    'field_updated' => ':field was updated to :value',
    'empty_value' => 'empty',

    'mail' => [
        'subject_account_created_enrollment' => 'Welcome to :company - Your Partner Account Details & Enrollment',
        'subject_account_created_trial' => 'Welcome to :company - Partner Portal Free Trial Access',
        'subject_account_migrated' => 'Welcome to :company - Partner Account Migrated from Free Trial',
        'subject_trial_expiry_updated' => ':company - Partner Portal Free Trial Expiry Date Updated',
    ],
];

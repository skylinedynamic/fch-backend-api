<?php

return [
    'validation' => [
        'partner_name' => [
            'required' => 'Partner name is required.',
        ],
        'company_name' => [
            'required' => 'Company name is required.',
        ],
        'email' => [
            'required' => 'Email address is required.',
            'email' => 'Please provide a valid email address.',
        ],
        'phone_number' => [
            'required' => 'Phone number is required.',
        ],
        'website' => [
            'url' => 'Please provide a valid website URL.',
        ],
        'partner_id' => [
            'required' => 'Partner ID is required.',
            'exists' => 'Selected partner does not exist.',
        ],
        'partner_type' => [
            'string' => 'Partner type must be a string.',
            'max' => 'Partner type must not exceed 255 characters.',
        ],
        'enrollment_type' => [
            'string' => 'Enrollment type must be a string.',
            'max' => 'Enrollment type must not exceed 255 characters.',
        ],
        'monthly_platform_fee' => [
            'numeric' => 'Monthly platform fee must be a number.',
            'min' => 'Monthly platform fee must be greater than or equal to 0.',
        ],
        'document_file' => [
            'required' => 'Document file is required.',
            'max' => 'Document file size must not exceed 50MB.',
        ],
        'partner_url' => [
            'string' => 'Partner URL must be a string.',
            'max' => 'Partner URL must not exceed 255 characters.',
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
        'document_uploaded' => 'Document uploaded successfully.',
        'document_updated' => 'Document updated successfully.',
        'documents_retrieved' => 'Documents retrieved successfully.',
        'document_deleted' => 'Document deleted successfully.',
    ],

    'partner_stats_retrieved' => 'Partner statistics retrieved successfully.',
    'partner_stats_retrieved_failed' => 'Failed to retrieve partner statistics.',
    'borrowers_retrieved_data' => 'Borrowers retrieved successfully.',

    'subscription_saved' => 'Subscription saved successfully.',
    'subscription_save_failed' => 'Failed to save subscription.',
    'subscription_list' => 'Subscription list retrieved successfully.',
    'subscription_list_failed' => 'Failed to retrieve subscription list.',
    'partner_delete_failed' => 'Failed to delete partner.',
    'subscription_details' => 'Subscription details retrieved successfully.',
    'subscription_not_found' => 'Subscription not found.',
    'subscription_fetch_failed' => 'Failed to fetch subscription details.',

];

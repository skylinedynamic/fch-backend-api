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
        'document_file' => [
            'required' => 'Document file is required.',
            'max' => 'Document file size must not exceed 50MB.',
        ],
        'partner_url' => [
            'string' => 'Partner URL must be a string.',
        ],
        'billing_term' => [
            'string' => 'Billing term must be a string.',
            'in' => 'Billing term must be either monthly or annual.',
        ],
        'first_reg_date' => [
            'date' => 'First registration date must be a valid date.',
        ],
        'first_reg_amount' => [
            'numeric' => 'First registration amount must be a number.',
        ],
        'first_payment_amount' => [
            'numeric' => 'First payment amount must be a number.',
        ],
        'first_reg_amount_2' => [
            'numeric' => 'Second registration amount must be a number.',
        ],
        'recurring_amount' => [
            'numeric' => 'Recurring amount must be a number.',
        ],
        'total_amount' => [
            'numeric' => 'Total amount must be a number.',
        ],
        'subscription_start_date' => [
            'date' => 'Subscription start date must be a valid date.',
        ],
        'subscription_end_date' => [
            'date' => 'Subscription end date must be a valid date.',
        ],
        'expiry_date' => [
            'date' => 'Expiry date must be a valid date.',
        ],
        'termination_date' => [
            'date' => 'Termination date must be a valid date.',
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
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
    ],
];
<?php

return [
    'fetched' => 'Resources fetched successfully.',
    'created' => 'Resource created successfully.',
    'updated' => 'Resource updated successfully.',
    'deleted' => 'Resource deleted successfully.',
    'not_found' => 'Resource not found.',

    // Validation Attributes
    'attributes' => [
        'resource_type' => 'Resource Type',
        'title' => 'Resource Title',
        'subtitle' => 'Description Subtitle',
        'status' => 'Status',
        'icon' => 'Icon Class',
        'file' => 'Upload File Asset',
        'link' => 'Resource Link',
        'is_partner' => 'Partner Availability',
        'is_borrower' => 'Borrower Availability',
    ],

    // Validation Messages
    'validation' => [
        'resource_type_required' => 'The Resource Type is required.',
        'resource_type_invalid' => 'The selected Resource Type is invalid.',
        'title_required' => 'The Resource Title is required.',
        'file_required' => 'The Upload File is required.',
        'file_pdf' => 'The Upload File must be a PDF file.',
        'link_required' => 'The Resource Link is required.',
        'link_invalid' => 'The Resource Link must be a valid URL.',
        'is_borrower_boolean' => 'The Borrower Availability field must be true or false.',
        'is_partner_boolean' => 'The Partner Availability field must be true or false.',
    ],
];

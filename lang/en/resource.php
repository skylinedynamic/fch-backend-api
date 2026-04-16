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
    ],

    // Validation Messages
    'validation' => [
        'resource_type_required' => 'The Resource Type is required.',
        'resource_type_invalid' => 'The selected Resource Type is invalid.',
        'title_required' => 'The Resource Title is required.',
        'file_required' => 'The Upload File Asset is required for this resource type.',
        'file_pdf' => 'The Upload File Asset must be a PDF file.',
        'link_required' => 'The Resource Link is required for this resource type.',
        'link_invalid' => 'The Resource Link must be a valid URL.',
    ],
];

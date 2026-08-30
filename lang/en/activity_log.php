<?php

return [
    'listed'              => 'Activity logs retrieved successfully.',
    'list_failed'         => 'Failed to retrieve activity logs.',
    'details_retrieved'   => 'Activity log details retrieved successfully.',
    'not_found'           => 'Activity log not found.',
    'activity_created'    => 'Activity created successfully.',
    'activity_create_failed' => 'Failed to create activity.',
    'email_sent'          => 'Email sent successfully.',
    'email_send_failed'   => 'Failed to send email.',

    // Validation
    'reference_required'      => 'Reference is required.',
    'activity_date_required'  => 'Activity Date is required.',
    'summary_required'        => 'Summary is required.',
    'to_emails_required'      => 'Email is required.',
    'to_emails_min'           => 'At least one recipient email is required.',
    'to_emails_invalid'       => 'Email addresses is invalid.',
    'subject_required'        => 'Subject is required.',
    'description_required'    => 'Message is required.',
    // keep old key for backward compat
    'message_body_required'   => 'Message is required.',
    'attachment_file'         => 'Each attachment must be a file.',
    'attachment_max'          => 'Each attachment may not be greater than 5MB.',
    'attachment_mimes'        => 'Attachments must be a file of type: jpg, jpeg, png, pdf, gif, webp, svg, xls, xlsx, doc, docx, ppt, pptx, txt.',
];

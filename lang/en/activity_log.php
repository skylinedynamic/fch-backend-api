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
    'visibility_updated'       => 'Activity visibility updated successfully.',
    'visibility_update_failed' => 'Failed to update activity visibility.',
    'task_status_updated'       => 'Task status updated successfully.',
    'task_status_update_failed' => 'Failed to update the task status.',

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

    // Who is responsible for a record changed (client Assigned To, Lead Owner, partner Agent)
    'owner_changed'       => ':label changed from :from to :to',
    'owner_set'           => ':label set to :to',
    'owner_removed'       => ':label removed (was :from)',
    'owner_unknown_user'  => 'user #:id',
    // An SSN / EIN / date of birth changed: recorded, never repeated
    'sensitive_field_updated' => ':field was updated',
    'owner_label_client'  => 'Assigned To',
    'owner_label_lead'    => 'Lead Owner',
    'owner_label_partner' => 'Agent',
    'owner_subject_client'  => 'Client Assigned To Changed',
    'owner_subject_lead'    => 'Lead Owner Changed',
    'owner_subject_partner' => 'Partner Agent Changed',

    // Task assigned to a staff member (mail + bell)
    'task_assigned_mail_subject' => 'New task assigned: :subject',
    'task_assigned_mail_heading' => 'A task has been assigned to you',
    'task_assigned_mail_intro'   => ':assigner assigned you a task on :record.',
    'task_assigned_assigned_to'  => 'Assigned To',
    'task_assigned_due_date'     => 'Due Date',
    'task_assigned_priority'     => 'Priority',
    'task_assigned_status'       => 'Status',
    'task_assigned_details'      => 'Details',
    'task_assigned_action'       => 'View Task',
    'task_assigned_someone'      => 'A team member',
    'task_assigned_record_partner'             => 'partner :name',
    'task_assigned_record_client'              => 'client :name',
    'task_assigned_record_lead'                => 'lead :name',
    'task_assigned_record_funding_application' => 'funding application #:id',
    'task_assigned_record_funding_request'     => 'funding request #:id',
    'task_assigned_notify_failed' => 'Task assignment notification failed for activity :id: :error',

    // Task changed - told to its other person, creator or assignee (mail + bell)
    'task_updated_mail_subject' => 'Task updated: :subject',
    'task_updated_mail_heading' => 'A task was updated',
    'task_updated_mail_intro'   => ':actor updated a task on :record.',
    'task_updated_changes'      => 'What changed',
    'task_change_date'          => 'Date: :value',
    'task_change_due_date'      => 'Due date: :value',
    'task_change_priority'      => 'Priority: :value',
    'task_change_status'        => 'Status: :value',
    'task_updated_notify_failed' => 'Task update notification failed for activity :id: :error',
];

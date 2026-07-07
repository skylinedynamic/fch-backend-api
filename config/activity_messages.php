<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Activity Log Messages
    |--------------------------------------------------------------------------
    |
    | This file contains the localized templates for activity log subjects
    | and descriptions to avoid hardcoding strings in the service layer.
    |
    */

    'subjects' => [
        'batch_process' => 'Batch Process :model Created',
        'permission_update' => 'Permissions Assigned to Role',
        'checklist_update' => 'Document Checklist Updated',
        'lead_details_update' => 'Lead Details Updated',
        'lead_contact_update' => 'Lead Contact Info Updated',
        'email_update' => ':model Email Address Updated',
        'borrower_free_trial' => 'Client Free Trial Started',
        'partner_free_trial' => 'Partner Free Trial Started',
        'default' => ':model :action',
    ],

    'descriptions' => [
        'permission_update' => 'New permissions assigned to role: :display_name',
        'checklist_update' => 'The borrower document checklist was updated with new requirements.',
        'lead_details_update' => 'Lead profile details were updated for :display_name.',
        'lead_contact_update' => 'Lead contact information was updated for :display_name.',
        'email_update' => ':model contact email was updated to :new_email for :display_name.',
        'batch_invoice' => 'System generated a renewal invoice (:invoice_id) for user :display_name via batch process.',
        'appointment' => 'Appointment (:subject) :action for :entity_name on :time.',
        'payment' => ':model of :amount :currency :action successfully.',
        'invoice' => ':model (:invoice_id) :action for :display_name',
        'borrower_free_trial' => 'Client profile free trial started for :display_name.',
        'partner_free_trial' => 'Partner profile free trial started for :display_name.',
        'employee_default' => 'Employee profile :action for :display_name.',
        'user_default' => 'User account :action for :display_name.',
        'default' => ':model profile :action for :display_name',
    ],

    'actions' => [
        'create' => 'Created',
        'update' => 'Updated',
        'delete' => 'Deleted',
        'upload' => 'Uploaded',
        'payment' => 'Processed',
        'purchase' => 'Successful',
        'batch' => 'Created (Batch)',
        'batch_process' => 'generated via batch process',
        'permissions' => 'updated',
        'checklist' => 'updated',
    ],
];

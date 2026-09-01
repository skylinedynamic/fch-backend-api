<?php

return [
    'command_description'        => 'Unified: generate invoices for all subscription types and dispatch auto-charge jobs to database queue.',
    'step_1_generating_invoices' => '=== STEP 1: Generating Invoices ===',
    'invoices_generated'         => '[Invoices] Done. All due invoices generated.',
    'step_2_dispatching_jobs'    => '=== STEP 2: Dispatching Auto-Charge Jobs to Database Queue ===',
    'processing_completed'       => '[Done] Unified subscription processing completed.',
    'critical_failure'           => '[ProcessAllSubscriptions] Critical failure: ',
    'found_unpaid_invoices'      => '[AutoCharge] Found :count unpaid invoice(s) to dispatch.',
    'dispatched_charge_jobs'     => '[AutoCharge] Successfully dispatched :count charge job(s) to database queue.',
    'invoices_retrieved'               => 'Subscription invoices retrieved successfully.',
    'borrower_enrollment_email_failed' => 'Borrower enrollment email failed',
    'partner_enrollment_email_failed'  => 'Partner enrollment email failed',
    'borrower_fee_notification_failed' => 'Failed to send borrower fee invoice notification mail to host admin',
    'default_enrollment_fee_title'     => 'Enrollment Fee',
    'default_company_name'             => 'Four Corner Funding',
];

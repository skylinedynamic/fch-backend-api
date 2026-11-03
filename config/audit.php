<?php

/*
|--------------------------------------------------------------------------
| Funding application audit trail
|--------------------------------------------------------------------------
|
| Every change to a funding application's data is an append-only,
| hash-chained event (App\Models\Audit\FundingApplicationAuditEvent). The
| files an event concerns are kept under `folder` on `disk`, named by their
| sha256, so a file is kept once and its name cannot be guessed.
|
| The disk is `public` for now (decided 2026-11-03). Files are only ever
| handed out through the audit API, never linked to directly; moving them to
| a private disk or S3 (Object Lock) later is a change of AUDIT_DISK alone.
|
*/

return [
    'disk' => env('AUDIT_DISK', 'public'),

    'folder' => env('AUDIT_FOLDER', 'audit'),

    // The most rows one page of the trail returns.
    'max_per_page' => 100,
    'default_per_page' => 25,

    // How the exports write a date (always UTC), and the PDF's paper.
    'date_format' => 'Y-m-d H:i:s',
    'pdf_paper' => 'a4',
    'pdf_orientation' => 'landscape',

    // Never in a change: bookkeeping, or kept as its own event.
    'ignored_fields' => [
        'id', 'created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by',
        'application_number', 'document_number',
        'signature_data', 'previous_signature_data',
        'is_business_info_saved', 'is_ownership_info_saved', 'is_financial_info_saved',
        'is_funding_request_saved', 'is_disclosures_saved', 'is_federal_compliance_saved',
    ],

    // Recorded as changed, with only their last four characters.
    'masked_fields' => ['ssn', 'drivers_license_number'],
];

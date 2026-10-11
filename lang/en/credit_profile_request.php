<?php

return [
    'retrieved_successfully' => 'Credit profile requests retrieved successfully.',
    'retrieval_failed' => 'Failed to fetch credit profile requests.',
    'not_found' => 'Credit profile request not found.',
    'details_retrieved_successfully' => 'Credit profile request retrieved successfully.',
    'details_retrieval_failed' => 'Failed to fetch credit profile request details.',
    'log_retrieval_failed' => 'CreditProfileRequestAdminController@index: :error',
    'log_details_retrieval_failed' => 'CreditProfileRequestAdminController@show: :error',

    // Borrower-facing (CreditProfileRequestController / BorrowerCreditProfileController)
    'submitted_successfully' => 'Credit profile request submitted successfully.',
    'fetched_successfully' => 'Credit profile request fetched successfully.',
    'borrower_not_found' => 'Borrower not found.',
    'credit_profile_not_found' => 'Credit profile not found.',
    'no_credit_profile_found' => 'No credit profile found.',
    'credit_profile_fetched' => 'Credit profile fetched successfully.',
    'update_request_sent' => 'Request to update iSoftPull data has been sent successfully.',
    'log_upload_email_failed' => 'Failed to send credit profile upload email to host: :error',
    'log_update_email_failed' => 'Failed to send credit profile update request email to host: :error',

    // Shown when a request arrives with either box unticked. Worded as the
    // client sees it, because the server is the thing actually refusing.
    'credit_authorization_required' => 'You must agree to the Credit Authorization before your credit profile can be requested.',
    'e_consent_required' => 'You must agree to the Electronic Disclosures and Communications before your credit profile can be requested.',
    'consent_not_found' => 'Credit authorization record not found.',

    // A credit profile was saved with no unused authorisation on file to
    // attribute it to - the exact situation an FCRA audit asks about, so it is
    // logged rather than passed over.
    'log_no_consent_to_attach' => 'saveCreditProfile: no unattached credit authorization consent for borrower :borrower_id',
    'log_attach_consent_failed' => 'saveCreditProfile: failed to attach consent to pull for borrower :borrower_id: :error',
];

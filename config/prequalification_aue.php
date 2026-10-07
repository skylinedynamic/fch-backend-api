<?php

/*
|--------------------------------------------------------------------------
| Internal Prequalification AUE
|--------------------------------------------------------------------------
|
| Settings that exist only to build the payload sent to
| FCH_Internal_Prequalification_AUE_v3 (PreQualificationInternalAueService).
|
| Kept apart from config/funding_qualification.php on purpose. That file is the
| shared FCH qualification rule package — FundingQualificationService,
| FundingReadinessService and FundingSequencing all read from it, and a change
| there moves the funding-readiness engine too. Nothing below affects that
| engine; it only describes how prequalification answers are translated for the
| AUE. Keeping the two separate means this can be tuned without touching
| funding qualification, and vice versa.
|
| The underwriting rules themselves are NOT duplicated here. The AUE reads them
| from funding_qualification.rules, because FCH has one rule package and the AUE
| must evaluate against the same one the rest of the platform uses.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Data statuses that stop a borrower-facing assessment
    |--------------------------------------------------------------------------
    |
    | The agent reports a data_status over the borrower's own answers. These are
    | the values that mean the borrower has something to correct: CONFLICTING,
    | where two answers cannot both be true, and INSUFFICIENT, where one needed
    | for a meaningful assessment is missing. On either, the evaluation is kept
    | for FCH staff and the borrower is shown what to fix, rather than an
    | assessment built on figures the agent just called unreliable.
    |
    | COMPLETE and PARTIAL are deliberately absent. PARTIAL is the ordinary
    | state of a pre-qualification — no documents are collected at this stage,
    | and the agent's own instructions forbid treating their absence as a
    | deficiency, so blocking on it would stop every application.
    |
    */
    'blocking_data_statuses' => ['CONFLICTING', 'INSUFFICIENT'],

    /*
    |--------------------------------------------------------------------------
    | Self-reported personal credit bands
    |--------------------------------------------------------------------------
    |
    | The prequalification wizard collects personal credit as a band, never as a
    | score (personalCreditScoreOptions in the borrower front-end). These are the
    | numeric bounds of each band, so the AUE can compare a band against a
    | lender's min_fico without anyone inventing a point score.
    |
    | A band is always borrower-reported. It is never a bureau value, and it is
    | never a substitute for borrower_credit_profiles.fico_score.
    |
    | Keys must match the wizard option values exactly. A band that is not listed
    | resolves to null bounds, which the AUE reads as "not computable" rather
    | than guessing a range.
    |
    */
    'personal_credit_bands' => [
        'Poor (<580)'         => ['min' => null, 'max' => 579],
        'Fair (580-669)'      => ['min' => 580,  'max' => 669],
        'Good (670-739)'      => ['min' => 670,  'max' => 739],
        'Very Good (740-799)' => ['min' => 740,  'max' => 799],
        'Excellent (800+)'    => ['min' => 800,  'max' => null],
    ],

    /*
    |--------------------------------------------------------------------------
    | Business credit self-assessment
    |--------------------------------------------------------------------------
    |
    | The business-credit answer is a qualitative self-assessment, not a Paydex
    | score. Listed here so it is documented that it has no numeric equivalent
    | and therefore cannot be compared to a lender's min_business_credit_score.
    |
    */
    'business_credit_self_assessment_options' => [
        'No Business Credit',
        'Low',
        'Medium',
        'High',
    ],

    /*
    |--------------------------------------------------------------------------
    | AUS model routing
    |--------------------------------------------------------------------------
    |
    | Maps a lender program onto one of the three AUS models the Internal AUE
    | recognises (FCH_Internal_Prequalification_AUE_v3, "AUS MODEL CONTROL").
    | Resolved most-specific-first: the lender's product category, then the FCH
    | funding sequence, then funding type, then funding group, then the default.
    |
    | Product category leads because the model follows the product being
    | underwritten, not the sequencing step FCH files it under — the same lender
    | product appears under several sequences.
    |
    | Names are matched against the records themselves
    | (lender_product_categories.name, funding_sequencings.name,
    | funding_types.name, funding_groups.name), so a category added in the admin
    | UI needs only a line here, not a code change. A category with no line falls
    | back to 'default'.
    |
    | NOTE: the real-estate categories are mapped to the investor model, but the
    | prompt restricts that model to non-owner-occupied transactions and
    | prequalification does not collect occupancy. Any real-estate path must have
    | occupancy confirmed before routing; the AUE is told this in the rule
    | package's data dictionary.
    |
    */
    'aus_models' => [

        'default' => 'REGULAR_AUS',

        'by_product_category' => [
            'Merchant Cash Advance'            => 'MCA_AUS',
            'Revenue-Based Financing'          => 'MCA_AUS',

            'Commercial Real Estate Financing' => 'REAL_ESTATE_INVESTOR_MODEL',
            'Real Estate Investor Financing'   => 'REAL_ESTATE_INVESTOR_MODEL',
            'Fix & Flip Financing'             => 'REAL_ESTATE_INVESTOR_MODEL',
            'Bridge Financing'                 => 'REAL_ESTATE_INVESTOR_MODEL',
            'Construction Financing'           => 'REAL_ESTATE_INVESTOR_MODEL',
            'DSCR Rental Financing'            => 'REAL_ESTATE_INVESTOR_MODEL',
        ],

        'by_funding_sequence' => [
            'Merchant Cash Advance'   => 'MCA_AUS',
            'Revenue Based Financing' => 'MCA_AUS',
        ],

        'by_funding_type' => [
            'Real Estate' => 'REAL_ESTATE_INVESTOR_MODEL',
        ],

        'by_funding_group' => [
            'Real Estate Financing' => 'REAL_ESTATE_INVESTOR_MODEL',
        ],
    ],
];

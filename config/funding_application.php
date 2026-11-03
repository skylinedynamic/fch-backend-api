<?php

use App\Models\Borrower\FundingApplication;

/*
|--------------------------------------------------------------------------
| Funding Application
|--------------------------------------------------------------------------
|
| Everything the funding application is defined by that is not code: the
| dropdown choices it offers, how a credit score is banded, and the limits the
| public embedded form validates against.
|
| Three concerns, one file, because they are always read together and always
| changed together - a new funding type, a re-banded score and a raised amount
| ceiling are the same kind of edit by the same person.
|
| The borrower portal keeps its own copy of the option lists in
| src/data/profile.ts; this is the server-side source the public embedded form
| renders from, and the two must stay in step.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Status Lifecycle
    |--------------------------------------------------------------------------
    |
    | Which statuses let the borrower and partner still touch the application,
    | and what a touch costs them.
    |
    | The lists below reference FundingApplication's constants rather than
    | repeating the strings, so a status is spelled in exactly one place. These
    | resolve to plain strings while the config is read, well after the
    | autoloader is registered, and `config:cache` exports the resolved strings -
    | so the cached config carries no dependency on the model.
    |
    | `order` is the sort order; `editable` is which statuses borrower and
    | partner may still edit. The buckets are worked out from those two:
    |
    |   pre_signature   - listed below. The wizard is still being filled in and
    |                     nothing has been signed, so an edit is just an edit.
    |   resign_on_edit  - every other status in `editable`.
    |                     Already signed, so a real field change invalidates the
    |                     signature: the application drops back to
    |                     `resign_target`, signature_data is cleared, and the
    |                     borrower has to sign again. A save that changes nothing
    |                     (the wizard re-posts every step's prefilled values) must
    |                     NOT cost a signature.
    |   locked          - every status not in `editable`. The file is out
    |                     of our hands - with a lender, under contract, funded or
    |                     closed. Borrower and partner cannot edit at all; only an
    |                     admin status change moves it on.
    |
    | A locked status is also where the borrower may submit a *new* funding
    | request: that round is settled. What must not happen is a second request
    | opened while an application is still being reviewed, which would run two
    | pipelines for the same borrower against the same lenders.
    |
    */

    'statuses' => [

        /*
        | THE ONE PLACE THE STATUS ORDER LIVES.
        |
        | Every status with its order number, 1 being the earliest. It decides
        | how a list sorts by status; it does NOT decide editability - that is
        | the `editable` list below.
        |
        | Stage's statuses, with the two new editable ones (SCRUM-331) right
        | after In Verification and the five new closing ones after Denied.
        |
        | To add a status: add its constant to FundingApplication, give it a
        | number here, and add it to `editable` only if borrower and partner may
        | still change the application in it.
        */
        'order' => [
            FundingApplication::ON_HOLD => 1,
            FundingApplication::DRAFT => 2,
            FundingApplication::PENDING => 3,
            FundingApplication::SUBMITTED => 4,
            FundingApplication::UNDERWRITING_REVIEW => 5,
            FundingApplication::MISSING_DOCUMENTS => 6,
            FundingApplication::IN_VERIFICATION => 7,
            FundingApplication::PENDING_CLARIFICATION => 8,
            FundingApplication::READY_FOR_SUBMISSION => 9,
            FundingApplication::APPROVED_CONDITIONAL => 10,
            FundingApplication::APPROVED_FINAL => 11,
            FundingApplication::MATCHED_TO_LENDER => 12,
            FundingApplication::SUBMITTED_TO_LENDER => 13,
            FundingApplication::LENDER_REVIEWING => 14,
            FundingApplication::OFFER_ISSUED => 15,
            FundingApplication::OFFER_ACCEPTED => 16,
            FundingApplication::CONTRACTING_DOCS_OUT => 17,
            FundingApplication::CLEARED_TO_FUND => 18,
            FundingApplication::FUNDED => 19,
            FundingApplication::CLOSED_COMPLETED => 20,
            FundingApplication::DENIED => 21,
            FundingApplication::WITHDRAWN => 22,
            FundingApplication::OFFER_DECLINED_BY_BORROWER => 23,
            FundingApplication::CLOSED_NO_RESPONSE => 24,
            FundingApplication::CLOSED_INCOMPLETE_APPLICATION => 25,
            FundingApplication::CLOSED_ERROR => 26,
        ],

        /*
        | The statuses where borrower and partner may still edit the
        | application. Every check reads this list; anything not in it - an
        | unknown status included - is read-only for them, and the borrower may
        | start a new request instead.
        |
        | The nine that were editable on stage, plus Pending Clarification and
        | Ready for Submission (SCRUM-331).
        */
        'editable' => [
            FundingApplication::ON_HOLD,
            FundingApplication::DRAFT,
            FundingApplication::PENDING,
            FundingApplication::SUBMITTED,
            FundingApplication::UNDERWRITING_REVIEW,
            FundingApplication::MISSING_DOCUMENTS,
            FundingApplication::IN_VERIFICATION,
            FundingApplication::PENDING_CLARIFICATION,
            FundingApplication::READY_FOR_SUBMISSION,
            FundingApplication::APPROVED_CONDITIONAL,
            FundingApplication::APPROVED_FINAL,
        ],

        // The lender follows this list too (decision 2026-11-03): in these
        // statuses it is optional and the host may change it; every other status
        // needs one assigned before it is set, and from then on it is fixed.
        // See FundingApplication::canAssignLender() / statusRequiresLender().

        // Editable statuses where nothing has been signed yet, so an edit costs
        // nothing. Every other editable status costs the signature on a change.
        'pre_signature' => [
            FundingApplication::ON_HOLD,
            FundingApplication::DRAFT,
        ],

        // Where a signature-invalidating edit puts the application.
        'resign_target' => FundingApplication::DRAFT,

        /*
        | Statuses written by older versions of the application that still sit on
        | rows in the database. Each is read as its modern equivalent, so an old
        | application lands in the right bucket instead of matching nothing.
        |
        | This matters because an unrecognised status is treated as read-only -
        | the safe default for a status nobody has classified. Without this map a
        | bare 'Pending' row, which simply means submitted, would stop being
        | editable the moment the lists above started being enforced.
        */
        'legacy_aliases' => [
            // Written before the status was renamed; it means the same thing.
            // The key stays a literal because no constant names it any more.
            'Pending' => FundingApplication::PENDING,
        ],

        /*
        | Fields excluded when deciding whether a save actually changed anything.
        | These are bookkeeping the server sets itself, or the very fields a
        | resign rewrites - counting them would make every save look like an edit
        | and cost the borrower a signature for paging through the wizard.
        */
        'edit_comparison_ignored_fields' => [
            'id',
            'status',
            'status_note',
            'borrower_id',
            'partner_id',
            'is_partner_prepared',
            'created_by',
            'updated_by',
            'funding_request_id',
            'funding_sequence_id',
            'signature_data',
            'previous_signature_data',
            'attestation_authorization',
            'requires_resign',
            'co_borrowers',
            'is_business_info_saved',
            'is_ownership_info_saved',
            'is_financial_info_saved',
            'is_funding_request_saved',
            'is_disclosures_saved',
            'is_federal_compliance_saved',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | E-Signature Image
    |--------------------------------------------------------------------------
    |
    | What a borrower may pick in the file manager to sign with. Enforced on the
    | server when the file is chosen; the portal reads the same values to word
    | its hint, so the limit is stated in one place.
    |
    */

    'signature' => [
        // Any image type - JPG, JPEG, PNG, HEIC, WEBP, AVIF, GIF and the rest.
        // Checked against the file's contents, not its extension. Narrow it by
        // listing exact types instead, e.g. ['image/png', 'image/jpeg'].
        'mime_types' => ['image/*'],
        // 1 MB.
        'max_file_kb' => 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | Option Lists
    |--------------------------------------------------------------------------
    |
    | The columns these feed are plain strings rather than foreign keys, so a
    | value removed here does not invalidate applications already submitted with
    | it - the embedded form re-adds any unrecognised stored value to its own
    | list, the same way the portal's steps do.
    |
    */

    'options' => [

        'entity_types' => [
            'Sole Proprietor',
            'Partnership',
            'C-Corporation',
            'S-Corporation',
            'Limited Liability Company (LLC)',
            'Other',
        ],

        'industry_types' => [
            'Advertising, Marketing & Media',
            'Agriculture, Farming & Ranching',
            'Automotive (Sales, Repair, Transport)',
            'Construction & Contracting',
            'Consulting & Professional Services',
            'E-Commerce & Online Businesses',
            'Education & Training',
            'Entertainment & Events',
            'Financial Services (Non-Lending)',
            'Food Service & Restaurants',
            'Healthcare & Medical Services',
            'Home Services (Cleaning, Landscaping, Repair)',
            'Hospitality (Hotels, Short-Term Rentals)',
            'Information Technology & Software',
            'Manufacturing',
            'Non-Profit Organization',
            'Personal Services (Beauty, Fitness, Wellness)',
            'Real Estate (Non-Lending)',
            'Retail (Brick & Mortar)',
            'Transportation & Logistics',
            'Wholesale & Distribution',
            'Other',
        ],

        'years_in_business' => [
            'Under 6 months',
            '6-12 months',
            '1-2 years',
            '2-3 years',
            '3+ years',
        ],

        'citizenship_statuses' => [
            'US Citizen',
            'Permanent Resident',
            'Other',
        ],

        'purposes_of_funds' => [
            'Accounts Receivable',
            'Business Acquisition',
            'Business Expansion',
            'Business Line of Credit',
            'Commercial Real Estate Purchase',
            'Commercial Real Estate Refinance',
            'Debt Refinance / Consolidation',
            'Equipment Lease',
            'Equipment Purchase',
            'Franchise Purchase',
            'Furniture, Fixtures & Equipment (FF&E)',
            'Ground-Up Construction',
            'Inventory',
            'Land Purchase / Development',
            'Marketing / Advertising',
            'Owner Occupied Commercial Property',
            'Partner Buyout',
            'Payroll',
            'Professional Services',
            'Renovation / Tenant Improvements',
            'Startup Costs',
            'Technology / Software',
            'Transportation / Fleet',
            'Working Capital',
            'Other',
        ],

        // The one purpose that reveals the free-text note field.
        'purpose_other' => 'Other',

        'funding_types' => [
            'Term Loan',
            'Line of Credit',
            'Equipment Financing',
            'SBA Loan',
            'Merchant Cash Advance',
            'Hard Money / Bridge Loan',
            'Commercial Real Estate Loan',
            'Construction Loan',
            'Asset-Based Lending (AR / Inventory)',
            'Invoice Factoring',
            'Revenue-Based Financing',
            'Unsecured Business Credit',
            'Business Acquisition Financing',
            'Refinance / Debt Consolidation',
        ],

        'timeframes' => [
            'Immediately',
            '1-2 Weeks',
            '1 Month',
            'Flexible',
        ],

        'collateral' => [
            'None',
            'Business Assets',
            'Real Estate',
            'Equipment',
            'Inventory',
        ],

        'co_borrower_roles' => [
            'Owner',
            'Partner',
            'Officer',
            'Director',
            'Guarantor',
            'Other',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Credit Score Bands
    |--------------------------------------------------------------------------
    |
    | The single source of truth for turning a credit score into a band label.
    | Every surface that renders a score - the partner portal's submission list,
    | the submission detail page, the public form - resolves its band through
    | CreditScoreService rather than carrying its own thresholds. The front end
    | used to hard code them, which is how 710 and 720 came to be labelled "Fair"
    | when FICO puts 670-739 in "Good".
    |
    | Bands are matched in order, on `min <= score <= max`. Changing one is a
    | code change on purpose: a score has to mean the same thing on every screen
    | and in every stored application, so the table is reviewed and deployed
    | rather than editable in production.
    |
    */

    'credit_score' => [

        'min' => 300,
        'max' => 850,

        'bands' => [
            [
                'key' => 'poor',
                'label' => 'Poor',
                'min' => 300,
                'max' => 579,
                // Bootstrap contextual colour used by the portal's score pills.
                'tone' => 'danger',
            ],
            [
                'key' => 'fair',
                'label' => 'Fair',
                'min' => 580,
                'max' => 669,
                'tone' => 'warning',
            ],
            [
                'key' => 'good',
                'label' => 'Good',
                'min' => 670,
                'max' => 739,
                'tone' => 'info',
            ],
            [
                'key' => 'very_good',
                'label' => 'Very Good',
                'min' => 740,
                'max' => 799,
                'tone' => 'success',
            ],
            [
                'key' => 'exceptional',
                'label' => 'Exceptional',
                'min' => 800,
                'max' => 850,
                'tone' => 'success',
            ],
        ],

        // Business credit is self-reported on a different scale entirely, so it
        // is never run through the bands above.
        'business_score_options' => [
            'Not established yet',
            'Not sure',
            '0-49',
            '50-64',
            '65-74',
            '75-79',
            '80-100',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Embedded Form Rules
    |--------------------------------------------------------------------------
    |
    | Limits and controlled lists the public form validates against. Kept in
    | config rather than inline in the request class so a range can be widened
    | without touching validation code.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Postal Code
    |--------------------------------------------------------------------------
    |
    | How many digits a US ZIP code has. Sits at the top level rather than under
    | embed_form because App\Rules\UsPostalCode reads it for every zip field in
    | both portals, not just the public form.
    |
    */

    'postal_code_length' => 5,

    /*
    |--------------------------------------------------------------------------
    | Signing a funding application
    |--------------------------------------------------------------------------
    |
    | The documents a signer accepts, at the version in force. Recorded by the
    | server with every signature (SignatureRecorder) - never taken from what the
    | browser posts - and shown on the electronic signature certificate.
    |
    | CHANGING A DOCUMENT: publish the new version at its URL and bump `version`
    | here. Never edit an existing version's text: signatures already recorded
    | point at that version string. Versions are strings: '1.0', not 1.0.
    |
    */

    'signing' => [
        'policies' => [
            'esign' => [
                'label' => 'E-SIGN Consent (Electronic Disclosures and Communications)',
                'version' => '1.0',
                'url' => 'https://fourcornerholdings.com/e-consent',
            ],
            'terms' => [
                'label' => 'Terms & Conditions',
                'version' => '1.0',
                'url' => 'https://fourcornerholdings.com/terms',
            ],
            'privacy' => [
                'label' => 'Privacy Policy',
                'version' => '1.0',
                'url' => 'https://fourcornerholdings.com/privacy',
            ],
            'credit_authorization' => [
                'label' => 'Credit Authorization',
                'version' => '1.0',
                'url' => 'https://fourcornerholdings.com/credit-authorization',
            ],
        ],
    ],

    'embed_form' => [

        // Currency bounds. A submission outside these is refused rather than
        // passed to a partner as a serious enquiry.
        'amounts' => [
            'requested_amount' => ['min' => 1000, 'max' => 50000000],
            'monthly_revenue' => ['min' => 0, 'max' => 50000000],
            'annual_revenue' => ['min' => 0, 'max' => 500000000],
            'debt_balance' => ['min' => 0, 'max' => 500000000],
        ],

        // Minimum age to apply. Contracts signed by a minor are not enforceable,
        // so this is a hard floor rather than a warning.
        'minimum_age' => 18,

        // Referral source is a controlled list so the field can actually be
        // reported on - it used to accept arbitrary free text, which made it
        // unaggregatable. "Other" reveals a free-text box captured separately.
        'referral_sources' => [
            'Search Engine',
            'Social Media',
            'Referred by a Friend or Colleague',
            'Existing Client',
            'Broker or Partner Referral',
            'Email Campaign',
            'Event or Conference',
            'Advertisement',
            'Other',
        ],

        'referral_source_other' => 'Other',

        // The currencies a requested amount may be given in, with the symbol
        // the form shows and the funding application keeps. The first is the
        // default for a form loaded before the choice existed.
        'currencies' => [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
        ],

    ],

];

<?php

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

    'embed_form' => [

        // US ZIP: exactly five digits.
        'postal_code_length' => 5,

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

    ],

];

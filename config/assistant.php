<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Assistant Settings
    |--------------------------------------------------------------------------
    |
    | Per-assistant-type settings — a plain config file, not a database row,
    | so nothing here requires an app_configurations write or an .env value to
    | go live: it ships and changes with the code, the same way the system
    | prompt (app/Services/Assistant/*.txt) and knowledge files
    | (app/Services/Assistant/Knowledge/{type}/) already do.
    |
    | is_enabled defaults to false here on purpose: this file is git-tracked,
    | so whatever value is committed goes to every environment that deploys
    | this code. Flip it to true only once the real approved system prompt
    | and knowledge content have replaced the TEST_PLACEHOLDER_* files — never
    | commit it as true with placeholder content still in place.
    |
    | model/reasoning_effort are left null on purpose too: null means "use
    | the shared OpenAiResponsesService::defaultModel()/defaultReasoningEffort()"
    | (Settings > OpenAI Configuration), the same default Pre-Qualification
    | uses, rather than a separate value to keep in sync.
    |
    */

    /*
    | openai_prompt_id / openai_prompt_version: when a prompt id (pmpt_...) is set,
    | the assistant runs the Agent (stored Prompt) configured on the OpenAI
    | platform: its instructions, model and knowledge follow whatever is
    | published there, with no deploy. A blank version means "the prompt's
    | current version" on OpenAI; set it to pin. With no prompt id the bundled
    | prompt file / database prompt versions below are used instead.
    */

    'wlp_support' => [
        'is_enabled' => true,
        'openai_prompt_id' => null,
        'openai_prompt_version' => null,
        'model' => null,
        'reasoning_effort' => null,
        'max_output_tokens' => null,
        'vector_store_id' => null,
        'knowledge_manifest_version' => null,
    ],

    'borrower_support' => [
        'is_enabled' => true,
        'openai_prompt_id' => null,
        'openai_prompt_version' => null,
        'model' => null,
        'reasoning_effort' => null,
        'max_output_tokens' => null,
        'vector_store_id' => null,
        'knowledge_manifest_version' => null,
    ],


    /*
    |--------------------------------------------------------------------------
    | Take Me There navigation map (server-owned)
    |--------------------------------------------------------------------------
    |
    | The model never produces a URL. For every Take Me There CTA it returns
    | a navigation.route_key, and ONLY a key listed here for that assistant
    | is accepted — the path comes from this table, never from model output,
    | the user's message, or the page the user is currently on. An unknown or
    | missing key means no navigation action at all (never a default page).
    |
    | Keys/labels mirror the approved Canonical navigation maps in WLP System
    | Prompt v1.3.2 and Borrower Instructions v1.5. Each frontend keeps its own
    | matching allowlist and refuses any action whose path differs.
    |
    | Changing this table is an application change (code review + deploy);
    | prompt wording changes are not — see the prompt versioning commands.
    |
    */

    'navigation' => [

        'wlp_support' => [
            'leads' => ['label' => 'Leads', 'path' => '/leads'],
            'clients' => ['label' => 'Clients', 'path' => '/clients'],
            // Approved map lists '/pre-aualification' (typo, no such route in
            // the WLP portal); the real Intake & Review route is used instead.
            'pre_qualification' => ['label' => 'Intake & Review > Pre-Qualification', 'path' => '/intake-review/pre-qualification'],
            'funding_request' => ['label' => 'Intake & Review > Funding Request', 'path' => '/intake-review/funding-request'],
            'funding_application' => ['label' => 'Intake & Review > Funding Application', 'path' => '/intake-review/funding-application'],
            'commissions' => ['label' => 'Commissions', 'path' => '/commissions'],
            'invoices' => ['label' => 'Invoices', 'path' => '/invoices'],
            'payments' => ['label' => 'Payments', 'path' => '/payments'],
            'subscriptions' => ['label' => 'Subscriptions', 'path' => '/subscriptions'],
            'upcoming_billing' => ['label' => 'Upcoming Billing', 'path' => '/billing'],
            'appointments' => ['label' => 'Appointments', 'path' => '/appointments'],
            'task_reminders' => ['label' => 'Task & Reminders', 'path' => '/reminders'],
            'resources' => ['label' => 'Resources', 'path' => '/resources'],
            'employees' => ['label' => 'Employees', 'path' => '/employees'],
            'users' => ['label' => 'Users', 'path' => '/users'],
            'roles_permissions' => ['label' => 'Roles & Permissions', 'path' => '/roles-permissions'],
            'embedded_form_configuration' => ['label' => 'Embedded Form > Configuration', 'path' => '/embed-form/settings'],
            'embedded_form_loan_applications' => ['label' => 'Embedded Form > Loan Applications', 'path' => '/embed-form/loan-applications'],
            // Individual Settings tabs (Email Configuration, Test Mail, ...) have
            // no routes of their own: they all resolve to 'settings'.
            'settings' => ['label' => 'Settings', 'path' => '/settings'],
        ],

        'borrower_support' => [
            'pre_qualification' => ['label' => 'Pre-Qualification', 'path' => '/pre-qualification'],
            'funding_request' => ['label' => 'Funding Request', 'path' => '/funding-request'],
            'my_applications' => ['label' => 'My Applications', 'path' => '/my-applications'],
            'capital_access' => ['label' => 'Capital Access', 'path' => '/capital-access'],
            'invoices' => ['label' => 'Invoices', 'path' => '/invoices'],
            'payments' => ['label' => 'Payments', 'path' => '/payments'],
            'billing_details' => ['label' => 'Billing Details', 'path' => '/billing-details'],
            'documents' => ['label' => 'Documents', 'path' => '/documents'],
            'resource_hub' => ['label' => 'Resource Hub', 'path' => '/resources'],
            'appointments' => ['label' => 'Appointments', 'path' => '/appointment'],
            'funding' => ['label' => 'Funding', 'path' => '/funding'],
            'credit_builder_overview' => ['label' => 'Credit Builder > Overview', 'path' => '/credit-builder/overview'],
            'credit_builder_guide' => ['label' => 'Credit Builder > Step-By-Step Guide', 'path' => '/credit-builder/guide'],
            'credit_builder_credit_accounts' => ['label' => 'Credit Builder > Credit Accounts', 'path' => '/credit-builder/credit-accounts'],
            'profile' => ['label' => 'Profile', 'path' => '/profile'],
        ],

    ],

];

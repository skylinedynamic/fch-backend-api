<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Take Me There navigation map (server-owned)
    |--------------------------------------------------------------------------
    |
    | The agent never produces a URL. For every Take Me There action it returns
    | a navigation.route_key, and ONLY a key listed here for that assistant is
    | accepted — the path comes from this table, never from model output, the
    | user's message, or the page the user is currently on. An unknown or
    | missing key means no navigation action at all (never a default page).
    |
    | Keys and labels mirror the Canonical navigation maps in the agents'
    | approved instructions on the OpenAI platform; the labels are what the
    | agent is shown when it picks a key. Each portal reads the key => path map
    | from its assistant config endpoint and refuses any action whose path
    | differs from it.
    |
    | A destination added to an agent's instructions needs a row here too:
    | until it has one, the agent cannot select it and no button is shown.
    |
    */

    'navigation' => [

        'wlp_support' => [
            'dashboard' => ['label' => 'Dashboard', 'path' => '/'],
            'leads' => ['label' => 'Leads', 'path' => '/leads'],
            'clients' => ['label' => 'Clients', 'path' => '/clients'],
            // The agent's map lists '/pre-aualification' (a typo; the portal has
            // no such route). The real Intake & Review route is used instead.
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
            'notifications' => ['label' => 'Notification', 'path' => '/notifications'],
            'resources' => ['label' => 'Resources', 'path' => '/resources'],
            'employees' => ['label' => 'Employees', 'path' => '/employees'],
            'users' => ['label' => 'Users', 'path' => '/users'],
            'roles_permissions' => ['label' => 'Roles & Permissions', 'path' => '/roles-permissions'],
            'embedded_form_configuration' => ['label' => 'Embedded Form > Configuration', 'path' => '/embed-form/settings'],
            'embedded_form_loan_applications' => ['label' => 'Embedded Form > Loan Applications', 'path' => '/embed-form/loan-applications'],
            // Individual Settings tabs (Email Configuration, Test Mail, ...) have
            // no routes of their own: they all resolve to 'settings'.
            'settings' => ['label' => 'Settings', 'path' => '/admin/settings'],
            // The profile dropdown in the header.
            'my_subscription' => ['label' => 'My Subscription', 'path' => '/my-subscription'],
            'profile' => ['label' => 'Profile', 'path' => '/profile'],
            'contacts' => ['label' => 'Contacts', 'path' => '/contacts'],
        ],

        'borrower_support' => [
            'dashboard' => ['label' => 'Dashboard', 'path' => '/'],
            'pre_qualification' => ['label' => 'Pre-Qualification', 'path' => '/pre-qualification'],
            'funding_request' => ['label' => 'Funding Request', 'path' => '/funding-requests'],
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
            'change_password' => ['label' => 'Change Password', 'path' => '/change-password'],
            'notifications' => ['label' => 'Notification', 'path' => '/notifications'],
        ],

    ],

];

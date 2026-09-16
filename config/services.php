<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    /*
     * The SES MAILER's credentials, and so tenant data like the SMTP block in
     * config/mail.php - not deployment configuration. Resolved per message from
     * `email_configurations` by EmailConfigurationService::resolveFor().
     *
     * Unrelated to the S3 filesystem disk, which reads its own AWS_* values.
     */
    'ses' => [
        'key' => null,
        'secret' => null,
        'region' => null,
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Microsoft Graph / SharePoint configuration
    'microsoft' => [
        'tenant_id' => env('MS_TENANT_ID'),
        'client_id' => env('MS_CLIENT_ID'),
        'client_secret' => env('MS_CLIENT_SECRET'),
        'sharepoint_site_id' => env('MS_SHAREPOINT_SITE_ID'),
        'calendar_service_email' => env('MS_CALENDAR_SERVICE_EMAIL'), // The internal account that hosts events
        'login_endpoint' => env('MS_LOGIN_ENDPOINT', 'https://login.microsoftonline.com/'),
        'graph_endpoint' => env('MS_GRAPH_ENDPOINT', 'https://graph.microsoft.com/v1.0'),
        'default_scope'  => env('MS_DEFAULT_SCOPE', 'https://graph.microsoft.com/.default'),
    ],

    'microbilt' => [
        'base_url' => env('MICROBILT_BASE_URL', 'https://api.microbilt.com/'), // Default to prod or sandbox URL
        'client_id' => env('MICROBILT_CLIENT_ID'),
        'client_secret' => env('MICROBILT_CLIENT_SECRET'),
    ],

    'opencorporates' => [
        'api_token' => env('OPENCORPORATES_API_TOKEN'),
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
    ],

    // OpenAI is configured in the database (app_configurations, module "openai")
    // via Settings > OpenAI Configuration — API key, model and reasoning effort.
    // See config/openai.php for defaults and the model list.

];

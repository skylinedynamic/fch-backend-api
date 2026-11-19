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
     * `mail_configurations` by EmailConfigurationService::resolveFor().
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

    /*
     * Calendly - nothing for it in .env. Each partner (host, WLP, certified)
     * saves its own Client ID, Client Secret and Webhook Signing Key in
     * Settings > Calendly (calendly_connections). The redirect address is this
     * API's /api/v1/calendly/callback on the address it is reached at (shown in
     * Settings > Calendly to enter on the Calendly app), and each connection's
     * webhooks go to /api/v1/calendly/webhook/{id} likewise. redirect_uri and
     * webhook_url stay null here (tests may set them).
     */
    'calendly' => [
        'redirect_uri' => null,
        'webhook_url' => null,
        'auth_url' => 'https://auth.calendly.com',
        'api_url' => 'https://api.calendly.com',
        // OAuth scopes asked for when connecting - each must also be ticked on the
        // OAuth app in Calendly's developer console (event_types:write: a meeting
        // named after the appointment title). Each :read is asked for as well as
        // its :write - a token with only scheduled_events:write was refused GET
        // /scheduled_events ("Insufficient scope").
        'scopes' => 'users:read event_types:read event_types:write availability:read scheduled_events:read scheduled_events:write webhooks:read webhooks:write',
    ],

    'microbilt' => [
        'base_url' => env('MICROBILT_BASE_URL', 'https://api.microbilt.com/'), // Default to prod or sandbox URL
        'client_id' => env('MICROBILT_CLIENT_ID'),
        'client_secret' => env('MICROBILT_CLIENT_SECRET'),
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
    ],

    // OpenAI is configured in the database (app_configurations, module "openai")
    // via Settings > OpenAI Configuration — API key, model and reasoning effort.
    // See config/openai.php for defaults and the model list.

];

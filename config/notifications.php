<?php

/*
|--------------------------------------------------------------------------
| In-app notifications
|--------------------------------------------------------------------------
|
| Every module notifies users through App\Services\FCH\Common\Notification\Notifier:
|
|     app(Notifier::class)->send('borrower.funding_request.status_changed', $borrower, [
|         'id' => $request->id, 'status' => $request->status,
|     ]);
|
| The catalogue below ships with the code, so a new type goes live on a normal
| deploy (deploys run migrations, never seeders). A row in notification_types
| with the same key overrides any of these fields at runtime (templates,
| channels, is_active) without a deploy.
|
| Templates use {{placeholder}} substitution only. Keep them free of PII
| (SSN, account/card numbers, credit data): they are stored in the
| notifications table and pushed over the WebSocket. Links must be paths
| inside the portal the audience uses ("/..."), anything else is dropped.
|
*/

return [

    // Channel name => Laravel notification channel (name or class).
    // Add a channel (mail, sms, webpush...) here and list it on a type.
    'channels' => [
        'database' => 'database',
        'broadcast' => 'broadcast',
    ],

    'inbox' => [
        'per_page' => 15,
        'max_per_page' => 50,
    ],

    'types' => [

        'system.test' => [
            'name' => 'Test notification',
            'category' => 'system',
            'icon' => 'ri-notification-3-line',
            'channels' => ['database', 'broadcast'],
            'title' => 'Test notification',
            'body' => 'Real-time notifications are working.',
            'url' => null,
        ],

        // Borrower portal
        'borrower.funding_request.status_changed' => [
            'name' => 'Funding request status changed (borrower)',
            'category' => 'funding',
            'icon' => 'ri-hand-coin-line',
            'channels' => ['database', 'broadcast'],
            'title' => 'Funding request {{status}}',
            'body' => 'Your funding request #{{id}} is now {{status}}.',
            'url' => '/funding-requests/{{id}}',
        ],

        // Partner portal
        'partner.funding_request.status_changed' => [
            'name' => 'Funding request status changed (partner)',
            'category' => 'funding',
            'icon' => 'ri-hand-coin-line',
            'channels' => ['database', 'broadcast'],
            'title' => 'Funding request {{status}}',
            'body' => 'Funding request #{{id}} for your client is now {{status}}.',
            'url' => '/intake-review/funding-request/{{id}}',
        ],

    ],

];

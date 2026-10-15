<?php

/*
|--------------------------------------------------------------------------
| Notifications
|--------------------------------------------------------------------------
|
| Everything the platform tells anyone - in the bell, by email - goes out
| through App\Services\FCH\Common\Notification\Notifier:
|
|     app(Notifier::class)->send('payment.approved', $payer, [
|         'id' => $payment->id, 'amount' => 'USD 150.00', 'invoice' => 'INV-7',
|     ], mail: (new PaymentApprovedMail($mailData, $partnerId))->to($email));
|
| The catalogue below ships with the code, so a new type goes live on a normal
| deploy (deploys run migrations, never seeders). A row in notification_types
| with the same key overrides any of these fields at runtime (templates,
| channels, is_active) without a deploy - including switching a type's email
| off. Nothing may call Mail:: directly; tests/Unit/Notification/
| NotificationCatalogueTest enforces that and that every key used is listed.
|
| Channels:
|   database, broadcast  the bell (stored + pushed over Reverb), to the login
|                        users behind the recipient the caller names
|   mail                 the type's own Mailable, sent to the addresses and
|                        from the mailbox it already carries. Queued or inline
|                        per the Mailable (ShouldQueue / SendsImmediately).
|   sms                  a text to the same users as the bell, at their stored
|                        phone number normalised to E.164 when sent (SmsSender;
|                        a failed text is logged, never thrown). The
|                        text is the type's `sms` template, or "title: body"
|                        when it has none. A white-label partner's users are
|                        texted from its own SMS account, everyone else from
|                        the host's; not at all while that account is disabled
|                        (SmsConfigurationService::resolveFor). 2FA codes use
|                        the same accounts.
|                        No type lists it yet.
| Web push would be added the same way: a Laravel notification channel under
| `channels`, then listed on the types that should use it.
|
| Templates use {{placeholder}} substitution only. Keep them free of PII
| (SSN, account/card numbers, credit data): they are stored in the
| notifications table and pushed over the WebSocket. Links must be paths
| inside the portal the audience uses ("/..."), anything else is dropped.
| Categories are the ones both portals colour: funding, document, payment,
| reminder, system.
|
*/

use App\Services\FCH\Common\Notification\Sms\SmsChannel;

$bellAndMail = ['database', 'broadcast', 'mail', 'sms'];

return [

    // Channel name => Laravel notification channel (name or class) for the
    // in-app side. `mail` is delivered by the Notifier itself, see above.
    'channels' => [
        'database' => 'database',
        'broadcast' => 'broadcast',
        'sms' => SmsChannel::class,
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

        // ── Funding requests ────────────────────────────────────────────

        'borrower.funding_request.status_changed' => [
            'name' => 'Funding request status changed (borrower)',
            'category' => 'funding',
            'icon' => 'ri-hand-coin-line',
            'channels' => $bellAndMail,
            'title' => 'Funding request {{status}}',
            'body' => 'Your funding request #{{id}} is now {{status}}.',
            'url' => '/funding-requests/{{id}}',
        ],

        'partner.funding_request.status_changed' => [
            'name' => 'Funding request status changed (partner)',
            'category' => 'funding',
            'icon' => 'ri-hand-coin-line',
            'channels' => $bellAndMail,
            'title' => 'Funding request {{status}}',
            'body' => 'Funding request #{{id}} for your client is now {{status}}.',
            'url' => '/intake-review/funding-request/{{id}}',
        ],

        'host.funding_request.approved' => [
            'name' => "Partner's funding request approved (host copy)",
            'category' => 'funding',
            'icon' => 'ri-hand-coin-line',
            'channels' => $bellAndMail,
            'title' => 'Funding request approved',
            'body' => "Funding request #{{id}} for {{partner}}'s client was approved.",
            'url' => '/intake-review/funding-request/{{id}}',
        ],

        'partner.funding_request.submitted' => [
            'name' => 'Funding request submitted (host / partner)',
            'category' => 'funding',
            'icon' => 'ri-hand-coin-line',
            'channels' => $bellAndMail,
            'title' => 'New funding request',
            'body' => '{{borrower}} submitted funding request #{{id}}.',
            'url' => '/intake-review/funding-request/{{id}}',
        ],

        // ── Funding applications ────────────────────────────────────────

        'borrower.funding_application.reminder' => [
            'name' => 'Funding application not started (borrower reminder)',
            'category' => 'reminder',
            'icon' => 'ri-alarm-warning-line',
            'channels' => $bellAndMail,
            'title' => 'Start your funding application',
            'body' => 'Your funding request #{{id}} was approved. Start your funding application to keep it moving.',
            'url' => '/fund-application/{{id}}',
        ],

        'partner.funding_application.reminder' => [
            'name' => 'Funding application not started (partner reminder)',
            'category' => 'reminder',
            'icon' => 'ri-alarm-warning-line',
            'channels' => $bellAndMail,
            'title' => 'Funding application not started',
            'body' => "{{borrower}} hasn't started the funding application for approved request #{{id}}.",
            'url' => '/intake-review/funding-request/{{id}}',
        ],

        'borrower.funding_application.submitted' => [
            'name' => 'Funding application submitted (borrower)',
            'category' => 'funding',
            'icon' => 'ri-file-list-3-line',
            'channels' => $bellAndMail,
            'title' => 'Funding application submitted',
            'body' => "We received your funding application. We'll be in touch with next steps.",
            'url' => '/my-applications/funding/{{id}}',
        ],

        'partner.funding_application.submitted' => [
            'name' => 'Funding application submitted (host / partner)',
            'category' => 'funding',
            'icon' => 'ri-file-list-3-line',
            'channels' => $bellAndMail,
            'title' => 'New funding application',
            'body' => '{{borrower}} submitted funding application #{{id}}.',
            'url' => '/intake-review/funding-application/{{id}}',
        ],

        'borrower.funding_application.draft_ready' => [
            'name' => 'Prepared funding application ready (borrower)',
            'category' => 'funding',
            'icon' => 'ri-file-edit-line',
            'channels' => $bellAndMail,
            'title' => 'Your application is ready to review',
            'body' => 'A funding application has been prepared for you. Review it and submit.',
            'url' => '/fund-application/{{id}}',
        ],

        'partner.pre_qualification.submitted' => [
            'name' => 'Pre-qualification submitted',
            'category' => 'funding',
            'icon' => 'ri-survey-line',
            'channels' => $bellAndMail,
            'title' => 'New pre-qualification',
            'body' => '{{borrower}} submitted a pre-qualification request.',
            'url' => '/intake-review/pre-qualification/{{id}}',
        ],

        // ── Leads & embedded form ───────────────────────────────────────

        'lead.created' => [
            'name' => 'Lead created',
            'category' => 'funding',
            'icon' => 'ri-user-add-line',
            'channels' => $bellAndMail,
            'title' => 'New lead',
            'body' => '{{name}} was added as a new {{source}} lead.',
            'url' => '/leads/{{id}}',
        ],

        'partner.embed_form.submitted' => [
            'name' => 'Embedded form submitted',
            'category' => 'funding',
            'icon' => 'ri-inbox-archive-line',
            'channels' => $bellAndMail,
            'title' => 'New loan application',
            'body' => '{{name}} submitted your embedded application form.',
            'url' => '/embed-form/loan-applications/{{id}}',
        ],

        'partner.embed_form.converted' => [
            'name' => 'Embedded form converted to client (host / partner)',
            'category' => 'funding',
            'icon' => 'ri-user-follow-line',
            'channels' => $bellAndMail,
            'title' => 'Application converted to client',
            'body' => "{{name}}'s embedded form application was converted to a client.",
            'url' => '/clients/{{borrower_id}}',
        ],

        'borrower.embed_form.converted' => [
            'name' => 'Embedded form converted (applicant)',
            'category' => 'funding',
            'icon' => 'ri-user-follow-line',
            'channels' => $bellAndMail,
            'title' => 'Your application was received',
            'body' => "Your application has been set up. We'll be in touch with next steps.",
            'url' => '/dashboard',
        ],

        // ── Payments & invoices ─────────────────────────────────────────
        // /payments/:id and /invoices/:id exist in both portals.

        'payment.submitted' => [
            'name' => 'Manual payment submitted (payer)',
            'category' => 'payment',
            'icon' => 'ri-bank-card-line',
            'channels' => $bellAndMail,
            'title' => 'Payment submitted',
            'body' => 'Your payment of {{amount}} for invoice {{invoice}} is awaiting confirmation.',
            'url' => '/payments/{{id}}',
        ],

        'payment.submitted_for_review' => [
            'name' => 'Manual payment submitted (receiver)',
            'category' => 'payment',
            'icon' => 'ri-bank-card-line',
            'channels' => $bellAndMail,
            'title' => 'Payment awaiting review',
            'body' => '{{payer}} submitted a payment of {{amount}} for invoice {{invoice}}.',
            'url' => '/payments/{{id}}',
        ],

        'payment.succeeded' => [
            'name' => 'Payment successful (payer)',
            'category' => 'payment',
            'icon' => 'ri-checkbox-circle-line',
            'channels' => $bellAndMail,
            'title' => 'Payment successful',
            'body' => 'Your payment of {{amount}} for invoice {{invoice}} went through.',
            'url' => '/payments/{{id}}',
        ],

        'payment.received' => [
            'name' => 'Payment received (receiver)',
            'category' => 'payment',
            'icon' => 'ri-money-dollar-circle-line',
            'channels' => $bellAndMail,
            'title' => 'Payment received',
            'body' => '{{payer}} paid {{amount}} for invoice {{invoice}}.',
            'url' => '/payments/{{id}}',
        ],

        'payment.approved' => [
            'name' => 'Manual payment approved',
            'category' => 'payment',
            'icon' => 'ri-checkbox-circle-line',
            'channels' => $bellAndMail,
            'title' => 'Payment approved',
            'body' => 'Your payment of {{amount}} for invoice {{invoice}} was approved.',
            'url' => '/payments/{{id}}',
        ],

        'payment.rejected' => [
            'name' => 'Manual payment rejected',
            'category' => 'payment',
            'icon' => 'ri-close-circle-line',
            'channels' => $bellAndMail,
            'title' => 'Payment rejected',
            'body' => 'Your payment of {{amount}} for invoice {{invoice}} was rejected.',
            'url' => '/payments/{{id}}',
        ],

        'borrower.payment.card_reauthorization_required' => [
            'name' => 'Saved card must be re-entered (borrower)',
            'category' => 'payment',
            'icon' => 'ri-error-warning-line',
            'channels' => $bellAndMail,
            'title' => 'Update your payment card',
            'body' => "We couldn't charge your saved card for invoice {{invoice}}. Please enter your card again.",
            'url' => '/billing-details',
        ],

        'partner.payment.card_reauthorization_required' => [
            'name' => 'Saved card must be re-entered (partner)',
            'category' => 'payment',
            'icon' => 'ri-error-warning-line',
            'channels' => $bellAndMail,
            'title' => 'Update your payment card',
            'body' => "We couldn't charge your saved card for invoice {{invoice}}. Please enter your card again.",
            'url' => '/my-subscription',
        ],

        'borrower.payment.failed' => [
            'name' => 'Automatic payment failed (borrower)',
            'category' => 'payment',
            'icon' => 'ri-error-warning-line',
            'channels' => $bellAndMail,
            'title' => 'Payment not taken',
            'body' => "We couldn't take the automatic payment for invoice {{invoice}}. Please update your payment method or pay it from your billing page.",
            'url' => '/billing-details',
        ],

        'partner.payment.failed' => [
            'name' => 'Automatic payment failed (partner)',
            'category' => 'payment',
            'icon' => 'ri-error-warning-line',
            'channels' => $bellAndMail,
            'title' => 'Payment not taken',
            'body' => "We couldn't take the automatic payment for invoice {{invoice}}. Please update your payment method or pay it from My Subscription.",
            'url' => '/my-subscription',
        ],

        'invoice.sent' => [
            'name' => 'Invoice sent',
            'category' => 'payment',
            'icon' => 'ri-file-list-2-line',
            'channels' => $bellAndMail,
            'title' => 'New invoice',
            'body' => 'Invoice {{invoice}} for {{amount}} is ready.',
            'url' => '/invoices/{{id}}',
        ],

        'host.borrower_fee_invoice.created' => [
            'name' => 'Borrower fee invoice created (host)',
            'category' => 'payment',
            'icon' => 'ri-file-list-2-line',
            'channels' => $bellAndMail,
            'title' => 'Borrower fee invoice created',
            'body' => '{{partner}} enrolled {{borrower}}; fee invoice {{invoice}} was created.',
            'url' => '/invoices/{{id}}',
        ],

        'partner.commission.paid' => [
            'name' => 'Commission paid out',
            'category' => 'payment',
            'icon' => 'ri-money-dollar-circle-line',
            'channels' => $bellAndMail,
            'title' => 'Commission paid',
            'body' => 'Your commission payout of {{amount}} was processed.',
            'url' => '/commissions/{{id}}',
        ],

        // ── Profile, credit & documents ─────────────────────────────────

        'host.change_request.submitted' => [
            'name' => 'Profile change requested (host)',
            'category' => 'document',
            'icon' => 'ri-edit-box-line',
            'channels' => $bellAndMail,
            'title' => 'New profile change request',
            'body' => '{{borrower}} asked to change their {{field}}.',
            'url' => '/intake-review/change-requests',
        ],

        'borrower.change_request.decided' => [
            'name' => 'Profile change request decided (borrower)',
            'category' => 'document',
            'icon' => 'ri-edit-box-line',
            'channels' => $bellAndMail,
            'title' => 'Profile change request {{status}}',
            'body' => 'Your request to change your {{field}} was {{status}}.',
            'url' => '/profile',
        ],

        'host.credit_builder.verification_requested' => [
            'name' => 'Credit builder tasks submitted (host)',
            'category' => 'document',
            'icon' => 'ri-shield-check-line',
            'channels' => $bellAndMail,
            'title' => 'Tasks submitted for verification',
            'body' => '{{borrower}} submitted {{count}} credit builder task(s) for verification.',
            'url' => '/verification-center/borrower-requests/{{borrower_id}}',
        ],

        'borrower.credit_builder.verification_decided' => [
            'name' => 'Credit builder verification decided (borrower)',
            'category' => 'document',
            'icon' => 'ri-shield-check-line',
            'channels' => $bellAndMail,
            'title' => 'Task verification: {{status}}',
            'body' => 'Your submitted credit builder tasks have been reviewed.',
            'url' => '/credit-builder',
        ],

        'host.credit_profile.requested' => [
            'name' => 'Credit profile requested (host)',
            'category' => 'document',
            'icon' => 'ri-file-user-line',
            'channels' => $bellAndMail,
            'title' => 'New credit profile request',
            'body' => '{{borrower}} submitted a credit profile request.',
            'url' => '/intake-review/credit-profile-requests/{{id}}',
        ],

        // Sent to the borrower when the host saves their iSoftPull credit
        // report (Client -> Credit Profile). Opens the profile's Credit
        // profile tab (index 3).
        'borrower.credit_profile.updated' => [
            'name' => 'Credit profile updated (borrower)',
            'category' => 'document',
            'icon' => 'ri-file-user-line',
            'channels' => $bellAndMail,
            'title' => 'Your credit profile has been updated',
            'body' => 'Your latest credit report has been added to your profile.',
            'url' => '/profile?tab=3',
        ],

        'host.credit_profile.update_requested' => [
            'name' => 'Credit profile update requested (host)',
            'category' => 'document',
            'icon' => 'ri-file-user-line',
            'channels' => $bellAndMail,
            'title' => 'Credit profile update requested',
            'body' => '{{borrower}} asked to update their credit profile.',
            // The borrower's request; the list when they have none on file.
            'url' => '/intake-review/credit-profile-requests/{{id}}',
        ],

        'partner.documents.uploaded' => [
            'name' => 'Borrower uploaded documents (partner)',
            'category' => 'document',
            'icon' => 'ri-folder-upload-line',
            'channels' => $bellAndMail,
            'title' => 'New documents uploaded',
            'body' => '{{borrower}} uploaded {{count}} document(s).',
            'url' => '/clients/{{borrower_id}}',
        ],

        // ── Accounts ────────────────────────────────────────────────────
        // The emails carry login credentials and are sent inline; the bell
        // never repeats a credential.

        'borrower.account.created' => [
            'name' => 'Borrower account created',
            'category' => 'system',
            'icon' => 'ri-user-smile-line',
            'channels' => $bellAndMail,
            'title' => 'Welcome, {{name}}!',
            'body' => 'Your account is set up. Explore your dashboard to get started.',
            'url' => '/dashboard',
        ],

        'borrower.account.created_with_payment' => [
            'name' => 'Borrower account created with enrollment payment',
            'category' => 'system',
            'icon' => 'ri-user-smile-line',
            'channels' => $bellAndMail,
            'title' => 'Complete your enrollment',
            'body' => 'Your account is set up. Pay your enrollment invoice of {{amount}} to get started.',
            'url' => '/invoices',
        ],

        'borrower.account.migrated' => [
            'name' => 'Borrower account migrated',
            'category' => 'system',
            'icon' => 'ri-user-shared-line',
            'channels' => $bellAndMail,
            'title' => 'Your account has migrated',
            'body' => 'Your account was migrated. Pay your invoice of {{amount}} to continue.',
            'url' => '/invoices',
        ],

        'borrower.account.trial_started' => [
            'name' => 'Borrower free trial started',
            'category' => 'system',
            'icon' => 'ri-gift-line',
            'channels' => $bellAndMail,
            'title' => 'Your free trial has started',
            'body' => 'Your free trial is active until {{date}}.',
            'url' => '/dashboard',
        ],

        'borrower.account.login_updated' => [
            'name' => 'Borrower sign-in email changed',
            'category' => 'system',
            'icon' => 'ri-lock-password-line',
            'channels' => $bellAndMail,
            'title' => 'Your sign-in email was changed',
            'body' => "Your sign-in email was updated. If this wasn't you, contact support right away.",
            'url' => '/profile',
        ],

        'partner.account.trial_started' => [
            'name' => 'Partner free trial started',
            'category' => 'system',
            'icon' => 'ri-gift-line',
            'channels' => $bellAndMail,
            'title' => 'Your free trial has started',
            'body' => 'Your free trial is active until {{date}}.',
            'url' => null,
        ],

        'partner.account.trial_extended' => [
            'name' => 'Partner free trial end date changed',
            'category' => 'system',
            'icon' => 'ri-gift-line',
            'channels' => $bellAndMail,
            'title' => 'Your free trial was updated',
            'body' => 'Your free trial now ends on {{date}}.',
            'url' => null,
        ],

        'partner.account.created_with_payment' => [
            'name' => 'Partner account created with enrollment payment',
            'category' => 'system',
            'icon' => 'ri-user-smile-line',
            'channels' => $bellAndMail,
            'title' => 'Complete your enrollment',
            'body' => 'Your account is set up. Pay your enrollment invoice of {{amount}} to get started.',
            'url' => '/invoices',
        ],

        'partner.account.migrated' => [
            'name' => 'Partner account migrated',
            'category' => 'system',
            'icon' => 'ri-user-shared-line',
            'channels' => $bellAndMail,
            'title' => 'Your account has moved',
            'body' => 'Your account was migrated. Pay your invoice of {{amount}} to continue.',
            'url' => '/invoices',
        ],

        'partner.account.login_updated' => [
            'name' => 'Partner sign-in details changed',
            'category' => 'system',
            'icon' => 'ri-lock-password-line',
            'channels' => $bellAndMail,
            'title' => 'Your sign-in details were changed',
            'body' => "Your sign-in details were updated. If this wasn't you, contact support right away.",
            'url' => '/profile',
        ],

        'user.account.created' => [
            'name' => 'Staff user account created',
            'category' => 'system',
            'icon' => 'ri-user-smile-line',
            'channels' => $bellAndMail,
            'title' => 'Welcome aboard',
            'body' => 'Your account is set up.',
            'url' => null,
        ],

        'user.account.login_updated' => [
            'name' => 'Staff user sign-in details changed',
            'category' => 'system',
            'icon' => 'ri-lock-password-line',
            'channels' => $bellAndMail,
            'title' => 'Your sign-in details were changed',
            'body' => "Your sign-in details were updated. If this wasn't you, contact your administrator.",
            'url' => '/profile',
        ],

        'user.seat_activated' => [
            'name' => 'Paid user seat activated',
            'category' => 'system',
            'icon' => 'ri-user-smile-line',
            'channels' => $bellAndMail,
            'title' => 'Your account is active',
            'body' => 'Your user seat is paid and your account is active.',
            'url' => null,
        ],

        'user.password_changed' => [
            'name' => 'Password changed',
            'category' => 'system',
            'icon' => 'ri-lock-password-line',
            'channels' => $bellAndMail,
            'title' => 'Your password was changed',
            'body' => "Your password was just changed. If this wasn't you, reset it right away.",
            'url' => '/profile',
        ],

        // ── Appointments ────────────────────────────────────────────────

        'appointment.changed' => [
            'name' => 'Appointment created / updated / cancelled (staff)',
            'category' => 'system',
            'icon' => 'ri-calendar-event-line',
            'channels' => $bellAndMail,
            'title' => 'Appointment {{action}}',
            'body' => '"{{title}}" on {{date}} was {{action}}.',
            'url' => '/appointments',
        ],

        'borrower.appointment.changed' => [
            'name' => 'Appointment created / updated / cancelled (borrower)',
            'category' => 'system',
            'icon' => 'ri-calendar-event-line',
            'channels' => $bellAndMail,
            'title' => 'Appointment {{action}}',
            'body' => '"{{title}}" on {{date}} was {{action}}.',
            'url' => '/appointment',
        ],

        // ── Email only: no login behind the address ─────────────────────

        'activity_log.email' => [
            'name' => 'Email composed from the activity log',
            'category' => 'system',
            'channels' => ['mail'],
        ],

        'demo_request.created' => [
            'name' => 'Demo request confirmation (prospect)',
            'category' => 'system',
            'channels' => ['mail'],
        ],

        'system.cron_failed' => [
            'name' => 'Scheduled job failure (ops)',
            'category' => 'system',
            'channels' => ['mail'],
        ],

        'system.health_alert' => [
            'name' => 'System health alert (ops)',
            'category' => 'system',
            'channels' => ['mail'],
        ],

        // Security email from App\Services\Global\Notification (password
        // reset, two-factor codes). Never in the bell: a code or reset link
        // must not be stored in the notifications table.
        'auth.password_reset' => [
            'name' => 'Password reset link',
            'category' => 'system',
            'channels' => ['mail'],
        ],

        'auth.two_factor_code' => [
            'name' => 'Two-factor sign-in code',
            'category' => 'system',
            'channels' => ['mail'],
        ],

        'auth.account_created' => [
            'name' => 'Account created (security notice)',
            'category' => 'system',
            'channels' => ['mail'],
        ],

        // Changing a sign-in email (App\Services\Global\Account\EmailChangeService).
        // The links confirm, cancel or undo the change, so like a reset link
        // they stay out of the bell.
        'auth.email_change_requested' => [
            'name' => 'Sign-in email change requested (old address)',
            'category' => 'system',
            'channels' => ['mail'],
        ],

        'auth.email_change_confirmation' => [
            'name' => 'Confirm new sign-in email (new address)',
            'category' => 'system',
            'channels' => ['mail'],
        ],

        'auth.email_changed' => [
            'name' => 'Sign-in email changed (old address)',
            'category' => 'system',
            'channels' => ['mail'],
        ],

        'auth.test' => [
            'name' => 'Mail configuration test',
            'category' => 'system',
            'channels' => ['mail'],
        ],

    ],

];

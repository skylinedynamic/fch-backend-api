<?php

return [
    // In-app notification inbox (bell / notifications page)
    'inbox' => [
        'fetched' => 'Notifications fetched successfully',
        'marked_read' => 'Notification marked as read',
        'marked_all_read' => 'All notifications marked as read',
        'deleted' => 'Notification deleted',
        'not_found' => 'Notification not found',
    ],

    // Common reusable strings
    'greeting_hello_name' => 'Hello :name,',
    'salutation_best_regards_team' => 'Best regards,<br>:appName Team',
    'invalid_channel' => 'Invalid notification channel',

    'two_factor' => [
        'errors' => [
            'no_settings' => 'User does not have two-factor settings',
            'invalid_method' => 'Invalid two-factor method',
        ],
    ],

    // Test notification (generic)
    'test' => [
        'sms_message' => 'This is Test mail',
        'email_subject' => 'This is Test mail',
        'email_lines' => [
            'line_1' => 'This is a test email to verify your notification system configuration.',
            'line_3' => 'If you received this email, your email service is working correctly.',
        ],
    ],

    // Password reset notification
    'password_reset' => [
        'email_subject' => 'Reset Password Notification',
        'email_lines' => [
            'line_1' => 'You are receiving this email because we received a password reset request for your account.',
            'line_2' => 'This password reset link will expire in 60 minutes.',
            'line_3' => 'If you did not request a password reset, no further action is required.',
        ],
        'action_text' => 'Reset Password',
        'sms_message' => 'Your :appName password reset link: :resetUrl. This link expires in 60 minutes.',
    ],

    // EmailChannel related strings
    'email' => [
        'errors' => [
            'not_configured' => 'Email channel not configured properly',
            'send_failed' => 'Failed to send email',
            'test_send_failed' => 'Failed to send test email',
        ],
        'logs' => [
            'send_attempt' => '...............Send notification via Email.............',
            'sent' => '...............Email notification sent.............',
            'after_sent' => '...............Email notification After Sent.............',
            'sent_success' => 'Email sent successfully............',
        ],
        'drivers' => [
            'smtp' => 'SMTP',
            'mailgun' => 'Mailgun',
            'ses' => 'Amazon SES',
            'postmark' => 'Postmark',
            'resend' => 'Resend',
            'log' => 'Log (Development)',
            'array' => 'Array (Testing)'
        ],
    ],

    // Notifier (config/notifications.php types, bell + mail + sms)
    'notifier' => [
        'logs' => [
            'unknown_type' => 'Notifier: unknown notification type, bell skipped.',
            'in_app_failed' => 'Notifier: in-app notification failed.',
            'mail_no_recipient' => 'Notifier: mail has no recipient, not sent.',
            'overrides_unreadable' => 'NotificationTypeRegistry: overrides unreadable, using the config catalogue.',
        ],
    ],

    // SmsChannel related strings
    'sms' => [
        'errors' => [
            'not_configured' => "SMS not sent: no enabled SMS configuration for this user's partner.",
            'no_phone' => 'SMS not sent: the user has no phone number.',
            'invalid_number' => 'SMS not sent: the phone number cannot be converted to international (E.164) format.',
            'send_failed' => 'Failed to send SMS',
            'provider_not_configured' => 'SMS provider [:provider] is not configured in notifications.sms.providers.',
        ],
        'logs' => [
            'sent_success' => 'SMS sent successfully',
            'no_enabled_configuration' => "SMS not sent: the recipient's SMS configuration is not enabled.",
        ],
    ],

    // EmailChannel::sendTestEmail specific strings
    'test_email' => [
        'subject' => 'Test Email - Configuration Verification',
        'lines' => [
            'line_1' => 'This is a test email to verify your email configuration.',
            'line_3' => 'If you received this email, your email service is working correctly.',
        ],
    ],
];

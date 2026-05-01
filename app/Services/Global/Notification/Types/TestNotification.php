<?php

namespace App\Services\Global\Notification\Types;

use App\Models\User\User;

class TestNotification implements NotificationTypeInterface
{
    protected $message;

    public function __construct(string $message)
    {
        $this->message = $message;
    }

    /**
     * Get the message content for SMS.
     */
    public function getSmsMessage(User $user): string
    {
        $appName = config('app.name');
        return trans('notification.test.sms_message', [
            'appName' => $appName,
            'message' => $this->message,
        ]);
    }

    /**
     * Get the email subject.
     */
    public function getEmailSubject(User $user): string
    {
        $appName = config('app.name');
        return trans('notification.test.email_subject', [
            'appName' => $appName,
        ]);
    }

    /**
     * Get the email content/body.
     */
    public function getEmailContent(User $user): array
    {
        $appName = config('app.name');
        
        return [
            'greeting' => trans('notification.greeting_hello_name', [
                'name' => $user->first_name,
            ]),
            'lines' => [
                // trans('notification.test.email_lines.line_1'),
                $this->message,
                // trans('notification.test.email_lines.line_3'),
            ],
            'salutation' => trans('notification.salutation_best_regards_team', [
                'appName' => $appName,
            ]),
        ];
    }

    /**
     * Get notification type identifier.
     */
    public function getType(): string
    {
        return 'test';
    }

    /**
     * Get any additional data needed for the notification.
     */
    public function getData(): array
    {
        return [
            'test_message' => $this->message,
            'sent_at' => now()
        ];
    }
}
<?php

namespace App\Services\Common\Notification\Types;

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
        return "Test message from {$appName}: {$this->message}";
    }

    /**
     * Get the email subject.
     */
    public function getEmailSubject(User $user): string
    {
        $appName = config('app.name');
        return "Test Email from {$appName}";
    }

    /**
     * Get the email content/body.
     */
    public function getEmailContent(User $user): array
    {
        $appName = config('app.name');
        
        return [
            'greeting' => "Hello {$user->first_name}!",
            'lines' => [
                'This is a test email to verify your notification system configuration.',
                $this->message,
                'If you received this email, your email service is working correctly.'
            ],
            'salutation' => "Best regards,<br>{$appName} Team"
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
<?php

namespace App\Services\Global\Notification\Types;

use App\Models\User\User;

interface NotificationTypeInterface
{
    /**
     * Get the message content for SMS.
     */
    public function getSmsMessage(User $user): string;

    /**
     * Get the email subject.
     */
    public function getEmailSubject(User $user): string;

    /**
     * Get the email content/body.
     */
    public function getEmailContent(User $user): array;

    /**
     * Get notification type identifier.
     */
    public function getType(): string;

    /**
     * Get any additional data needed for the notification.
     */
    public function getData(): array;
}
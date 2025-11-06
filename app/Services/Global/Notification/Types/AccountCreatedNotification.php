<?php

namespace App\Services\Global\Notification\Types;

use App\Models\User\User;

class AccountCreatedNotification implements NotificationTypeInterface
{
    protected array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function getType(): string
    {
        return 'account_created';
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getEmailSubject(User $user): string
    {
        return $this->data['subject'] ?? 'Welcome to ' . ($this->data['company_name'] ?? 'Our Company');
    }

    public function getEmailContent(User $user): array
    {
        \Log::info('Mail aaaa');
        return [
            'view' => 'emails.account_created',
            'data' => [
                'subject'        => $this->data['subject'] ?? 'Account Created',
                'name'           => $this->data['name'] ?? $user->name,
                'role'           => $this->data['role'] ?? 'User',
                'email'          => $this->data['email'] ?? $user->email,
                'phone_number'   => $this->data['phone_number'] ?? null,
                'password'       => $this->data['password'] ?? null,
                'login_url'      => $this->data['login_url'] ?? url('/'),
                'company_name'   => $this->data['company_name'] ?? 'Skyline Dynamics',
                'company_logo'   => $this->data['company_logo'] ?? 'https://skylinedynamic.dev/admin-portal/assets/images/logo.png',
                'company_email'  => $this->data['company_email'] ?? 'support@skylinedynamic.dev',
                'company_phone'  => $this->data['company_phone'] ?? '+880 1234-567890',
            ],
        ];
    }

    public function getSmsMessage(User $user): string
    {
        return 'Hi ' . ($this->data['name'] ?? $user->name) .
            ', your account has been created at ' .
            ($this->data['company_name'] ?? 'Our Company') .
            '. Login: ' . ($this->data['login_url'] ?? url('/'));
    }
}

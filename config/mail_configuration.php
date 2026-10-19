<?php

/*
|--------------------------------------------------------------------------
| Mail configuration
|--------------------------------------------------------------------------
|
| What Settings > Email Configuration offers. A partner keeps one
| configuration per provider, verifies each with a code sent through it, and
| picks a verified one as its default. The host partner also keeps the one
| platform-wide fallback, used for every partner without a verified default.
|
| To add a provider: list it under `providers`, then teach
| App\Mail\Traits\MailConfig::apply() to hand its fields to a mailer.
|   fields    every field the provider's form saves (stored encrypted), in
|             form order, with what the settings screen needs to draw it:
|             label, type (text, number, password, select), options for a
|             select, and whether it is required
|   secret    a field never returned to the browser; sending it back blank
|             or masked keeps the stored value
|
*/

return [

    'providers' => [
        'smtp' => [
            'label' => 'SMTP',
            'fields' => [
                'smtp_host' => ['label' => 'SMTP Host', 'type' => 'text', 'required' => true, 'rules' => ['string', 'max:255']],
                'smtp_port' => ['label' => 'SMTP Port', 'type' => 'number', 'required' => true, 'rules' => ['integer', 'between:1,65535']],
                'smtp_encryption' => [
                    'label' => 'Encryption',
                    'type' => 'select',
                    'required' => true,
                    'options' => ['tls' => 'TLS', 'ssl' => 'SSL', 'none' => 'None'],
                    'rules' => ['string', 'in:tls,ssl,none'],
                ],
                'smtp_username' => ['label' => 'SMTP Username', 'type' => 'text', 'required' => true, 'rules' => ['string', 'max:255']],
                'smtp_password' => ['label' => 'SMTP Password', 'type' => 'password', 'required' => true, 'secret' => true, 'rules' => ['string', 'max:1024']],
            ],
        ],
        'aws_ses' => [
            'label' => 'Amazon SES',
            'fields' => [
                'aws_access_key' => ['label' => 'AWS Access Key', 'type' => 'text', 'required' => true, 'rules' => ['string', 'max:255']],
                'aws_secret_key' => ['label' => 'AWS Secret Key', 'type' => 'password', 'required' => true, 'secret' => true, 'rules' => ['string', 'max:1024']],
                'aws_region' => ['label' => 'AWS Region', 'type' => 'text', 'required' => true, 'rules' => ['string', 'max:64']],
            ],
        ],
    ],

    'verification' => [
        'code_length' => 6,
        'ttl_minutes' => 10,
        'max_attempts' => 5,
        'resend_seconds' => 60,
    ],

];

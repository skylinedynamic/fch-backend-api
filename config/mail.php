<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        /*
         * Deliberately empty.
         *
         * Mail credentials are tenant data, not deployment configuration: every
         * partner sends through their own server, and a partner without one
         * sends through the host's. Both live in `email_configurations` and are
         * resolved per message by EmailConfigurationService::resolveFor(),
         * applied by App\Mail\Traits\MailConfig.
         *
         * These are NOT read from the environment on purpose. An env fallback
         * here is silent and wrong in the worst way: when a partner's row is
         * missing or incomplete, their mail goes out through whatever mailbox
         * the server happens to be configured with - under another tenant's
         * credentials - instead of failing visibly. Leaving these null means a
         * misconfigured tenant is reported, not quietly redirected.
         *
         * MAIL_MAILER, MAIL_FROM_* and the log/array transports below stay in
         * the environment: those select a transport for local work and tests,
         * they are not credentials.
         */
        'smtp' => [
            'transport' => 'smtp',
            'scheme' => null,
            'url' => null,

            /*
             * An inert placeholder, not a real destination, and NOT read from
             * the environment.
             *
             * Laravel builds the default mailer eagerly - `Mail::to(...)`
             * resolves the transport before the Mailable's own send() runs - and
             * Symfony's Dsn requires a string host, so a null here is a TypeError
             * on every send before MailConfig gets a chance to apply anything.
             *
             * No message is delivered through it: MailConfig calls
             * forgetMailers() and re-resolves with the tenant's real host before
             * handing the mailer to parent::send(). Anything that somehow sends
             * without going through that trait fails to connect here, which is
             * the intended outcome - better a refused connection than a message
             * delivered through an ambient mailbox.
             */
            'host' => 'localhost',
            'port' => 25,

            'username' => null,
            'password' => null,
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
            'retry_after' => 60,
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
            'retry_after' => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', 'Example'),
    ],

];

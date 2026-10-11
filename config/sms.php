<?php

/*
|--------------------------------------------------------------------------
| SMS
|--------------------------------------------------------------------------
|
| What Settings > SMS Configuration offers. Each partner (host or
| white-label) picks a country, then one of that country's providers, and
| saves that provider's credentials.
|
| Countries are keyed by their code in config/locations.php, which owns
| everything about the country itself - its name, dialling code
| (`phone_code`) and national number length (`phone_national_length`). This
| file only says which of them SMS is offered for.
|
| To add a provider: implement App\Services\FCH\Common\Notification\Sms\
| SmsGateway, list it under `providers`, then offer it in a country.
|   credentials  every field the provider's form saves (stored encrypted)
|   required     fields an enabled configuration cannot be without
|   secrets      fields never returned to the browser; sending one back blank
|                keeps the stored value
|
| tests/Unit/Notification/Sms/SmsConfigTest keeps this in step with
| config/locations.php.
|
*/

use App\Services\FCH\Common\Notification\Sms\TwilioSmsGateway;

return [

    'countries' => [
        'USA' => ['providers' => ['twilio']],
        'CAN' => ['providers' => ['twilio']],
    ],

    'providers' => [
        'twilio' => [
            'label' => 'Twilio',
            'gateway' => TwilioSmsGateway::class,
            'credentials' => ['account_sid', 'auth_token', 'messaging_service_sid'],
            'required' => ['account_sid', 'auth_token'],
            'secrets' => ['auth_token'],
        ],
    ],

];

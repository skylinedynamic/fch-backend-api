<?php

return [
    // Whether Twilio integration is enabled
    'enabled' => env('SMS_2FA_ENABLED', false),

    // Core credentials
    'account_sid' => env('TWILIO_SID'),
    'auth_token' => env('TWILIO_AUTH_TOKEN'),

    // From number or Messaging Service SID
    'from' => env('TWILIO_FROM_NUMBER'),
    'messaging_service_sid' => env('TWILIO_MESSAGING_SERVICE_SID'),

    // Verify service (optional)
    'verify_service_sid' => env('TWILIO_VERIFY_SERVICE_SID'),

    // Optional flag to fake sending in non-prod environments
    'fake' => env('TWILIO_FAKE', false),
];
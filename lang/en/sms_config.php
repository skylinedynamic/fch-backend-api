<?php

return [
    'retrieved' => 'SMS configuration retrieved successfully.',
    'saved' => 'SMS configuration saved successfully.',
    'test_sent' => 'Test SMS sent. It may take a moment to arrive.',
    'test_failed' => 'The test SMS could not be sent: :reason',
    'test_message' => 'This is a test message from :name. Your SMS configuration is working.',
    'not_configured' => 'Save complete SMS credentials before sending a test message.',
    'no_partner' => 'SMS configuration is only available to partner accounts.',
    'required_when_enabled' => 'This field is required to enable SMS.',

    // Validation
    'country_required' => 'Please select a country.',
    'country_in' => 'SMS is not available for the selected country.',
    'provider_required' => 'Please select an SMS provider.',
    'provider_in' => 'The selected provider is not available for this country.',
    'is_enabled_required' => 'Please choose whether SMS is enabled.',
    'from_number_regex' => 'The from number must be a number of the selected country in international format, e.g. +15551234567.',
    'account_sid_regex' => 'The Account SID must start with AC followed by 32 characters.',
    'auth_token_max' => 'The auth token must not exceed 255 characters.',
    'messaging_service_sid_regex' => 'The Messaging Service SID must start with MG followed by 32 characters.',
    'test_phone_required' => 'Please enter a phone number to send the test to.',
    'test_phone_regex' => 'The phone number must be in international format, e.g. +15551234567.',

    'logs' => [
        'test_failed' => 'SMS configuration: test message rejected by the provider.',
        'permission_seeded' => 'SMS configuration permission granted to :roles role(s).',
    ],
];

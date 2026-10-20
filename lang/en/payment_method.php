<?php

return [
    'retrieved' => 'Payment Methods retrieved successfully.',
    'saved' => 'Payment Methods saved successfully.',
    'stripe_key_mode_mismatch' => 'The :mode :field is a :key_mode key. Stripe will reject payments when the keys do not match the environment they are saved under.',
    'stripe_key_field_mismatch' => 'The :mode publishable key and secret key look swapped. The publishable key must start with pk_ and the secret key with sk_ or rk_.',
    'not_found' => 'Payment Methods not found.',
    'partner_id_required' => 'Partner ID is required.',
    'provider_updated' => 'Payment provider updated successfully.',
    'unauthorized' => 'Unauthorized access.',
    'provider_not_offered' => 'This payment method is not available. Choose one of the methods offered.',

    // ✅ Setup a payment method still needs before payers are offered it (for its active mode)
    'mode_sandbox' => 'Sandbox',
    'mode_production' => 'Production',
    'setup_publishable_key' => 'The :mode publishable key is missing or is not a Stripe publishable key (it starts with pk_live_ or pk_test_).',
    'setup_secret_key' => 'The :mode secret key is missing or is not a Stripe secret key (it starts with sk_live_, sk_test_, rk_live_ or rk_test_).',
    'setup_keys_mixed' => 'The :mode publishable and secret keys do not match: one is a test key and the other a live key.',
    'setup_bank_account' => 'No active bank account is set up for :mode mode.',
    'setup_credential' => 'The :mode :field is missing.',
    'credential' => [
        'app_id' => 'Application ID',
        'access_token' => 'Access Token',
        'login_id' => 'API Login ID',
        'transaction_key' => 'Transaction Key',
        'client_key' => 'Client Key',
        'client_secret' => 'Client Secret',
    ],

    // ✅ Stripe's own answer when the keys are saved
    'stripe_publishable_key_rejected' => 'Stripe did not accept the :mode publishable key. Copy it again from your Stripe dashboard (Developers > API keys).',
    'stripe_secret_key_rejected' => 'Stripe did not accept the :mode secret key. Copy it again from your Stripe dashboard (Developers > API keys).',
    'stripe_keys_different_accounts' => 'The :mode publishable and secret keys belong to different Stripe accounts. Use both keys from the same account.',
];

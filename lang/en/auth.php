<?php

return [
    'invalid_credentials' => 'Invalid credentials',
    // One answer for an unknown email and a wrong password, so the sign-in
    // form cannot be used to find out which emails have an account.
    'invalid_login' => 'Invalid email or password.',
    // Forgot password: the same for every email, so it does not say which have an account (BUG-068).
    'password_reset_link_maybe_sent' => 'If an account exists for this email, a password reset link has been sent.',
    'logged_in' => 'Logged in Successfully',
    'logged_out' => 'Logged out successfully',
    'logout_failed' => 'Logout failed. Please try again.',
    'unauthenticated' => 'Unauthenticated. Please login first.',
    'token_name' => 'auth_token',
    'unauthorized' => 'Unauthorized. Please login first',
    'access_denied' => 'Access denied. Admin privileges required',
    'access_denied_partner_privileges_required' => 'Access denied. Partner privileges required',
    'access_denied_borrower_privileges_required' => 'Access denied. Borrower privileges required',
    'access_denied_partner_portal' => 'Access denied. This is part of the partner portal.',
    'access_denied_host_only' => 'Access denied. This is available to the host only.',
    'user_not_found' => 'User not found',
    'subscription_expired' => 'Your subscription package has expired. Please contact :partner for renewal.',
    'free_trial_expired' => 'Your free trial has expired. Please contact administrator for assistance.',
    'free_plan_expired' => 'Your free plan has expired. Please contact :partner for assistance.',
    'payment_not_completed' => 'Your payment was not completed. Please contact :partner for assistance.',
    'partner_suspended' => 'Your account has been suspended. Please contact administrator for more information.',
    'partner_inactive' => 'Your account is currently inactive. Please contact administrator for activation.',
    'user_inactive' => 'Your account is currently inactive. Please contact administrator.',
    'no_partner' => 'Your account is not linked to a partner. Please contact administrator.',
    'borrower_inactive' => 'Your account is currently inactive. Please contact administrator.',
    'borrower_suspended' => 'Your account has been suspended. Please contact administrator.',
    'unauthorized_portal' => 'Unauthorized portal access.',

    // Validation messages
    'email_required' => 'Email is required.',
    'email_invalid' => 'Please enter a valid email address.',
    'password_required' => 'Password is required.',
    'password_min' => 'Password must be at least 6 characters.',
    'too_many_login_attempts' => 'Too many login attempts. Please try again after :time minute.|Too many login attempts. Please try again after :time minutes.',
    'validation_failed' => 'Validation failed.',

    // Registration validation messages
    'signup_id_required' => 'Signup ID is required.',
    'signup_id_exists' => 'Invalid signup ID provided.',
    'first_name_required' => 'First name is required.',
    'last_name_required' => 'Last name is required.',
    'email_email' => 'Please provide a valid email address.',
    'email_unique' => 'This email address is already registered.',
    'phone_number_required' => 'Phone number is required.',
    'state_required' => 'State is required.',
    'password_confirmed' => 'Password confirmation does not match.',
    'image_image' => 'The uploaded file must be an image.',
    'image_mimes' => 'Image must be a file of type: jpeg, png, jpg, gif.',
    'image_max' => 'Image size must not exceed 2MB.',

    // reCAPTCHA messages
    'recaptcha_required' => 'Please complete the reCAPTCHA verification.',
    'recaptcha_invalid' => 'reCAPTCHA verification failed. Please try again.',
    'recaptcha_secret_not_configured' => 'reCAPTCHA secret key is not configured.',
    'recaptcha_verification_failed' => 'reCAPTCHA verification failed. Please try again.',

    // Two-factor authentication messages
    'method_required' => 'Two-factor authentication method is required.',
    'method_in' => 'Invalid two-factor authentication method selected',
    '2fa_invalid_method' => 'Invalid 2FA method',
    'two_factor_setup' => '2FA setup initialized',
    '2fa_locked' => '2FA is locked. Try again in :seconds seconds.',
    '2fa_verified' => '2FA verified successfully',
    '2fa_verification_failed' => '2FA verification failed',
    '2fa_enabled' => 'Two-factor authentication enabled',
    '2fa_disabled' => 'Two-factor authentication disabled',
    '2fa_disable_error' => 'Error disabling 2FA',
    '2fa_totp_decrypt_error' => 'Error decrypting TOTP secret',
    '2fa_required' => 'Two-factor authentication is required for this action.',
    '2fa_verification_required' => 'Two-factor authentication verification is required.',
    '2fa_setup_required' => 'Two-factor authentication setup is required.',
    '2fa_totp_qr_scan' => 'Scan the QR code with your TOTP app',
    '2fa_recovery_codes' => 'Two-factor authentication recovery codes',
    'recovery_codes_regenerated' => 'Two-factor authentication recovery codes regenerated',
    '2fa_status' => 'Two-factor authentication status',

    // Login 2FA confirmation validation messages
    'login_2fa_user_id_required' => 'User ID is required.',
    'login_2fa_user_id_integer' => 'User ID must be an integer.',
    'login_2fa_user_id_exists' => 'The selected user does not exist.',
    'login_2fa_method_required' => 'Two-factor authentication method is required.',
    'login_2fa_method_in' => 'Invalid two-factor authentication method selected.',
    'login_2fa_code_required' => 'Verification code is required.',
    'login_2fa_code_string' => 'Verification code must be a string.',

    // General reusable (for 2FA emails)
    'greeting_hello_name' => 'Hello :name!',
    'salutation_thank_you_regards_team' => 'Thank you for using our application!<br>Regards,<br>:appName Team',

    // 2FA Notification content (SMS/Email)
    '2fa_sms_message' => 'Your :appName verification code is: :code. This code expires in :minutes minutes.',
    '2fa_email_subject' => 'Your :appName Verification Code',
    '2fa_email_lines' => [
        'line_1' => 'You are receiving this email because a two-factor authentication code was requested for your account.',
        'line_2' => 'Your verification code is displayed below:',
        'line_3' => 'This code will expire in :minutes minutes.',
        'line_4' => 'If you did not request this code, please ignore this email and consider changing your password.',
    ],

    // 2FA resend/setup messages
    '2fa_resend_not_applicable' => 'Resend not applicable for this method',
    '2fa_code_sent' => 'Verification code sent',
    '2fa_code_resent' => 'Verification code resent',
    '2fa_code_send_failed' => 'Failed to send verification code',
];

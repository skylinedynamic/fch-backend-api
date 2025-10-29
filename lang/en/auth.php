<?php

return [
    'invalid_credentials' => 'Invalid credentials',
    'invalid_email' => 'The email you entered does not exist',
    'invalid_password' => 'The password you entered is incorrect',
    'logged_in' => 'Logged in Successfully',
    'logged_out' => 'Logged out successfully',
    'logout_failed' => 'Logout failed. Please try again.',
    'unauthenticated' => 'Unauthenticated. Please login first.',
    'token_name' => 'auth_token',
    'unauthorized' => 'Unauthorized. Please login first',
    'access_denied' => 'Access denied. Admin privileges required',
    'access_denied_partner_privileges_required' => 'Access denied. Partner privileges required',
    'access_denied_borrower_privileges_required' => 'Access denied. Borrower privileges required',
    
    // Validation messages
    'email_required' => 'Email is required.',
    'email_invalid' => 'Please enter a valid email address.',
    'password_required' => 'Password is required.',
    'password_min' => 'Password must be at least 6 characters.',
    'too_many_login_attempts' => 'Too many login attempts. Please try again after :time minutes.',
    'validation_failed' => 'Validation failed.',
    
    // Registration validation messages
    'signup_id_required' => 'Signup ID is required.',
    'signup_id_exists' => 'Invalid signup ID provided.',
    'first_name_required' => 'First name is required.',
    'last_name_required' => 'Last name is required.',
    'email_required' => 'Email address is required.',
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
];

<?php

return [
    // ✅ Validation
    'taken' => 'This email is already used by another account.',

    // ✅ Confirmation (sent to the new address)
    'confirm_subject' => 'Confirm your new sign-in email',
    'greeting' => 'Hello :name,',
    'confirm_line1' => 'Your sign-in email is being changed to this address (:email).',
    'confirm_line2' => 'Confirm it with your current password to finish the change. Until you do, keep signing in with your current email.',
    'confirm_button' => 'Confirm Email Change',
    'confirm_expiry' => 'This link expires in :hours hours. Your password stays the same.',

    // ✅ Notice (sent to the old address when a change is requested)
    'requested_subject' => 'Your sign-in email is being changed',
    'requested_line1' => 'A change was requested to the email you sign in with, from :old to :new.',
    'requested_line2' => 'It only takes effect once confirmed from :new. Until then you can keep signing in with this address.',
    'requested_warning' => 'If you did not ask for this, or :new is not your address, cancel the change with the button above.',
    'cancel_button' => 'Cancel This Change',

    // ✅ Notice (sent to the old address once a change is confirmed)
    'changed_subject' => 'Your sign-in email was changed',
    'changed_line1' => 'The email you sign in with was changed from :old to :new.',
    'changed_warning' => 'If this was not you, undo the change with the button above. You will then set a new password from this address.',
    'revert_button' => 'Undo This Change',
    'revert_expiry' => 'This link can be used for :days days.',

    // ✅ Landing pages
    'page_title' => 'Email change',
    'confirm_title' => 'Confirm your new sign-in email',
    'confirm_body' => 'Enter your current password to change your sign-in email to :email.',
    'password_label' => 'Current password',
    'password_placeholder' => 'Enter your current password',
    'wrong_password' => 'That password is not correct.',
    'too_many_attempts_title' => 'Too many attempts',
    'too_many_attempts_body' => 'The password was entered wrongly too many times. Try again in :minutes minutes.',
    'cancel_title' => 'Cancel the email change?',
    'cancel_body' => 'Your sign-in email will stay :email and the confirmation link sent to :new will stop working.',
    'cancelled_title' => 'The change has been cancelled',
    'cancelled_body' => 'Your sign-in email is still :email.',
    'revert_title' => 'Undo the email change?',
    'revert_body' => 'Your sign-in email will go back to :old. Everyone signed in to this account will be signed out, and you will need to set a new password with "Forgot password".',
    'reverted_title' => 'Your email has been changed back',
    'reverted_body' => 'Your sign-in email is :email again. For safety your password was reset: use "Forgot password" on the sign-in page to set a new one.',
    'changed_title' => 'Your email has been changed',
    'changed_body' => 'You can now sign in with :email. You have been signed out everywhere else, so you will need to sign in again.',
    'invalid_title' => 'This link is no longer valid',
    'invalid_body' => 'It may have expired, been used already, been replaced by a newer request, or been cancelled. Your sign-in email has not changed.',
    'taken_title' => 'This email is no longer available',
    'taken_body' => 'Another account is now using this email. Your sign-in email has not changed.',
    'sign_in' => 'Go to sign in',

    // ✅ API messages
    'requested' => 'A confirmation link has been sent to :email. The email will change once it is confirmed.',
    'resent' => 'A new confirmation link has been sent to :email.',
    'cancelled' => 'The pending email change has been cancelled.',
    'nothing_pending' => 'There is no pending email change.',
];

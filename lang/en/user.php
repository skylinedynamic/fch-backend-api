<?php

return [

    /*
    |--------------------------------------------------------------------------
    | User Module Language Lines
    |--------------------------------------------------------------------------
    |
    | Messages for User API requests, validation, and controller responses.
    |
    */

    // Validation
    'validation_failed'         => 'Validation failed.',
    'name_required'             => 'The name field is required.',
    'email_required'            => 'The email field is required.',
    'email_invalid'             => 'The email must be a valid email address.',
    'email_taken'               => 'The email has already been taken.',
    'phone_number_taken'        => 'The phone number has already been taken.',
    'password_required'         => 'The password field is required.',
    'password_min'              => 'The password must be at least 6 characters.',
    'new_password_required'     => 'New password is required when changing password.',
    'new_password_min'          => 'New password must be at least 8 characters.',
    'confirm_password_required' => 'Password confirmation is required.',
    'confirm_password_same'     => 'Password confirmation does not match new password.',
    'role_required'             => 'The role field is required.',
    'role_not_found'            => 'Selected role not found.',
    'permission_ids_required'   => 'At least one permission must be selected.',

    // Controller responses
    'user_created'           => 'User created successfully.',
    'user_create_failed'     => 'Failed to create user.',
    'user_updated'           => 'User updated successfully.',
    'user_update_failed'     => 'Failed to update user.',
    'user_delete_failed'     => 'Failed to delete user.',
    'user_deleted'           => 'User deleted successfully.',
    'user_list_retrieved'    => 'User list retrieved successfully.',
    'failed_to_retrieve_users' => 'Failed to retrieve users.',
    'user_retrieved'         => 'User details retrieved successfully.',
    'not_authorized_to_update_developer' => 'You are not authorized to update developer accounts.',
    'user_not_found'         => 'The specified user does not exist.',
    'employee_required'      => 'Please select the employee this user belongs to.',
    'employee_not_found'     => 'The selected employee does not exist.',
    'employee_already_linked' => 'The selected employee already has a user account.',
];

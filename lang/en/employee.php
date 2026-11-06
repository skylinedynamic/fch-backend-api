<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Employee Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used for employee CRUD operations,
    | form validation, notifications, and status messages.
    |
    */

    // ✅ Validation Messages
    'first_name_required' => 'First name is required.',
    'first_name_string'   => 'First name must be a valid string.',
    'last_name_string'    => 'Last name must be a valid string.',
    'designation_required'=> 'Designation is required.',
    'designation_string'  => 'Designation must be a valid string.',
    'department_required' => 'Department is required.',
    'department_string'   => 'Department must be a valid string.',
    'phone_number_required' => 'Phone number is required.',
    'email_required'      => 'Email is required.',
    'email_valid'         => 'Please provide a valid email address.',
    'email_unique'        => 'Email is already in use.',
    'phone_unique'        => 'Phone number is already in use.',
    'address_required'    => 'Address is required.',
    'city_required'       => 'City is required.',
    'state_required'      => 'State is required.',
    'country_required'    => 'Country is required.',
    'date_of_birth_before'=> 'Date of birth must be a valid date before today.',
    'gender_invalid'      => 'Selected gender is invalid. Allowed values: male, female, other.',
    'role_required_if_create_account' => 'Role is required.',
    'role_exists'         => 'Selected role does not exist.',
    'image_must_be_image' => 'Uploaded file must be an image.',
    'image_mime_invalid'  => 'Image must be a file of type: jpeg, jpg, png, webp.',
    'validation_failed'   => 'Some fields failed validation. Please review the input data.',

    // ✅ CRUD Messages
    'employee_created' => 'Employee has been created successfully.',
    'employee_updated' => 'Employee details have been updated successfully.',
    'employee_deleted' => 'Employee has been deleted successfully.',
    'employee_listed'  => 'Employees retrieved successfully.',
    'employee_details' => 'Employee details retrieved successfully.',
    'employee_not_found' => 'The requested employee could not be found.',
    'department_status' => 'Fecth department status successfully.',

    // ✅ Departments
    'department_name_required' => 'Department name is required.',
    'department_name_max' => 'Department name may not be greater than 255 characters.',
    'department_exists' => 'Department already exists.',
    'department_created' => 'Department created successfully',
    'department_deleted' => 'Department deleted successfully',
    'department_not_found' => 'Department not found.',
    'department_in_use' => 'Department ":name" is assigned to :count employee and cannot be deleted. Move the employee to another department first.|Department ":name" is assigned to :count employees and cannot be deleted. Move them to another department first.',

    // ✅ Account Creation
    'account_created'  => 'User account has been created successfully for this employee.',
    'account_already_exists' => 'A user account is already linked with this employee.',
    'account_deleted'  => 'Employee account and linked user deleted successfully.',

    // ✅ Status Messages
    'unauthorized_action' => 'You are not authorized to perform this action.',
    'forbidden_access'    => 'Access to this employee record is forbidden.',
    'unknown_error'       => 'An unexpected error occurred. Please try again later.',
    'mail_failed'         => 'Failed to send account creation email notification.',
    'mail_sent'           => 'Account details email has been sent successfully.',

    // ✅ Pagination & Filters
    'employee_paginated'  => 'Employee list retrieved successfully.',
    'employee_filtered'   => 'Filtered employee list retrieved successfully.',
    'employee_list_failed' => 'Failed to retrieve employee list.',
];

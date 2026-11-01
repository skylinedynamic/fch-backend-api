<?php

return [
    // Other existing messages
    'role_not_found' => 'The specified role does not exist.',
    'name_required' => 'The role name is required.',
    'name_string' => 'The role name must be a string.',
    'name_max' => 'The role name may not be greater than 50 characters.',
    'name_unique' => 'The role name already exists.',
    'description_required' => 'The role description is required.',
    'description_string' => 'The role description must be a string.',
    'description_max' => 'The role description may not be greater than 255 characters.',
    'permission_ids_required' => 'Permissions are required.',
    'permission_ids_array' => 'Permissions must be an array.',

    // success messages
    'role_created' => 'Role created successfully.',
    'role_updated' => 'Role updated successfully.',
    'role_deleted' => 'Role deleted successfully.',
    'role_static_cannot_delete' => 'Static role cannot be deleted.',
    'role_static_cannot_change' => 'This is a system role: its name and permissions cannot be changed.',
    'role_has_users_cannot_delete' => 'Role has users and cannot be deleted.',
    'ids_required' => 'Role IDs are required.',
    'ids_array' => 'Role IDs must be an array.',
    'ids_integer' => 'Each role ID must be an integer.',
    'ids_exist' => 'One or more role IDs do not exist.',
    'role_details' => 'Role details fetched successfully.',
    'role_list_retrieved' => 'Role list retrieved successfully.',
    'role_create_error' => 'Role creation failed.',
    'partner_id_required' => 'Partner ID is required to create a role.',
    'not_authorized_to_update_developer_role' => 'You are not authorized to update developer roles.',
    'not_authorized_to_delete_developer_role' => 'You are not authorized to delete developer roles.',
];

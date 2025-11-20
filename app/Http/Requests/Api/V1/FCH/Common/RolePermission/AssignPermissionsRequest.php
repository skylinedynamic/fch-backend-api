<?php

namespace App\Http\Requests\Api\V1\FCH\Common\RolePermission;

use App\Http\Requests\Api\V1\BaseApiRequest;

class AssignPermissionsRequest extends BaseApiRequest
{
    public function authorize()
    {
        return true; // Adjust based on your authorization needs
    }

    public function rules()
    {
        return [
            'permission_ids' => 'required|array',
        ];
    }

    public function messages()
    {
        return [
            'permission_ids.required' => __('role_permission.permission_ids_required'),
            'permission_ids.array' => __('role_permission.permission_ids_array'),
        ];
    }

}
